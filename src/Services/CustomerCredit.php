<?php

namespace Rishadblack\SyllooPosCore\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Rishadblack\SyllooPosCore\Models\Contact;
use Rishadblack\SyllooPosCore\Models\Order;
use Throwable;

/**
 * What a customer owes, has overdue and may owe at most (owner decision 2026-11-10, erp: docs/README.md §8q), for the
 * customer card, the credit limit check of a sale and the due collection panel.
 *
 * The office sends the balance, overdue amount and credit limit with the customer (pull) and again when the cashier
 * picks the customer online (`customer-credit`, {@see self::refresh()}), with the time it read them (`balance_at`).
 * What this till did since is added here, offline too: the dues of its sales and the collections it took that the
 * office did not have at that time (not sent yet, or sent after it). A credit limit of 0 or none means no limit.
 */
class CustomerCredit
{
    /** Seconds the till waits for the office when a customer is picked; the card keeps the last figures otherwise. */
    public const LOOKUP_TIMEOUT_SECONDS = 2;

    /** Differences below this (rounding) never ask for approval. */
    public const TOLERANCE = 0.005;

    /**
     * The customer card's figures: `owed` (the office's balance plus this till's changes; below zero an advance),
     * `overdue`, `limit` (0: none) and `over_limit`.
     *
     * @return array{owed: float, overdue: float, limit: float, over_limit: bool, checked_at: ?Carbon}
     */
    public function figures(Contact $contact): array
    {
        $owed = $this->owed($contact);
        $limit = $this->limit($contact);

        return [
            'owed' => $owed,
            'overdue' => round(max(0.0, (float) ($contact->overdue ?? 0)), 2),
            'limit' => $limit,
            'over_limit' => $limit > 0 && $owed > $limit + self::TOLERANCE,
            'checked_at' => $contact->balance_at ? Carbon::parse($contact->balance_at) : null,
        ];
    }

    /**
     * What the customer owes now: the office's last balance, plus the dues of this till's sales the office did not
     * have then, less the due collections it did not have then.
     */
    public function owed(Contact $contact): float
    {
        // Compared with this till's own times, which are kept in the app's time zone.
        $since = $contact->balance_at ? Carbon::parse($contact->balance_at)->setTimezone(config('app.timezone'))->format('Y-m-d H:i:s') : null;
        $notInBalance = fn (Builder $query) => $query->where(fn (Builder $query) => $query->whereNull('orders.last_sync_at')
            ->when($since, fn (Builder $query) => $query->orWhere('orders.last_sync_at', '>', $since)));

        $local = Order::query()
            ->notDraft()
            ->where('orders.contact_id', $contact->id)
            ->where('orders.from_office', false)
            ->whereIn('orders.type', [1, DueCollections::TYPE])
            ->where($notInBalance)
            ->selectRaw('SUM(CASE WHEN orders.type = ? THEN -orders.net_amount ELSE orders.due_amount END) AS owed', [DueCollections::TYPE])
            ->value('owed');

        return round((float) ($contact->balance ?? 0) + (float) $local, 2);
    }

    public function limit(Contact $contact): float
    {
        return round(max(0.0, (float) ($contact->credit_limit ?? 0)), 2);
    }

    /**
     * Whether leaving this much more owed takes the customer over their credit limit.
     */
    public function wouldExceed(Contact $contact, float $newDue): bool
    {
        $limit = $this->limit($contact);

        return $limit > 0 && $newDue > self::TOLERANCE && $this->owed($contact) + $newDue > $limit + self::TOLERANCE;
    }

    /**
     * Ask the office for the customer's figures now (one quick try); keeps the last ones when it can not be reached.
     * Returns whether they were updated.
     */
    public function refresh(Contact $contact): bool
    {
        if (ErpCircuitBreaker::isOpen()) {
            return false;
        }

        try {
            $response = (new ErpApiService)->customerCredit((string) $contact->id);
        } catch (Throwable) {
            return false;
        }

        return $this->remember($contact, $response);
    }

    /**
     * Keep the figures of a `customer-credit` answer (the store credit lookup gives them too). An older office that
     * sends no balance changes nothing.
     */
    public function remember(Contact $contact, ?Response $response): bool
    {
        if (! $response?->successful() || ! is_numeric($response->json('data.balance'))) {
            return false;
        }

        $at = $response->json('data.balance_at');

        try {
            $balanceAt = is_string($at) && $at !== '' ? Carbon::parse($at) : now();
        } catch (Throwable) {
            $balanceAt = now();
        }

        $balanceAt = $balanceAt->setTimezone(config('app.timezone'))->format('Y-m-d H:i:s');

        $contact->forceFill([
            'balance' => round((float) $response->json('data.balance'), 2),
            'overdue' => round(max(0.0, (float) $response->json('data.overdue')), 2),
            'credit_limit' => round(max(0.0, (float) $response->json('data.credit_limit')), 2),
            'balance_at' => $balanceAt,
        ])->saveQuietly();

        return true;
    }
}
