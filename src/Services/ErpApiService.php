<?php

namespace Rishadblack\SyllooPosCore\Services;

use Exception;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Rishadblack\SyllooAppBase\Erp\DeviceApi;
use Rishadblack\SyllooPosCore\Models\User;

/**
 * The till's calls to the ERP. The device calls (`device/api/v1/…`: activate, state, sign-in, PIN, me, sign-out,
 * deactivate, released, test-result) are the shared base's ({@see DeviceApi}); the till adds its POS API
 * (`pos/api/v1/…`: sync, staff, register, health, reset and the look-ups), which stays as it is (plan R4).
 *
 * @property ApiService $api
 */
class ErpApiService extends DeviceApi
{
    /**
     * Calls carry the given cashier's sign-in token, by default the signed-in cashier's.
     */
    public function __construct(?User $user = null)
    {
        parent::__construct(new ApiService($user));
    }

    /**
     * The cashier whose sign-in token the calls carry, or null when they carry none.
     */
    public function tokenUser(): ?User
    {
        return $this->api->tokenUser();
    }

    /**
     * Whether the cashier's sign-in token still works, one quick try (PIN sign-in after the offline window).
     */
    public function checkUser(): Response
    {
        return $this->probe()->postOnce('/v1/user', [], ApiService::INTERACTIVE_TIMEOUT_SECONDS, ApiService::INTERACTIVE_TIMEOUT_SECONDS);
    }

    /**
     * A customer's store credit (their advance with the business), to pay a sale from it.
     */
    public function customerCredit(string $contactId): Response
    {
        return $this->api->postOnce('/v1/customer-credit', ['contact_id' => $contactId], ApiService::INTERACTIVE_TIMEOUT_SECONDS);
    }

    /**
     * A sale of this outlet (any of its tills) by its receipt number, to return items of it.
     */
    public function saleLookup(string $code): Response
    {
        return $this->api->postOnce('/v1/sale-lookup', ['code' => $code], ApiService::INTERACTIVE_TIMEOUT_SECONDS);
    }

    public function getUser(): Response
    {
        return $this->api->post('/v1/user');
    }

    /**
     * One page of a model, starting after the given cursor (the ERP's `cursors.<model>.next`; null for the first page).
     *
     * @param  array<string, string>  $date
     */
    public function pullAfter(string $model, array $date, ?string $cursor, int $perPage): Response
    {
        return $this->api->postIdempotent('/v1/sync', [
            'action' => 'pull',
            'models' => $model,
            'date' => $date,
            'paging' => 'cursor',
            'per_page' => $perPage,
            'cursor' => $cursor !== null ? [$model => $cursor] : [],
        ]);
    }

    /**
     * A push (`action` push) is sent once per try of the job; a pull only reads and is retried like a GET.
     */
    public function sync(string $action, $models, ?array $date = null, ?string $page = null, array $data = []): Response
    {
        $payload = [
            'action' => $action,
            'models' => $models,
            'date' => $date,
            'page' => $page,
            'data' => $data,
        ];

        return $action === 'pull'
            ? $this->api->postIdempotent('/v1/sync', $payload)
            : $this->api->post('/v1/sync', $payload);
    }

    /**
     * Seconds the till waits for the serial check before it accepts the serial unchecked.
     */
    public const SERIAL_CHECK_TIMEOUT_SECONDS = 3;

    /**
     * Seconds the till waits when it asks whether a scanned code it does not know is a serial number.
     */
    public const SERIAL_LOOKUP_TIMEOUT_SECONDS = 1.5;

    /**
     * Ask the ERP about one serial number (in stock here, sold, at another shop, returned or damaged, unknown, and
     * whether it belongs to the product). One quick try: the cashier is waiting at the till.
     */
    public function serialCheck(string $serial, ?string $productId = null, int|float $timeoutSeconds = self::SERIAL_CHECK_TIMEOUT_SECONDS): Response
    {
        return $this->api->postOnce('/v1/serial-check', array_filter([
            'serial' => $serial,
            'product_id' => $productId,
        ], fn ($value) => $value !== null), $timeoutSeconds);
    }

    public function getBatchId(): Response
    {
        return $this->api->post('/v1/get-batch');
    }

    public function getUpdate(?array $date = null, $models = null): Response
    {
        return $this->api->postIdempotent('/v1/get-update', [
            'date' => $date,
            'models' => $models,
        ]);
    }

    /**
     * Report this terminal's sync health. Works without a signed-in cashier and with an expired licence.
     *
     * @param  array<string, mixed>  $payload
     */
    public function health(array $payload): Response
    {
        return $this->probe()->post('/v1/health', $payload);
    }

    /**
     * Ask the ERP to run the hard reset it ordered (erp: `POST pos/api/v1/reset`, RecordTerminalReset): refused with
     * `sync_required` (409) while sales wait by its count or by $payload's; when it agrees it records the reset and
     * the terminal wipes its data (ErpOrderedReset). One try: a page load waits for it.
     *
     * @param  array{app_version: string, pending_orders: int, uploading_orders: int, rejected_orders: int, failed_jobs: int}  $payload
     */
    public function reset(array $payload): Response
    {
        return $this->probe()->postOnce('/v1/reset', $payload, ApiService::INTERACTIVE_TIMEOUT_SECONDS * 2, ApiService::INTERACTIVE_TIMEOUT_SECONDS);
    }

    /**
     * Ask the ERP to sign a subscription to this terminal's private socket channel. Always tried, like the heartbeat:
     * the socket reconnecting is a sign the ERP is back, and a channel left unsigned would miss every ERP command.
     */
    public function broadcastAuth(string $socketId, string $channelName): Response
    {
        return $this->probe()->post('/v1/broadcasting/auth', [
            'socket_id' => $socketId,
            'channel_name' => $channelName,
        ]);
    }

    /**
     * The business and outlet details this terminal prints (TerminalProfile); needs only the activation key.
     */
    public function terminalProfile(): Response
    {
        return $this->api->post('/v1/terminal-profile');
    }

    /**
     * Who may work on this terminal, with their POS permissions, discount limit and PIN hash (StaffDirectory).
     */
    public function staff(): Response
    {
        return $this->api->post('/v1/staff');
    }

    /**
     * Open the terminal's cash register in the ERP for a shift (RegisterSync); answers with the session.
     *
     * @param  array{cash_register_id?: string, opening_float: float, opening_note?: string}  $data
     */
    public function registerOpen(array $data): Response
    {
        return $this->api->post('/v1/register/open', $data);
    }

    /**
     * Close the shift's register session with the counted cash (RegisterSync); answers with the closed session.
     *
     * @param  array{cash_register_session_id: string, counted_cash: float, closing_note?: string}  $data
     */
    public function registerClose(array $data): Response
    {
        return $this->api->post('/v1/register/close', $data);
    }

    /**
     * Old dues customers paid at the till, without a sale (DueCollections), sent once per try of the push job; the
     * office answers each by its own id (`received_ids`, `failed_ids`, `failed_codes`, `results`).
     *
     * @param  array<string, array{DueCollection: array<string, mixed>, Payment: list<array<string, mixed>>}>  $data
     */
    public function dueCollections(array $data): Response
    {
        return $this->api->post('/v1/due-collections', ['data' => $data]);
    }

    public function ping(): Response
    {
        return $this->api->post('/v1/ping');
    }

    public function isOnline(bool $useCache = true): bool
    {
        $cacheKey = 'erp_server_online';
        $cacheDuration = 30; // 30 seconds

        if (! $useCache) {
            Cache::forget($cacheKey);
        }

        return Cache::remember($cacheKey, $cacheDuration, function () {
            try {
                $response = $this->ping();

                return $response->successful();
            } catch (Exception $e) {
                return false;
            }
        });
    }

    public function checkOnlineStatus(): bool
    {
        return $this->isOnline(false);
    }

    public function clearOnlineCache(): void
    {
        Cache::forget('erp_server_online');
    }
}
