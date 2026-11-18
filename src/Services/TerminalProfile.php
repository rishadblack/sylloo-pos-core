<?php

namespace Rishadblack\SyllooPosCore\Services;

use DateTimeZone;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Rishadblack\SyllooPosCore\Jobs\PullErpJob;
use Rishadblack\SyllooPosCore\Jobs\PullStart;
use Rishadblack\SyllooPosCore\Models\Setting;
use Rishadblack\SyllooPosCore\Support\RestaurantMode;
use Throwable;

/**
 * The business and outlet details this terminal prints and shows (audit §7.2), from the ERP (erp: Modules/Pos
 * TerminalProfile): name, slogan, website, VAT number, the outlet's address and contacts, its time zone and receipt
 * width, and the logo. One copy is kept in the settings table and replaced whenever the ERP's copy is newer
 * (heartbeat `profile_updated_at`). Nothing about the store is typed in on the terminal; until the first copy
 * arrives receipts print without a header. LocalDataReset removes it with the settings table.
 */
class TerminalProfile
{
    public const SETTING = 'terminal_profile';

    public function __construct(protected ImageCache $images) {}

    /**
     * The stored profile, or null before the first one arrived.
     *
     * @return array{updated_at: ?string, business: array<string, ?string>, outlet: array<string, mixed>, logo: array{url: ?string, path?: ?string}, fetched_at?: string}|null
     */
    public function current(): ?array
    {
        try {
            $profile = json_decode((string) Setting::valueOf(self::SETTING), true);
        } catch (Throwable) {
            return null;
        }

        return is_array($profile) ? $profile : null;
    }

    /**
     * Whether the ERP has a newer profile than the stored one.
     */
    public function isOlderThan(?string $remoteUpdatedAt): bool
    {
        if (! filled($remoteUpdatedAt)) {
            return false;
        }

        $local = $this->current()['updated_at'] ?? null;

        return ! $local || rescue(fn () => Carbon::parse($remoteUpdatedAt)->gt(Carbon::parse($local)), true, false);
    }

    /**
     * Ask the ERP for its profile and keep it (with a local copy of the logo). Returns whether a profile is stored.
     */
    public function refresh(?ErpApiService $erp = null): bool
    {
        try {
            $response = ($erp ?? new ErpApiService)->terminalProfile();
        } catch (Throwable $exception) {
            Log::info('Terminal profile: the ERP could not be reached: '.$exception->getMessage());

            return $this->current() !== null;
        }

        $profile = $response->successful() ? $response->json('data') : null;

        if (! is_array($profile) || ! is_array($profile['business'] ?? null) || ! is_array($profile['outlet'] ?? null)) {
            Log::warning('Terminal profile: the ERP did not send one', ['status' => $response->status()]);

            return $this->current() !== null;
        }

        $this->store($profile);

        return true;
    }

    /**
     * Keep a profile from the ERP: its logo downloaded for printing offline, and the outlet's time zone used.
     *
     * @param  array<string, mixed>  $profile
     */
    public function store(array $profile): void
    {
        $logoUrl = is_string($profile['logo']['url'] ?? null) ? $profile['logo']['url'] : null;
        $profile['logo'] = ['url' => $logoUrl, 'path' => $logoUrl ? $this->images->cache($logoUrl) : null];
        $profile['fetched_at'] = now()->toIso8601String();
        $wasRestaurant = RestaurantMode::isOn();

        Setting::put(self::SETTING, json_encode($profile, JSON_UNESCAPED_UNICODE));

        // The switch was just turned on: the tables come now, not at the next pull.
        if (! $wasRestaurant && RestaurantMode::isOn()) {
            PullStart::dispatch(models: implode(',', PullErpJob::RESTAURANT_MODELS));
        }

        $timeZone = $profile['outlet']['time_zone'] ?? null;

        if (is_string($timeZone) && in_array($timeZone, DateTimeZone::listIdentifiers(), true)) {
            Setting::put('timezone', $timeZone);
        }
    }

    /**
     * The store details a receipt prints, from the profile; empty values before the first profile.
     *
     * @return array{name: ?string, outlet_name: ?string, sologan: ?string, address: ?string, mobile: ?string, phone: ?string, email: ?string, website_address: ?string, vat_reg_no: ?string, logo: ?string}
     */
    public function storeDetails(): array
    {
        $profile = $this->current() ?? [];
        $business = (array) ($profile['business'] ?? []);
        $outlet = (array) ($profile['outlet'] ?? []);
        $logoPath = $profile['logo']['path'] ?? null;

        return [
            'name' => $business['name'] ?? null,
            'outlet_name' => $outlet['name'] ?? null,
            // `sologan` as the ERP's settings spell it, so older receipt code reads it the same way.
            'sologan' => $business['slogan'] ?? null,
            'address' => $outlet['address'] ?? $business['address'] ?? null,
            'mobile' => $outlet['mobile'] ?? $business['mobile'] ?? null,
            'phone' => $outlet['phone'] ?? $business['phone'] ?? null,
            'email' => $outlet['email'] ?? $business['email'] ?? null,
            'website_address' => $business['website'] ?? null,
            'vat_reg_no' => $business['vat_reg_no'] ?? null,
            // The local copy on the public disk (ReceiptPrintService::printableLogo() inlines it).
            'logo' => is_string($logoPath) && $logoPath !== '' ? $logoPath : null,
        ];
    }

    /**
     * The receipt width the outlet prints on (58 or 80 mm), or null when the ERP sent none.
     */
    public function receiptWidth(): ?int
    {
        $width = (int) ($this->current()['outlet']['receipt_width'] ?? 0);

        return in_array($width, [58, 80], true) ? $width : null;
    }
}
