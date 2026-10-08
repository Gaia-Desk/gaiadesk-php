<?php

declare(strict_types=1);

namespace GaiaDesk\Stream;

/** Some output of a streamed command or followed job: which stream, and its bytes. */
final class Chunk
{
    /**
     * @param 'stdout'|'stderr' $stream
     */
    public function __construct(public readonly string $stream, public readonly string $data)
    {
    }
}
