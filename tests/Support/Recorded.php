<?php

declare(strict_types=1);

namespace GaiaDesk\Tests\Support;

/** One request as the fake API saw it. */
final class Recorded
{
    /**
     * @param array<string, string> $query
     * @param array<string, string> $headers lowercased names
     */
    public function __construct(public string $method, public string $path, public array $query, public array $headers, public string $body)
    {
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    /** @return array<string, mixed> */
    public function json(): array
    {
        $v = json_decode($this->body, true);

        return \is_array($v) ? $v : [];
    }

    /** Everything the server could read, as one string. */
    public function raw(): string
    {
        return json_encode([$this->method, $this->path, $this->query, $this->headers, $this->body], \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_INVALID_UTF8_SUBSTITUTE) ?: '';
    }
}
