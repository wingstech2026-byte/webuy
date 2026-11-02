<?php

namespace App\Support;

/**
 * A Code 128 (set B) barcode as SVG, for parcel labels. Any USB barcode scanner reads it and "types" the
 * order number (then Enter) into the station page, like the partner typing it by hand.
 */
final class Code128
{
    /** Bar and space widths of every symbol (values 0–106; 104 = start B, 106 = stop). */
    public const PATTERNS = [
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

    private const START_B = 104;

    private const STOP = 106;

    /** The symbol values for the text, with start, checksum and stop. @return list<int> */
    public static function values(string $text): array
    {
        $values = [self::START_B];
        $sum = self::START_B;

        foreach (str_split($text) as $i => $char) {
            $value = ord($char) - 32;

            if ($value < 0 || $value > 94) {
                throw new \InvalidArgumentException('Code 128 B only takes plain letters, numbers and signs.');
            }

            $values[] = $value;
            $sum += ($i + 1) * $value;
        }

        return [...$values, $sum % 103, self::STOP];
    }

    /** An SVG barcode (black bars on white), with quiet zones on both sides. */
    public static function svg(string $text, int $height = 60, int $module = 2): string
    {
        $x = 10 * $module; // quiet zone
        $bars = '';

        foreach (self::values($text) as $value) {
            foreach (str_split(self::PATTERNS[$value]) as $i => $width) {
                $w = (int) $width * $module;

                if ($i % 2 === 0) { // even positions are bars, odd are spaces
                    $bars .= '<rect x="'.$x.'" y="0" width="'.$w.'" height="'.$height.'"/>';
                }

                $x += $w;
            }
        }

        $total = $x + 10 * $module;

        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 '.$total.' '.$height.'" width="'.$total.'" height="'.$height.'" role="img" aria-label="Barcode '.e($text).'">'
            .'<rect width="100%" height="100%" fill="#fff"/><g fill="#000">'.$bars.'</g></svg>';
    }
}
