<?php

namespace App\Platform\Ssh;

/**
 * Turns process output chunks into whole lines per stream. A carriage return
 * ends a line too, so progress bars (docker pull, rsync) come out one state at a time.
 */
final class LineBuffer
{
    /** The longest unfinished line kept while waiting for its end. */
    public const MAX_PARTIAL = 65536;

    /** @var array<string, string> */
    private array $partial = ['out' => '', 'err' => ''];

    /**
     * @return list<array{0: string, 1: string}> [stream, line] pairs
     */
    public function push(string $type, string $chunk): array
    {
        $stream = $type === 'err' ? 'err' : 'out';
        $parts = preg_split('/\r\n|\n|\r/', $this->partial[$stream].$chunk);
        $this->partial[$stream] = (string) array_pop($parts);

        // A stream that never ends its line (a minified blob, binary) would
        // otherwise grow here without bound: past the cap it comes out as a line.
        if (strlen($this->partial[$stream]) > self::MAX_PARTIAL) {
            $parts[] = $this->partial[$stream];
            $this->partial[$stream] = '';
        }

        return array_map(fn (string $line) => [$stream, $line], $parts);
    }

    /**
     * @return list<array{0: string, 1: string}>
     */
    public function flush(): array
    {
        $lines = [];

        foreach ($this->partial as $stream => $rest) {
            if ($rest !== '') {
                $lines[] = [$stream, $rest];
            }
            $this->partial[$stream] = '';
        }

        return $lines;
    }
}
