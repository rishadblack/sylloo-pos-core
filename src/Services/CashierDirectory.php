<?php

namespace Rishadblack\SyllooPosCore\Services;

use Illuminate\Support\Carbon;
use InvalidArgumentException;
use Rishadblack\SyllooAppBase\Contracts\DeviceMember;
use Rishadblack\SyllooAppBase\Contracts\MemberDirectory;
use Rishadblack\SyllooAppBase\Erp\DeviceApi;
use Rishadblack\SyllooAppBase\SignIn\SignedInPerson;
use Rishadblack\SyllooPosCore\Jobs\CacheErpImages;
use Rishadblack\SyllooPosCore\Models\Order;
use Rishadblack\SyllooPosCore\Models\PosStaff;
use Rishadblack\SyllooPosCore\Models\User;
use Rishadblack\SyllooPosCore\Support\DeviceState;

/**
 * The till's answer to the shared base's MemberDirectory (plan §4.3 "The people"): the people of a terminal are its
 * cashiers ({@see User}), their PIN hashes the staff list's ({@see PosStaff}), as before the move.
 */
class CashierDirectory implements MemberDirectory
{
    public function pinHash(DeviceMember $member): ?string
    {
        $staff = PosStaff::query()->find($member->memberKey());

        return $staff?->hasPin() ? (string) $staff->pin_hash : null;
    }

    public function rememberPinHash(DeviceMember $member, string $hash): void
    {
        PosStaff::query()->updateOrCreate(['id' => $member->memberKey()], [
            'name' => $member->memberName(),
            'pin_hash' => $hash,
        ]);
    }

    public function forgetPinHash(DeviceMember $member): void
    {
        PosStaff::query()->whereKey($member->memberKey())->update(['pin_hash' => null]);
    }

    public function rememberPhoto(DeviceMember $member, ?string $photo): void
    {
        $user = $this->user($member);
        $user->forceFill(['image_url' => $photo])->save();
        CacheErpImages::dispatch(User::class, [(string) $user->getKey()]);
    }

    /**
     * @return iterable<User>
     */
    public function signedInMembers(): iterable
    {
        return User::query()->signedInHere()->get();
    }

    public function deviceApi(DeviceMember $member): DeviceApi
    {
        return new ErpApiService($this->user($member));
    }

    /**
     * Keep the cashier as the office signed them in: details, token (until `expires_at`), offline days, their discount
     * limit, the terminal's serial and the PIN hash of this side (the PIN works at once, before the staff list arrives).
     */
    public function keepSignIn(SignedInPerson $person): User
    {
        $answer = $person->answer;
        $details = is_array($answer['user'] ?? null) ? $answer['user'] : [];
        $id = $person->id;
        // No address is made up for a member the office sends none for (the column is nullable).
        $email = is_string($details['email'] ?? null) && $details['email'] !== '' ? $details['email'] : null;

        // A person left from another business (e.g. before a Practice/Live switch) may hold the same email.
        if ($email !== null) {
            User::query()->where('email', $email)->whereKeyNot($id)->delete();
        }

        $user = User::findOrNew($id);
        $user->id = $id;
        $user->name = $person->name;
        $user->email = $email;
        $user->phone = is_string($details['phone'] ?? null) ? $details['phone'] : null;
        $photo = $answer['member']['photo'] ?? $details['image_url'] ?? null;
        $user->image_url = is_string($photo) && $photo !== '' ? $photo : null;
        $user->save();

        CacheErpImages::dispatch(User::class, [$id]);

        $user->storeToken(
            $person->token,
            rescue(fn () => Carbon::parse((string) $answer['expires_at'])->setTimezone(config('app.timezone')), null, false) ?? now()->addDays(180),
        );

        if ($person->offlineLoginDays !== null) {
            DeviceState::remember(['offline_login_days' => $person->offlineLoginDays]);
        }

        $user->allowOfflineSignInFor($person->offlineLoginDays ?? DeviceState::offlineLoginDays());

        // The largest discount the cashier's ERP roles allow; an ERP that sends none sets no limit here.
        $maxDiscount = $answer['limits']['max_discount_percent'] ?? null;
        $user->forceFill(['max_discount_percent' => is_numeric($maxDiscount) ? max(0, min(100, (int) $maxDiscount)) : null])->save();
        Order::rememberTerminalSerial($answer['terminal']['serial'] ?? null);
        PinSignIn::forgetFailures($id);

        app(PinSignIn::class)->rememberHash($user, $answer['pin_hash'] ?? null);

        return $user;
    }

    public function member(string $key): ?User
    {
        return User::query()->find($key);
    }

    /**
     * The cashier behind a member of this directory.
     */
    protected function user(DeviceMember $member): User
    {
        if ($member instanceof User) {
            return $member;
        }

        return User::query()->find($member->memberKey())
            ?? throw new InvalidArgumentException('No cashier '.$member->memberKey().' on this terminal.');
    }
}
