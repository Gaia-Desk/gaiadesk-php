<?php

declare(strict_types=1);

namespace GaiaDesk\Stream;

/**
 * An incremental `text/event-stream` parser (the WHATWG rules: fields, comments,
 * blank-line dispatch). Text in, in any chunking (an event may be split anywhere, even
 * between `\r` and `\n`); events out. `:` lines are keep-alives.
 */
final class SseParser
{
    private string $buf = '';
    private string $event = '';
    /** @var list<string> */
    private array $data = [];

    /**
     * Feed some of the stream; the events it completed.
     *
     * @return list<SseEvent>
     */
    public function feed(string $text): array
    {
        $this->buf .= $text;
        $out = [];
        while (true) {
            if (1 !== preg_match('/\r\n|\r|\n/', $this->buf, $m, \PREG_OFFSET_CAPTURE)) {
                break;
            }
            [$sep, $at] = $m[0];
            // A trailing `\r` may be the first half of `\r\n`: wait for the next chunk.
            if ("\r" === $sep && $at === \strlen($this->buf) - 1) {
                break;
            }
            $line = substr($this->buf, 0, $at);
            $this->buf = (string) substr($this->buf, $at + \strlen($sep));
            $ev = $this->line($line);
            if (null !== $ev) {
                $out[] = $ev;
            }
        }

        return $out;
    }

    /**
     * The end of the stream: an event the server did not finish with a blank line is still delivered.
     *
     * @return list<SseEvent>
     */
    public function end(): array
    {
        $out = [];
        if ('' !== $this->buf) {
            $ev = $this->line(rtrim($this->buf, "\r"));
            $this->buf = '';
            if (null !== $ev) {
                $out[] = $ev;
            }
        }
        $last = $this->line('');
        if (null !== $last) {
            $out[] = $last;
        }

        return $out;
    }

    private function line(string $line): ?SseEvent
    {
        if ('' === $line) {
            if ([] === $this->data) {
                $this->event = '';

                return null;
            }
            $ev = new SseEvent('' !== $this->event ? $this->event : 'message', implode("\n", $this->data));
            $this->event = '';
            $this->data = [];

            return $ev;
        }
        if (str_starts_with($line, ':')) {
            return null; // a comment: keep-alive
        }
        $i = strpos($line, ':');
        $field = false === $i ? $line : substr($line, 0, $i);
        $value = false === $i ? '' : substr($line, $i + 1);
        if (str_starts_with($value, ' ')) {
            $value = substr($value, 1);
        }
        if ('event' === $field) {
            $this->event = $value;
        } elseif ('data' === $field) {
            $this->data[] = $value;
        }

        return null;
    }
}
