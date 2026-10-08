<?php

declare(strict_types=1);

namespace GaiaDesk\Stream;

/** One server-sent event: its `event:` name (default `message`) and its `data:` lines joined by `\n`. */
final class SseEvent
{
    public function __construct(public readonly string $event, public readonly string $data)
    {
    }
}
