<?php

namespace Rishadblack\SyllooPosCore\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Rishadblack\SyllooPosCore\Models\PosStaff;
use Rishadblack\SyllooPosCore\Models\Setting;
use Rishadblack\SyllooPosCore\Models\User;
use Throwable;

/**
 * Who may work on this terminal and what they may approve (audit Phase 3, manager override), from the ERP (erp:
 * PosStaff): a cashier does what their permissions allow, anything more needs the PIN of someone who may. The list is
 * taken at sign-in, on Sync now and when the heartbeat shows another version; the PIN is checked against its hash
 * here, offline too, with a few tries a minute.
 */
class StaffDirectory
{
    public const VERSION_SETTING = 'staff_version';

    /** Wrong PINs allowed per minute on this terminal. */
    public const MAX_PIN_ATTEMPTS = 5;

    /**
     * Take the list from the ERP. Returns whether it was taken.
     */
    public function refresh(?User $cashier = null): bool
    {
        $cashier ??= User::latestWithValidToken();

        if (! $cashier) {
            return false;
        }

        try {
            $response = (new ErpApiService($cashier))->staff();
        } catch (Throwable $exception) {
            Log::info('Staff list: the ERP could not be reached: '.$exception->getMessage());

            return false;
        }

        $staff = $response->successful() ? $response->json('data.staff') : null;

        if (! is_array($staff)) {
            return false;
        }

        DB::transaction(function () use ($staff, $response) {
            $ids = [];

            foreach ($staff as $member) {
                if (! is_array($member) || ! is_string($member['id'] ?? null)) {
                    continue;
                }

                $ids[] = $member['id'];
                PosStaff::query()->updateOrCreate(['id' => $member['id']], [
                    'name' => $member['name'] ?? null,
                    'pin_hash' => $member['pin_hash'] ?? null,
                    'permissions' => array_values(array_filter((array) ($member['permissions'] ?? []), 'is_string')),
                    'max_discount_percent' => max(0, min(100, (int) ($member['max_discount_percent'] ?? 0))),
                ]);
            }

            PosStaff::query()->whereKeyNot($ids)->delete();
            Setting::put(self::VERSION_SETTING, (string) $response->json('data.version'));
        });

        return true;
    }

    public function isOlderThan(?string $remoteVersion): bool
    {
        return filled($remoteVersion) && $remoteVersion !== Setting::valueOf(self::VERSION_SETTING);
    }

    /**
     * Whether the signed-in cashier may do it alone: a permission, or (with $discountPercent) a discount that large.
     * A till without a web session (the phone) names its cashier.
     */
    public function cashierMay(?string $permission, ?float $discountPercent = null, ?User $cashier = null): bool
    {
        $user = $cashier ?? Auth::user();

        if (! $user instanceof User) {
            return false;
        }

        return $this->allows(PosStaff::query()->find($user->id), $permission, $discountPercent, $user->max_discount_percent);
    }

    /**
     * People who may approve something: a PIN on this terminal and the permission (or any approval right when null).
     *
     * @return Collection<int, PosStaff>
     */
    public function approvers(?string $permission = null): Collection
    {
        return PosStaff::query()->whereNotNull('pin_hash')->orderBy('name')->get()
            ->filter(fn (PosStaff $staff) => $staff->hasPin() && ($permission === null || $staff->may($permission)))
            ->values();
    }

    /**
     * The staff member who approves (chosen by name, then their PIN; members choose their own PIN, so two may share
     * one) and who may do it, or null. Without a chosen name the PIN alone is taken only when exactly one person has
     * it. Every failed try counts against the PIN limit, which only an approval that goes through clears.
     *
     * @return array{staff: ?PosStaff, problem: ?string}
     */
    public function approverForPin(string $pin, ?string $permission, ?float $discountPercent = null, ?string $staffId = null): array
    {
        $key = 'pos-approval-pin';

        if (RateLimiter::tooManyAttempts($key, self::MAX_PIN_ATTEMPTS)) {
            return ['staff' => null, 'problem' => __('device.approval_wait')];
        }

        $owners = filled($staffId)
            ? PosStaff::query()->whereKey($staffId)->whereNotNull('pin_hash')->get()->filter(fn (PosStaff $staff) => $staff->pinMatches($pin))
            : PosStaff::query()->whereNotNull('pin_hash')->get()->filter(fn (PosStaff $staff) => $staff->pinMatches($pin));

        if ($owners->count() > 1) {
            return ['staff' => null, 'problem' => __('device.approval_choose_name')];
        }

        $owner = $owners->first();

        if (! $owner) {
            RateLimiter::hit($key, 60);

            return ['staff' => null, 'problem' => __('device.approval_unknown_pin')];
        }

        // A PIN that may not approve this (a cashier's own) is a failed try too and never resets the count (audit P1):
        // only an approval that goes through does.
        if (! $this->allows($owner, $permission, $discountPercent)) {
            RateLimiter::hit($key, 60);

            return ['staff' => null, 'problem' => __('device.approval_not_allowed', ['name' => $owner->name])];
        }

        RateLimiter::clear($key);

        return ['staff' => $owner, 'problem' => null];
    }

    protected function allows(?PosStaff $staff, ?string $permission, ?float $discountPercent, ?int $fallbackDiscount = null): bool
    {
        if ($discountPercent !== null) {
            $limit = $staff?->max_discount_percent ?? $fallbackDiscount;

            // A cashier the ERP sent no limit for (an older ERP) is not limited.
            return $limit === null || $discountPercent <= $limit + 0.01;
        }

        return $permission === null || ($staff?->may($permission) ?? false);
    }
}
