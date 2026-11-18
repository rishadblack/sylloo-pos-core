<?php

namespace Rishadblack\SyllooPosCore\Support;

use Rishadblack\SyllooPosCore\Facades\Settings;
use Throwable;

/**
 * A terminal activated with a Practice code sells into the business's Practice copy: every screen and every
 * receipt says so, so a practice receipt is never taken for a real sale. The mode comes from the activation.
 */
final class PracticeMode
{
    /**
     * The text printed across every practice receipt.
     */
    public const RECEIPT_TEXT = 'PRACTICE — NOT A REAL SALE';

    /**
     * Whether the terminal is activated in Practice mode. An unreadable setting counts as Live, so a page never
     * breaks over it.
     */
    public static function isOn(): bool
    {
        try {
            return Settings::get('license_mode') === 'practice';
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * The receipt HTML with the practice mark at its top and bottom (works for any template).
     */
    public static function markReceipt(string $html): string
    {
        $mark = '<div class="receipt-practice-mark" style="margin:2mm 0;padding:1.5mm 0;border:1px dashed #000;'
            .'text-align:center;font-weight:700;font-size:12px;letter-spacing:.5px;">'.e(self::RECEIPT_TEXT).'</div>';

        $html = preg_match('/<body[^>]*>/i', $html)
            ? preg_replace('/<body[^>]*>/i', '$0'.$mark, $html, 1)
            : $mark.$html;

        return preg_match('/<\/body>/i', $html)
            ? preg_replace('/<\/body>/i', $mark.'</body>', $html, 1)
            : $html.$mark;
    }
}
