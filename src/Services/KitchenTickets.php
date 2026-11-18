<?php

namespace Rishadblack\SyllooPosCore\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Rishadblack\SyllooPosCore\Models\KitchenTicket;
use Rishadblack\SyllooPosCore\Models\Order;
use Rishadblack\SyllooPosCore\Models\OrderItem;
use Rishadblack\SyllooPosCore\Models\Product;
use Rishadblack\SyllooPosCore\Support\KitchenStations;
use Throwable;

/**
 * Kitchen tickets of a restaurant's till (erp: docs/restaurant.md, phase 5). Sending writes one ticket per station for
 * what each line has not sent yet and counts those quantities as sent in the same transaction, so a second press can
 * not send twice. Printing comes after, in a request of its own, through the till's printing layer
 * (ReceiptPrintService), so the cashier never waits. A ticket that can not be printed stays failed and is tried again;
 * a ticket never stops a sale or the send.
 *
 * Not tried on a real printer yet: a printer that is switched off may still take the job without an error (plan §7).
 */
class KitchenTickets
{
    /** Seconds before a failed ticket is tried again on its own. */
    public const RETRY_SECONDS = 30;

    /** A ticket printed this many minutes after it was made says LATE. */
    public const LATE_MINUTES = 2;

    public function __construct(protected ReceiptPrintService $receipts) {}

    /**
     * What each line of the bill has not sent to the kitchen yet, by line id.
     *
     * @return array<string, float>
     */
    public function unsentQuantities(Order $bill): array
    {
        return $bill->orderItem()->get(['id', 'quantity', 'kitchen_sent_quantity'])
            ->mapWithKeys(fn (OrderItem $item) => [(string) $item->id => round((float) $item->quantity - (float) $item->kitchen_sent_quantity, 6)])
            ->filter(fn (float $quantity) => $quantity > 0.000001)
            ->all();
    }

    /**
     * Send what is new on the bill: one ticket per station, the lines counted as sent. Returns the tickets made.
     *
     * @return Collection<int, KitchenTicket>
     */
    public function send(Order $bill): Collection
    {
        return DB::transaction(function () use ($bill) {
            $items = $bill->orderItem()->with(['product', 'unit'])->get();
            $byId = $items->keyBy(fn (OrderItem $item) => (string) $item->id);
            $stations = KitchenStations::all();
            $groups = [];

            foreach ($this->inKitchenOrder($items) as $item) {
                $unsent = round((float) $item->quantity - (float) $item->kitchen_sent_quantity, 6);

                if ($unsent <= 0.000001) {
                    continue;
                }

                // An add-on is cooked where its dish is.
                $dish = filled($item->parent_item_id ?? null) ? ($byId[(string) $item->parent_item_id] ?? $item) : $item;
                $station = KitchenStations::stationFor($dish->product, $stations);
                $groups[$station['name']]['printer'] = $station['printer'];
                $groups[$station['name']]['lines'][] = $this->line($item, $unsent);

                $item->kitchen_sent_quantity = round((float) $item->quantity, 6);
                $item->save();
            }

            return collect($groups)->map(fn (array $group, string $station) => $this->ticket($bill, $station, $group['printer'], KitchenTicket::KIND_NEW, $group['lines']))->values();
        });
    }

    /**
     * A CANCEL ticket for quantities taken off lines the kitchen already had, by station.
     *
     * @param  list<array{product_id: string, name: string, quantity: float, unit?: ?string, note?: ?string, parent_item_id?: ?string, item_id?: ?string}>  $lines
     * @return Collection<int, KitchenTicket>
     */
    public function cancel(Order $bill, array $lines): Collection
    {
        $stations = KitchenStations::all();
        $products = Product::withTrashed()->whereIn('products.id', array_column($lines, 'product_id'))->get()->keyBy(fn (Product $product) => (string) $product->id);
        $groups = [];

        foreach ($lines as $line) {
            $station = KitchenStations::stationFor($products[(string) $line['product_id']] ?? null, $stations);
            $groups[$station['name']]['printer'] = $station['printer'];
            $groups[$station['name']]['lines'][] = [
                'item_id' => $line['item_id'] ?? null,
                'parent_item_id' => $line['parent_item_id'] ?? null,
                'name' => (string) $line['name'],
                'quantity' => round((float) $line['quantity'], 6),
                'unit' => $line['unit'] ?? null,
                'note' => $line['note'] ?? null,
            ];
        }

        return collect($groups)->map(fn (array $group, string $station) => $this->ticket($bill, $station, $group['printer'], KitchenTicket::KIND_CANCEL, $group['lines']))->values();
    }

    /**
     * Print a ticket again as a new REPRINT ticket, to the same station.
     */
    public function reprint(KitchenTicket $ticket): KitchenTicket
    {
        $bill = $ticket->order ?? new Order(['id' => $ticket->order_id]);

        return $this->ticket($bill, $ticket->station, $ticket->printer, KitchenTicket::KIND_REPRINT, (array) $ticket->lines);
    }

    /**
     * Print the tickets that wait: new ones at once, failed ones once their retry time came (or at once with $force).
     *
     * @return array{printed: int, failed: list<string>}
     */
    public function printWaiting(bool $force = false): array
    {
        $tickets = KitchenTicket::query()->unprinted()
            ->where(fn ($query) => $force
                ? $query
                : $query->where('kitchen_tickets.status', KitchenTicket::STATUS_WAITING)
                    ->orWhereNull('kitchen_tickets.tried_at')
                    ->orWhere('kitchen_tickets.tried_at', '<=', now()->subSeconds(self::RETRY_SECONDS)))
            ->with('order')
            ->oldest()
            ->get();

        $printed = 0;
        $failed = [];

        foreach ($tickets as $ticket) {
            if ($this->print($ticket)) {
                $printed++;
            } else {
                $failed[] = $ticket->station;
            }
        }

        return ['printed' => $printed, 'failed' => array_values(array_unique($failed))];
    }

    /**
     * Hand one ticket to its station's printer. A failure leaves it failed with the error; from the second failure,
     * with the fallback on, it prints on the receipt printer, headed with the station whose printer is off. Never throws.
     */
    public function print(KitchenTicket $ticket): bool
    {
        $printer = KitchenStations::printerOf($ticket->station) ?? $ticket->printer;
        $ticket->attempts++;
        $ticket->tried_at = now();

        try {
            $this->receipts->sendHtml($this->render($ticket), $printer);
            $ticket->forceFill(['status' => KitchenTicket::STATUS_SENT, 'sent_at' => now(), 'last_error' => null, 'on_receipt_printer' => false])->save();

            return true;
        } catch (Throwable $exception) {
            $ticket->last_error = mb_substr($exception->getMessage() ?: 'The printer did not take the ticket.', 0, 1000);
            Log::warning('Kitchen ticket not printed: '.$ticket->last_error, ['ticket' => $ticket->id, 'station' => $ticket->station]);
        }

        if ($ticket->attempts >= 2 && filled($printer) && KitchenStations::fallbackToReceipt()) {
            try {
                $this->receipts->sendHtml($this->render($ticket, 'KITCHEN: '.$ticket->station.' printer is off'), null);
                $ticket->forceFill(['status' => KitchenTicket::STATUS_SENT, 'sent_at' => now(), 'on_receipt_printer' => true])->save();

                return true;
            } catch (Throwable $exception) {
                Log::warning('Kitchen ticket not printed on the receipt printer either: '.$exception->getMessage(), ['ticket' => $ticket->id]);
            }
        }

        $ticket->status = KitchenTicket::STATUS_FAILED;
        $ticket->save();

        return false;
    }

    /**
     * The ticket as it prints: big table, mode, number and time, lines with add-ons indented and notes, never a price.
     */
    public function render(KitchenTicket $ticket, ?string $notice = null): string
    {
        $order = $ticket->order;
        $lines = collect((array) $ticket->lines);
        $children = $lines->filter(fn (array $line) => filled($line['parent_item_id'] ?? null))->groupBy('parent_item_id');
        $parentIds = $lines->pluck('item_id')->filter()->map(fn ($id) => (string) $id)->all();

        $html = view('sylloo-pos-core::receipts.kitchen-ticket', [
            'ticket' => $ticket,
            'order' => $order,
            // A dish first, its add-ons under it; an add-on whose dish is not on this ticket stands alone.
            'lines' => $lines->reject(fn (array $line) => filled($line['parent_item_id'] ?? null) && in_array((string) $line['parent_item_id'], $parentIds, true))
                ->map(fn (array $line) => [...$line, 'addons' => filled($line['item_id'] ?? null) ? ($children[(string) $line['item_id']] ?? collect())->values()->all() : []])
                ->values()->all(),
            'notice' => $notice,
            'late' => $ticket->created_at && $ticket->created_at->diffInMinutes(now()) >= self::LATE_MINUTES,
            'cashier' => (string) (Auth::user()?->name ?? ''),
            'paperWidth' => (int) ($this->receipts->settings()['paper_width'] ?? 80),
        ])->render();

        return $this->receipts->finishHtml($html);
    }

    /**
     * The bills with a ticket that did not print (the red mark on their table).
     *
     * @return list<string>
     */
    public function failedOrderIds(): array
    {
        return KitchenTicket::query()->failed()->distinct()->pluck('order_id')->map(fn ($id) => (string) $id)->all();
    }

    /**
     * Stations whose printer is not installed on this PC, said when the terminal opens.
     *
     * @return list<string>
     */
    public function printerProblems(): array
    {
        return collect(KitchenStations::all())
            ->filter(fn (array $station) => filled($station['printer']) && ! $this->receipts->printerInstalled($station['printer']))
            ->map(fn (array $station) => "Kitchen printer {$station['printer']} ({$station['name']}) is not connected")
            ->values()
            ->all();
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     */
    protected function ticket(Order $bill, string $station, ?string $printer, string $kind, array $lines): KitchenTicket
    {
        return KitchenTicket::query()->create([
            'order_id' => $bill->id,
            'number' => (int) KitchenTicket::query()->max('number') + 1,
            'station' => $station,
            'printer' => $printer,
            'kind' => $kind,
            'lines' => $lines,
            'status' => KitchenTicket::STATUS_WAITING,
            'created_by' => Auth::id(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function line(OrderItem $item, float $quantity): array
    {
        return [
            'item_id' => (string) $item->id,
            'parent_item_id' => filled($item->parent_item_id ?? null) ? (string) $item->parent_item_id : null,
            'name' => (string) $item->name,
            'quantity' => $quantity,
            'unit' => $item->otherUnitName() ?: null,
            'note' => filled($item->kitchen_note) ? (string) $item->kitchen_note : null,
        ];
    }

    /**
     * Dishes in the order they were added, each followed by its add-ons.
     *
     * @param  Collection<int, OrderItem>  $items
     * @return Collection<int, OrderItem>
     */
    protected function inKitchenOrder(Collection $items): Collection
    {
        return $items->sortBy(fn (OrderItem $item) => (string) ($item->parent_item_id ?? $item->id).'|'.(filled($item->parent_item_id ?? null) ? '1' : '0').(string) $item->id)->values();
    }
}
