<?php

namespace Rishadblack\SyllooPosCore\Jobs;

use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Log;
use Rishadblack\SyllooPosCore\Facades\Settings;
use Rishadblack\SyllooPosCore\Models\CashMovement;
use Rishadblack\SyllooPosCore\Models\CashRegister;
use Rishadblack\SyllooPosCore\Models\Order;
use Rishadblack\SyllooPosCore\Models\Shift;
use Rishadblack\SyllooPosCore\Models\User;
use Rishadblack\SyllooPosCore\Services\ErpApiService;
use Throwable;

/**
 * Brings the till's shifts to the ERP's cash register (erp: docs/account-daily-book.md §3b.2, owner decision: a shift
 * is a register session). Shifts open and close on the till offline too; when online, in this order:
 *
 * 1. a new shift opens the terminal's register in the ERP with its float (`register/open`);
 * 2. cash put in or taken out goes up as `CashMovement`, against the shift's register session;
 * 3. a closed shift closes the register with the counted cash (`register/close`), once its sales and cash movements
 *    are in the ERP, so the ERP's expected cash has them.
 *
 * Each run closes the earlier shifts before it opens new ones, and a new shift never adopts a session another shift
 * of this till still holds (`register_already_open`): it waits until that shift has closed it.
 *
 * The ERP's refusals are kept on the shift or movement (`register_status`, `sync_status`) and shown on the Shift
 * panel; a connection problem leaves everything for the next run (after a push, with the heartbeat).
 */
class RegisterSync implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public const TO_OPEN = 'to_open';

    public const OPEN = 'open';

    public const CLOSED = 'closed';

    /** The ERP has no register for this terminal: the shift stays on the till, cash movements go up without one. */
    public const NO_REGISTER = 'no_register';

    /** The ERP refused to open or close the register (no right, a business rule); said on the Shift panel. */
    public const REFUSED = 'refused';

    public int $uniqueFor = 60;

    public function __construct()
    {
        $this->onQueue(PushErpJob::QUEUE);
    }

    /**
     * Whether anything waits for the ERP.
     */
    public static function hasWork(): bool
    {
        return Shift::query()->where('register_status', self::TO_OPEN)->exists()
            || Shift::query()->where('register_status', self::OPEN)->whereNotNull('closed_at')->exists()
            || CashMovement::query()->whereNull('synced_at')->whereNull('sync_status')->exists();
    }

    public function handle(): void
    {
        $cashier = User::latestWithValidToken();

        if (! $cashier) {
            return;
        }

        $erp = new ErpApiService($cashier);

        // Closed shifts go first: a new shift must never find (and adopt) the session a previous shift still has to close.
        try {
            $this->pushMovements($erp);
            Shift::query()->where('register_status', self::OPEN)->whereNotNull('closed_at')->oldest('closed_at')->get()->each(fn (Shift $shift) => $this->close($erp, $shift));
            Shift::query()->where('register_status', self::TO_OPEN)->oldest('opened_at')->get()->each(fn (Shift $shift) => $this->open($erp, $shift));
            $this->pushMovements($erp);
        } catch (Throwable $exception) {
            Log::info('Register sync: the ERP could not be reached: '.$exception->getMessage());
        }
    }

    protected function open(ErpApiService $erp, Shift $shift): void
    {
        $registerId = $shift->cash_register_id ?? CashRegister::forTerminal(Settings::get('desktop_pos_id'))?->id;
        $response = $erp->registerOpen(array_filter([
            'cash_register_id' => $registerId,
            'opening_float' => round((float) $shift->opening_float, 2),
            'opening_note' => 'Till shift '.$shift->number.($shift->user?->name ? ' · '.$shift->user->name : ''),
        ], fn ($value) => $value !== null));

        match (true) {
            $response->successful() => $this->opened($shift, $response, null),
            // The session of an earlier shift that has not closed it yet (its sales wait to upload): tried again once it is closed.
            $this->code($response) === 'register_already_open' && $this->sessionHeldByAnotherShift($shift, (string) $response->json('data.session.id')) => $shift->forceFill(['register_message' => 'Waiting for the previous shift to close the register in the office.'])->save(),
            // Opened before (a reply that was lost, or on the web): this shift goes on in that session.
            $this->code($response) === 'register_already_open' && filled($response->json('data.session.id')) => $this->opened($shift, $response, 'The register was already open in the office; this shift carries on in that session.'),
            $this->code($response) === 'register_not_found' => $shift->forceFill(['register_status' => self::NO_REGISTER, 'register_message' => "The office has no cash register for this till. The shift is kept here; cash in and out go to the shop's cash account."])->save(),
            in_array($this->code($response), ['not_allowed', 'refused', 'invalid'], true) => $shift->forceFill(['register_status' => self::REFUSED, 'register_message' => $this->message($response, 'The office did not open the register.')])->save(),
            // outlet_not_set, a server error: tried again later.
            default => null,
        };
    }

    /**
     * Whether another shift of this till already works in the given register session.
     */
    protected function sessionHeldByAnotherShift(Shift $shift, string $sessionId): bool
    {
        return $sessionId !== '' && Shift::query()
            ->whereKeyNot($shift->getKey())
            ->where('cash_register_session_id', $sessionId)
            ->exists();
    }

    protected function opened(Shift $shift, Response $response, ?string $message): void
    {
        $shift->forceFill([
            'register_status' => self::OPEN,
            'cash_register_session_id' => (string) $response->json('data.session.id'),
            'cash_register_id' => $response->json('data.session.cash_register_id') ?: $shift->cash_register_id,
            'register_message' => $message,
        ])->save();
    }

    /**
     * Cash in and out of shifts whose register is open in the ERP (or will never be), sent with the push contract.
     */
    protected function pushMovements(ErpApiService $erp): void
    {
        $movements = CashMovement::query()
            ->with('shift')
            ->whereNull('synced_at')
            ->whereNull('sync_status')
            ->whereHas('shift', fn (Builder $query) => $query->whereIn('register_status', [self::OPEN, self::CLOSED, self::NO_REGISTER, self::REFUSED]))
            ->oldest()
            ->limit(50)
            ->get();

        if ($movements->isEmpty()) {
            return;
        }

        $data = $movements->mapWithKeys(fn (CashMovement $movement) => [(string) $movement->id => ['CashMovement' => array_filter([
            'id' => (string) $movement->id,
            'flow_type' => $movement->type === CashMovement::IN ? 1 : 2,
            'daily_category_id' => $movement->daily_category_id,
            'amount' => round((float) $movement->amount, 2),
            'note' => $movement->reason,
            'happened_at' => $movement->created_at?->format('Y-m-d H:i:s'),
            'cash_register_session_id' => in_array($movement->shift?->register_status, [self::OPEN, self::CLOSED], true) ? $movement->shift->cash_register_session_id : null,
        ], fn ($value) => $value !== null)]])->all();

        $response = $erp->sync('push', 'CashMovement', data: $data);

        if (! $response->successful()) {
            return;
        }

        $results = (array) $response->json('data.results', []);
        $codes = (array) $response->json('data.failed_codes', []);
        $failed = (array) $response->json('data.failed_order_ids', []);

        foreach ($movements as $movement) {
            $id = (string) $movement->id;

            if (isset($results[$id])) {
                $withIssues = ($results[$id]['status'] ?? null) === 'received_with_issues';
                $movement->forceFill(['synced_at' => now(), 'sync_status' => $withIssues ? 'received_with_issues' : 'received', 'sync_message' => $withIssues ? 'The office checks this one.' : null])->save();
            } elseif (isset($codes[$id]) && $codes[$id] !== 'server_error') {
                $movement->forceFill(['sync_status' => 'refused', 'sync_message' => mb_substr((string) (((array) ($failed[$id] ?? []))[0] ?? 'Refused by the office.'), 0, 500)])->save();
            }
        }
    }

    protected function close(ErpApiService $erp, Shift $shift): void
    {
        // The ERP counts the register's cash from what is booked: the shift's sales and cash movements go first.
        $salesWaiting = Order::query()->where('shift_id', $shift->id)->notDraft()->whereNull('last_sync_at')->whereNull('sync_response')->exists();
        $movementsWaiting = $shift->cashMovements()->whereNull('synced_at')->whereNull('sync_status')->exists();

        if ($salesWaiting || $movementsWaiting) {
            return;
        }

        $response = $erp->registerClose([
            'cash_register_session_id' => (string) $shift->cash_register_session_id,
            'counted_cash' => round((float) $shift->counted_cash, 2),
            'closing_note' => $shift->close_note ?: 'Till shift '.$shift->number.' closed',
        ]);

        match (true) {
            $response->successful() => $shift->forceFill([
                'register_status' => self::CLOSED,
                'register_message' => null,
                'erp_expected_cash' => $response->json('data.session.expected'),
                'erp_difference' => $response->json('data.session.difference'),
            ])->save(),
            $this->code($response) === 'register_closed' => $shift->forceFill(['register_status' => self::CLOSED, 'register_message' => 'The register was already closed in the office.'])->save(),
            in_array($this->code($response), ['not_allowed', 'refused', 'invalid', 'register_not_found'], true) => $shift->forceFill(['register_status' => self::REFUSED, 'register_message' => $this->message($response, 'The office did not close the register; close it there.')])->save(),
            default => null,
        };
    }

    protected function code(Response $response): ?string
    {
        $code = $response->json('code');

        return is_string($code) ? $code : null;
    }

    protected function message(Response $response, string $fallback): string
    {
        $message = $response->json('message');

        return mb_substr(is_string($message) && $message !== '' ? $message : $fallback, 0, 500);
    }
}
