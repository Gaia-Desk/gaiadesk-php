<?php

declare(strict_types=1);

/** Reads a required environment variable for the examples. */
function env(string $name): string
{
    $v = getenv($name);
    if (false === $v || '' === $v) {
        fwrite(\STDERR, "set $name\n");
        exit(2);
    }

    return $v;
}
