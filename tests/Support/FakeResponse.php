<?php

declare(strict_types=1);

namespace GaiaDesk\Tests\Support;

/** A fake answer: its status, headers, and its body in the pieces it is written in. */
final class FakeResponse
{
    /**
     * @param array<string, string> $headers
     * @param list<string>          $chunks
     */
    public function __construct(public int $status, public array $headers = [], public array $chunks = [])
    {
    }

    public function body(): string
    {
        return implode('', $this->chunks);
    }
}
