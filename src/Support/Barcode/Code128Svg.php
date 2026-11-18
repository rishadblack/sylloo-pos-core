<?php

namespace Rishadblack\SyllooPosCore\Support\Barcode;

/**
 * A code as a Code 128 barcode in SVG, with no barcode package and no `ext-gd` (the Android till's PHP has none).
 * Moved from the Android till's T0 (`app/Receipts/Code128Svg.php`) and given code set C.
 *
 * Code set B draws every printable ASCII character; a run of 4 or more digits is drawn in code set C (two digits in one
 * symbol), so a receipt number like `POS1234` stays short. Then the mod-103 check symbol and the stop pattern, bars as
 * SVG rectangles. A code with a character outside these sets (a control character, anything not ASCII) gives null:
 * no barcode is printed.
 */
final class Code128Svg
{
    /**
     * Bar and space widths of the 107 Code 128 symbols (0–102 data, 103–105 start A/B/C, 106 stop).
     *
     * @var list<string>
     */
    private const PATTERNS = [
        '212222', '222122', '222221', '121223', '121322', '131222', '122213', '122312', '132212', '221213',
        '221312', '231212', '112232', '122132', '122231', '113222', '123122', '123221', '223211', '221132',
        '221231', '213212', '223112', '312131', '311222', '321122', '321221', '312212', '322112', '322211',
        '212123', '212321', '232121', '111323', '131123', '131321', '112313', '132113', '132311', '211313',
        '231113', '231311', '112133', '112331', '132131', '113123', '113321', '133121', '313121', '211331',
        '231131', '213113', '213311', '213131', '311123', '311321', '331121', '312113', '312311', '332111',
        '314111', '221411', '431111', '111224', '111422', '121124', '121421', '141122', '141221', '112214',
        '112412', '122114', '122411', '142112', '142211', '241211', '221114', '413111', '241112', '134111',
        '111242', '121142', '121241', '114212', '124112', '124211', '411212', '421112', '421211', '212141',
        '214121', '412121', '111143', '111341', '131141', '114113', '114311', '411113', '411311', '113141',
        '114131', '311141', '411131', '211412', '211214', '211232', '2331112',
    ];

    public const START_B = 104;

    public const START_C = 105;

    /** In code set B: the next symbols are code set C. */
    public const CODE_C = 99;

    /** In code set C: the next symbols are code set B. */
    public const CODE_B = 100;

    public const STOP = 106;

    /** The shortest run of digits drawn in code set C. */
    private const DIGIT_RUN = 4;

    /**
     * The symbol values of a code: the start symbol, the data (with the code set changes), the check symbol and stop;
     * null when a character is in no code set drawn here.
     *
     * @return list<int>|null
     */
    public static function symbols(string $code): ?array
    {
        if ($code === '') {
            return null;
        }

        $characters = str_split($code);

        foreach ($characters as $character) {
            $ord = ord($character);

            if ($ord < 32 || $ord > 127) {
                return null;
            }
        }

        $data = [];
        // The code set in use; the first one chosen is the start symbol.
        $set = null;
        $start = null;
        $length = count($characters);
        $position = 0;

        while ($position < $length) {
            $run = self::digitRun($characters, $position);

            if ($run >= self::DIGIT_RUN) {
                // An odd run: its first digit stays in code set B, the rest goes in pairs.
                // (A run always follows a character of code set B or opens the code, never another run.)
                if ($run % 2 === 1) {
                    $set = 'B';
                    $start ??= self::START_B;
                    $data[] = ord($characters[$position]) - 32;
                    $position++;
                    $run--;
                }

                if ($set === 'B') {
                    $data[] = self::CODE_C;
                }

                $set = 'C';
                $start ??= self::START_C;

                for ($end = $position + $run; $position < $end; $position += 2) {
                    $data[] = (int) ($characters[$position].$characters[$position + 1]);
                }

                continue;
            }

            if ($set === 'C') {
                $data[] = self::CODE_B;
            }

            $set = 'B';
            $start ??= self::START_B;
            $data[] = ord($characters[$position]) - 32;
            $position++;
        }

        $sum = $start;

        foreach ($data as $index => $value) {
            $sum += $value * ($index + 1);
        }

        return [$start, ...$data, $sum % 103, self::STOP];
    }

    /**
     * The barcode as an SVG document: `$moduleWidth` is the width of the narrowest bar, `$height` the bars' height.
     */
    public static function svg(string $code, float $moduleWidth = 1.4, float $height = 40, string $color = 'black'): ?string
    {
        $symbols = self::symbols($code);

        if ($symbols === null) {
            return null;
        }

        $x = 0.0;
        $bars = '';

        foreach ($symbols as $symbol) {
            foreach (str_split(self::PATTERNS[$symbol]) as $index => $modules) {
                $width = (int) $modules * $moduleWidth;

                if ($index % 2 === 0) {
                    $bars .= sprintf('<rect x="%s" y="0" width="%s" height="%s" fill="%s"/>', self::number($x), self::number($width), self::number($height), htmlspecialchars($color, ENT_QUOTES));
                }

                $x += $width;
            }
        }

        return sprintf(
            '<?xml version="1.0" standalone="no"?><svg width="%1$s" height="%2$s" viewBox="0 0 %1$s %2$s" version="1.1" xmlns="http://www.w3.org/2000/svg"><g>%3$s</g></svg>',
            self::number($x),
            self::number($height),
            $bars,
        );
    }

    /**
     * How many digits follow one another from a position.
     *
     * @param  list<string>  $characters
     */
    private static function digitRun(array $characters, int $from): int
    {
        $run = 0;

        while (isset($characters[$from + $run]) && ctype_digit($characters[$from + $run])) {
            $run++;
        }

        return $run;
    }

    private static function number(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }
}
