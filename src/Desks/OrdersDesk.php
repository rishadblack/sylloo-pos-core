<?php

namespace Rishadblack\SyllooPosCore\Desks;

use Illuminate\Database\Eloquent\Builder;
use Rishadblack\SyllooPosCore\Models\Order;
use Rishadblack\SyllooPosCore\Services\DueCollections;
use Rishadblack\SyllooPosCore\Services\SaleReturns;

/**
 * This till's orders of one day as plain rows (android-till-ux.md §3.9, the Orders list): newest first, with the
 * filters `all`, `not_sent`, `baki` and `held` (the held orders, a chip of Orders by the owner's decision of
 * 2026-11-18), a search by receipt number, customer name or mobile, and each order's sync state as a word. It names no
 * screen and no platform.
 *
 * A row: `id`, `code`, `time` (`H:i`), `customer`, `total`, `paid`, `due`, `kind` (`sale`, `return`, `collection`),
 * `held`, `state` (`synced`, `uploading`, `failed`, `pending`, `held`), `state_text`.
 */
class OrdersDesk
{
    /** Orders shown at most for one day. */
    public const MAX = 200;

    public const FILTERS = ['all', 'not_sent', 'baki', 'held'];

    /**
     * @return list<array{id: string, code: string, time: string, customer: string, total: float, paid: float, due: float, kind: string, held: bool, state: string, state_text: string}>
     */
    public function today(string $filter = 'all', string $search = '', ?string $date = null): array
    {
        $day = $date ?? now()->toDateString();
        $query = Order::query()->with('contact:id,name,mobile')->whereDate('orders.order_date', $day);

        match ($filter) {
            'held' => $query->heldOnly(),
            'not_sent' => $query->notDraft()->whereNull('orders.last_sync_at')->where('orders.from_office', false),
            'baki' => $query->notDraft()->where('orders.type', 1)->where('orders.due_amount', '>', 0),
            default => $query->notDraft(),
        };

        $term = trim(numberBnToEn($search));

        if ($term !== '') {
            $query->where(fn (Builder $query) => $query->where('orders.code', 'like', '%'.$term.'%')
                ->orWhereHas('contact', fn (Builder $contact) => $contact->where('contacts.name', 'like', '%'.$term.'%')
                    ->orWhere('contacts.mobile', 'like', '%'.preg_replace('/\D/', '', $term).'%')));
        }

        return $query->orderByDesc('orders.order_date')->orderByDesc('orders.created_at')->limit(self::MAX)->get()
            ->map(fn (Order $order) => $this->row($order))->all();
    }

    /**
     * How many orders each filter holds today (the chips' counts).
     *
     * @return array<string, int>
     */
    public function counts(?string $date = null): array
    {
        $day = $date ?? now()->toDateString();
        $base = fn () => Order::query()->whereDate('orders.order_date', $day);

        return [
            'all' => $base()->notDraft()->count(),
            'not_sent' => $base()->notDraft()->whereNull('orders.last_sync_at')->where('orders.from_office', false)->count(),
            'baki' => $base()->notDraft()->where('orders.type', 1)->where('orders.due_amount', '>', 0)->count(),
            'held' => Order::query()->heldOnly()->count(),
        ];
    }

    /**
     * @return array{id: string, code: string, time: string, customer: string, total: float, paid: float, due: float, kind: string, held: bool, state: string, state_text: string}
     */
    protected function row(Order $order): array
    {
        $held = $order->isDraft();
        $state = $held ? 'held' : ($order->getAttribute('from_office') ? 'synced' : $order->syncState());

        return [
            'id' => (string) $order->getKey(),
            'code' => (string) $order->getAttribute('code'),
            'time' => $order->getAttribute('order_date') ? $order->getAttribute('order_date')->format('H:i') : '',
            'customer' => (string) ($order->contact?->getAttribute('name') ?? ''),
            'total' => round((float) $order->getAttribute('net_amount'), 2),
            'paid' => round((float) $order->getAttribute('paid_amount'), 2),
            'due' => round((float) $order->getAttribute('due_amount'), 2),
            'kind' => match ((int) $order->getAttribute('type')) {
                SaleReturns::TYPE => 'return',
                DueCollections::TYPE => 'collection',
                default => 'sale',
            },
            'held' => $held,
            'state' => $state,
            'state_text' => match (true) {
                $state === 'synced' && $order->needsChecking() => __('till.order_needs_checking'),
                default => __('till.order_'.$state),
            },
        ];
    }
}
