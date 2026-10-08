<?php

declare(strict_types=1);

namespace GaiaDesk\Internal;

/**
 * JSON as the API speaks it: objects are PHP arrays when decoded; when encoded, an
 * empty map must be `{}` (pass `new \stdClass()` or an `object`), never `[]`.
 *
 * @internal
 */
final class Json
{
    /** Like JavaScript's JSON.stringify: no escaped slashes or Unicode; invalid UTF-8 becomes U+FFFD. */
    public static function encode(mixed $value): string
    {
        return json_encode($value, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_INVALID_UTF8_SUBSTITUTE | \JSON_PRESERVE_ZERO_FRACTION | \JSON_THROW_ON_ERROR);
    }

    /**
     * The JSON value of a text, or $default when it is not JSON. Objects are arrays.
     */
    public static function decode(string $text, mixed $default = null): mixed
    {
        if ('' === trim($text)) {
            return $default;
        }
        try {
            return json_decode($text, true, 512, \JSON_THROW_ON_ERROR | \JSON_BIGINT_AS_STRING);
        } catch (\JsonException) {
            return $default;
        }
    }

    /** Is it a JSON object (a non-list array, or an empty one)? */
    public static function isObject(mixed $v): bool
    {
        return \is_array($v) && ([] === $v || !array_is_list($v));
    }

    /**
     * A map as a JSON object, `{}` when empty.
     *
     * @param array<string, mixed> $map
     */
    public static function object(array $map): object
    {
        return (object) $map;
    }
}
