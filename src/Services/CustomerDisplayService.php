<?php

namespace Rishadblack\SyllooPosCore\Services;

use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Rishadblack\SyllooPosCore\Contracts\CustomerDisplayScreen;
use Rishadblack\SyllooPosCore\Models\Product;

/**
 * Customer-facing display (second screen).
 *
 * The terminal publishes its cart to the cache and, whenever it changes, hands what the display shows
 * ({@see self::snapshot()}) to the till's {@see CustomerDisplayScreen} (the PC till pushes the rendered live panel to
 * its display window), so the display never polls. The PC's display routes run without a session, like the tray panel,
 * see {@see SyncStatusService}.
 */
class CustomerDisplayService
{
    public const WINDOW_ID = 'customer_display';

    public const CART_CACHE_KEY = 'pos.customer_display.cart';

    public const SALE_CACHE_KEY = 'pos.customer_display.last_sale';

    /**
     * How long the "thank you" screen stays up after a sale.
     */
    public const THANK_YOU_SECONDS = 12;

    public const IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp', 'gif'];

    public const VIDEO_EXTENSIONS = ['mp4', 'webm'];

    /**
     * @param  array{code: ?string, customer: ?string, stage: string, items: array<int, array{name: string, quantity: float, price: float, total: float}>, totals: array<string, float>}  $cart
     */
    public static function publishCart(array $cart): void
    {
        $previous = cache()->get(self::CART_CACHE_KEY);

        if ($previous !== null && Arr::except($previous, 'updated_at') === $cart) {
            return;
        }

        cache()->forever(self::CART_CACHE_KEY, [...$cart, 'updated_at' => now()->toIso8601String()]);

        self::pushToDisplay();
    }

    public static function announceSale(string $code, float $total, float $paid, float $change): void
    {
        cache()->put(self::SALE_CACHE_KEY, [
            'code' => $code,
            'total' => $total,
            'paid' => $paid,
            'change' => $change,
            'at' => now()->timestamp,
        ], now()->addSeconds(self::THANK_YOU_SECONDS));

        self::pushToDisplay();
    }

    /**
     * Send what the display shows once the current request has finished, so a sale (announce + empty cart) reaches it
     * as one update showing its final state.
     */
    public static function pushToDisplay(): void
    {
        defer(function () {
            $display = app(self::class);

            app(CustomerDisplayScreen::class)->show($display->snapshot());
        }, 'customer-display-updated');
    }

    /**
     * What the display should show right now.
     *
     * @return array{state: 'idle'|'active'|'thanks', cart: ?array<string, mixed>, sale: ?array<string, mixed>}
     */
    public function snapshot(): array
    {
        $cart = cache()->get(self::CART_CACHE_KEY);
        $sale = cache()->get(self::SALE_CACHE_KEY);
        $hasItems = ! empty($cart['items']);

        $state = match (true) {
            $hasItems => 'active',
            $sale !== null => 'thanks',
            default => 'idle',
        };

        return ['state' => $state, 'cart' => $hasItems ? $cart : null, 'sale' => $sale];
    }

    /**
     * Folder the shop drops advertisement images/videos into.
     */
    public function advertisementsPath(): string
    {
        $path = storage_path('app/advertisements');

        File::ensureDirectoryExists($path);

        return $path;
    }

    /**
     * Slides for the advertising area: the shop's own ads, or featured products when there are none.
     *
     * @return Collection<int, array{type: 'image'|'video'|'product', src: string, title?: string, subtitle?: string}>
     */
    public function slides(): Collection
    {
        $screen = app(CustomerDisplayScreen::class);

        $advertisements = collect(File::files($this->advertisementsPath()))
            ->filter(fn ($file) => in_array(Str::lower($file->getExtension()), [...self::IMAGE_EXTENSIONS, ...self::VIDEO_EXTENSIONS]))
            ->sortBy(fn ($file) => $file->getFilename())
            ->map(fn ($file) => [
                'type' => in_array(Str::lower($file->getExtension()), self::VIDEO_EXTENSIONS) ? 'video' : 'image',
                'src' => $screen->advertisementUrl($file->getFilename()),
            ])
            ->values();

        if ($advertisements->isNotEmpty()) {
            return $advertisements;
        }

        return Product::active()
            ->whereNotNull('image_url')
            ->inRandomOrder()
            ->limit(8)
            ->get()
            ->map(fn (Product $product) => [
                'type' => 'product',
                'src' => $product->display_image,
                'title' => (string) $product->name,
                'subtitle' => numberFormat($product->net_sale_price, true),
            ]);
    }

    /**
     * Open the display (the PC till: its own movable window, filling a second monitor the first time).
     */
    public function openWindow(): void
    {
        app(CustomerDisplayScreen::class)->open();
    }
}
