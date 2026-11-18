<?php

namespace Rishadblack\SyllooPosCore\Support;

use Rishadblack\SyllooPosCore\Models\Product;
use Rishadblack\SyllooPosCore\Models\Setting;
use Throwable;

/**
 * The kitchen printers of this PC (erp: docs/restaurant.md, phase 5): each station has a name, a printer and the
 * categories it cooks; one is the default for everything else. Kept per PC like the receipt printer and copied with
 * the settings file. Without any station a ticket goes to the receipt printer, headed "Kitchen".
 */
final class KitchenStations
{
    public const SETTING = 'kitchen_stations';

    public const FALLBACK = 'kitchen_fallback_to_receipt';

    public const MAX_STATIONS = 10;

    /** The station of a till with none set up: the receipt printer. */
    public const RECEIPT_STATION = 'Kitchen';

    /**
     * @return list<array{name: string, printer: ?string, categories: list<string>, default: bool}>
     */
    public static function all(): array
    {
        try {
            $saved = json_decode((string) Setting::valueOf(self::SETTING), true);
        } catch (Throwable) {
            return [];
        }

        return self::clean(is_array($saved) ? $saved : []);
    }

    /**
     * The stations as they are saved: named, at most MAX_STATIONS, exactly one default when there are any.
     *
     * @param  array<int, mixed>  $stations
     * @return list<array{name: string, printer: ?string, categories: list<string>, default: bool}>
     */
    public static function clean(array $stations): array
    {
        $clean = collect($stations)
            ->filter(fn ($station) => is_array($station) && filled(trim((string) ($station['name'] ?? ''))))
            ->take(self::MAX_STATIONS)
            ->map(fn (array $station) => [
                'name' => mb_substr(trim((string) $station['name']), 0, 60),
                'printer' => filled($station['printer'] ?? null) ? mb_substr((string) $station['printer'], 0, 255) : null,
                'categories' => array_values(array_unique(array_map('strval', array_filter((array) ($station['categories'] ?? []), 'filled')))),
                'default' => (bool) ($station['default'] ?? false),
            ])
            ->values();

        if ($clean->isEmpty()) {
            return [];
        }

        $default = $clean->search(fn (array $station) => $station['default']);

        return $clean->map(fn (array $station, int $index) => [...$station, 'default' => $index === ($default === false ? 0 : $default)])->all();
    }

    /**
     * @param  array<int, mixed>  $stations
     */
    public static function save(array $stations, bool $fallbackToReceipt): void
    {
        Setting::put(self::SETTING, json_encode(self::clean($stations), JSON_UNESCAPED_UNICODE));
        Setting::put(self::FALLBACK, $fallbackToReceipt);
    }

    /**
     * Whether a ticket that failed twice prints on the receipt printer instead (on by default).
     */
    public static function fallbackToReceipt(): bool
    {
        try {
            return (bool) Setting::valueOf(self::FALLBACK, '1');
        } catch (Throwable) {
            return true;
        }
    }

    /**
     * Where an item is cooked: the first station that has one of its categories, else the default station.
     *
     * @param  list<array{name: string, printer: ?string, categories: list<string>, default: bool}>|null  $stations
     * @return array{name: string, printer: ?string}
     */
    public static function stationFor(?Product $product, ?array $stations = null): array
    {
        $stations ??= self::all();

        if ($stations === []) {
            return ['name' => self::RECEIPT_STATION, 'printer' => null];
        }

        $categories = array_map('strval', (array) ($product?->category_ids ?? []));
        $station = collect($stations)->first(fn (array $station) => array_intersect($station['categories'], $categories) !== [])
            ?? collect($stations)->firstWhere('default', true)
            ?? $stations[0];

        return ['name' => $station['name'], 'printer' => $station['printer']];
    }

    /**
     * The printer of a station by its name (a ticket keeps the name); null is the receipt printer.
     */
    public static function printerOf(string $station): ?string
    {
        return collect(self::all())->firstWhere('name', $station)['printer'] ?? null;
    }
}
