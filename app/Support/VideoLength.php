<?php

namespace App\Support;

/**
 * The length of an MP4 or MOV video, read from its "mvhd" header (no ffmpeg needed). Phones save both
 * formats in the same box structure: size (4 bytes) + type (4 bytes), boxes inside boxes.
 */
final class VideoLength
{
    /** Seconds, or null when the file is not a readable MP4/MOV. */
    public static function seconds(string $path): ?float
    {
        $file = @fopen($path, 'rb');

        if ($file === false) {
            return null;
        }

        try {
            $size = filesize($path);
            $moov = self::find($file, 0, $size, 'moov');
            $mvhd = $moov ? self::find($file, $moov[0], $moov[1], 'mvhd') : null;

            if (! $mvhd) {
                return null;
            }

            fseek($file, $mvhd[0]);
            $data = fread($file, 32);

            if (strlen($data) < 32) {
                return null;
            }

            // Version 0: 32-bit times; version 1: 64-bit times.
            if (ord($data[0]) === 1) {
                $timescale = unpack('N', substr($data, 20, 4))[1];
                $duration = unpack('J', substr($data, 24, 8))[1];
            } else {
                $timescale = unpack('N', substr($data, 12, 4))[1];
                $duration = unpack('N', substr($data, 16, 4))[1];
            }

            return $timescale > 0 ? $duration / $timescale : null;
        } finally {
            fclose($file);
        }
    }

    /**
     * The content (start, end) of the first box of $type between $start and $end.
     *
     * @param  resource  $file
     * @return ?array{0: int, 1: int}
     */
    private static function find($file, int $start, int $end, string $type): ?array
    {
        $position = $start;

        while ($position + 8 <= $end) {
            fseek($file, $position);
            $header = fread($file, 8);

            if (strlen($header) < 8) {
                return null;
            }

            $boxSize = unpack('N', substr($header, 0, 4))[1];
            $headerSize = 8;

            if ($boxSize === 1) { // 64-bit size follows
                $boxSize = unpack('J', fread($file, 8))[1];
                $headerSize = 16;
            } elseif ($boxSize === 0) { // box runs to the end
                $boxSize = $end - $position;
            }

            if ($boxSize < $headerSize) {
                return null;
            }

            if (substr($header, 4, 4) === $type) {
                return [$position + $headerSize, min($end, $position + $boxSize)];
            }

            $position += $boxSize;
        }

        return null;
    }
}
