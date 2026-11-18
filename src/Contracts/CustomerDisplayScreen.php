<?php

namespace Rishadblack\SyllooPosCore\Contracts;

/**
 * The customer-facing display of a till: the PC till's second window, an Android till's second screen. The core keeps
 * what it shows (CustomerDisplayService::snapshot()); the till draws it.
 */
interface CustomerDisplayScreen
{
    /**
     * Show what the display should show now.
     *
     * @param  array{state: 'idle'|'active'|'thanks', cart: ?array<string, mixed>, sale: ?array<string, mixed>}  $snapshot
     */
    public function show(array $snapshot): void;

    /**
     * Open the display (on a second monitor when there is one).
     */
    public function open(): void;

    /**
     * The address the display loads one of the shop's advertisement files from.
     */
    public function advertisementUrl(string $file): string;
}
