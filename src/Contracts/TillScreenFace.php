<?php

namespace Rishadblack\SyllooPosCore\Contracts;

use Rishadblack\SyllooAppBase\Screens\Hooks;
use Rishadblack\SyllooPosCore\Models\User;

/**
 * What only a till's face does on the shared device screens (Screens\TillScreens decides the rest): sign the cashier
 * in to its session, show a notice on the next screen and add its own pieces (views) at the base's hook places.
 */
interface TillScreenFace
{
    /**
     * The cashier is signed in on this till (the PC: the web session).
     */
    public function signIn(User $cashier): void;

    /**
     * A notice the next screen shows.
     */
    public function noticeOnNextPage(string $title, ?string $message = null, string $type = 'success'): void;

    /**
     * Add the face's own pieces at the base's hook places (the fresh-start warning, "Where is my code?", "Copy the
     * link", the open-shift line on Releasing).
     */
    public function addPieces(Hooks $hooks): void;
}
