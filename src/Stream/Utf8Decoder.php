<?php

declare(strict_types=1);

namespace GaiaDesk\Stream;

/**
 * Bytes to UTF-8 text in pieces: a character split between two pieces is held back
 * until it is whole. Invalid sequences become U+FFFD.
 */
final class Utf8Decoder
{
    private string $held = '';

    /** The text of the next piece (without a trailing partial character). */
    public function decode(string $bytes, bool $final = false): string
    {
        $s = $this->held.$bytes;
        $this->held = '';
        if (!$final) {
            $cut = self::completeLength($s);
            $this->held = (string) substr($s, $cut);
            $s = substr($s, 0, $cut);
        }
        if ('' === $s) {
            return '';
        }

        return self::scrub($s);
    }

    /** Valid UTF-8, each invalid sequence replaced by U+FFFD. */
    public static function scrub(string $s): string
    {
        if (mb_check_encoding($s, 'UTF-8')) {
            return $s;
        }
        $prev = mb_substitute_character();
        mb_substitute_character(0xFFFD);
        try {
            return mb_scrub($s, 'UTF-8');
        } finally {
            mb_substitute_character($prev);
        }
    }

    /** The length of $s without a trailing incomplete (but so far valid) UTF-8 sequence. */
    private static function completeLength(string $s): int
    {
        $n = \strlen($s);
        for ($back = 1; $back <= 3 && $back <= $n; ++$back) {
            $b = \ord($s[$n - $back]);
            if (0x80 === ($b & 0xC0)) {
                continue; // a continuation byte: look further back
            }
            $need = match (true) {
                0xC0 === ($b & 0xE0) => 2,
                0xE0 === ($b & 0xF0) => 3,
                0xF0 === ($b & 0xF8) => 4,
                default => 1,
            };

            return $need > $back ? $n - $back : $n;
        }

        return $n;
    }
}
