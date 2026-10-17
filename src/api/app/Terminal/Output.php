<?php

namespace App\Terminal;

/**
 * What one command answers: lines to print (each a list of [text, tone]
 * segments; tones are the console's t-* classes), and maybe a question to
 * ask next, a pane to open, a page to go to, or the screen to clear.
 */
final class Output
{
    /** @var list<list<array{0: string, 1: string}>> */
    public array $lines = [];

    /**
     * A question the command waits on: a pick from options, or yes/no.
     *
     * @var array{type: string, question: string, options?: list<array{value: string, label: string, hint?: string, tone?: string}>, default?: string, state: array<string, mixed>}|null
     */
    public ?array $ask = null;

    /** @var array{kind: string, arg: ?string}|null */
    public ?array $open = null;

    public ?string $visit = null;

    public bool $clear = false;

    /**
     * @param  array{0: string, 1: string}|string  ...$segments  [text, tone] pairs, or plain text
     */
    public function line(array|string ...$segments): self
    {
        $this->lines[] = array_map(fn ($segment) => is_string($segment) ? [$segment, ''] : $segment, $segments);

        return $this;
    }

    public function blank(): self
    {
        $this->lines[] = [];

        return $this;
    }

    public function error(string $message): self
    {
        return $this->line(['✕ ', 't-err'], [$message, 't-err']);
    }

    /**
     * Columns padded to their widest cell.
     *
     * @param  list<string>  $head
     * @param  list<list<array{0: string, 1: string}|string>>  $rows
     */
    public function table(array $head, array $rows): self
    {
        $cells = fn (array $row) => array_map(fn ($cell) => is_string($cell) ? [$cell, ''] : $cell, $row);
        $widths = array_map(fn (string $title) => mb_strlen($title), $head);

        foreach ($rows as $row) {
            foreach ($cells($row) as $i => [$text]) {
                $widths[$i] = max($widths[$i] ?? 0, mb_strlen($text));
            }
        }

        $this->line([' '.implode('  ', array_map(fn (string $title, int $i) => self::pad($title, $widths[$i]), $head, array_keys($head))), 't-mute']);

        foreach ($rows as $row) {
            $segments = [[' ', '']];
            foreach ($cells($row) as $i => [$text, $tone]) {
                $segments[] = [self::pad($text, $widths[$i] ?? 0), $tone];
                $segments[] = ['  ', ''];
            }
            $this->lines[] = $segments;
        }

        return $this;
    }

    /**
     * A box drawn with box-drawing characters, a title in its top border. It
     * stays narrow enough for a split pane: rows longer than that are cut.
     *
     * @param  list<list<array{0: string, 1: string}>>  $rows
     */
    public function box(string $title, array $rows, int $max = 58): self
    {
        $rows = array_map(fn (array $row) => self::crop($row, $max), $rows);
        $width = max(mb_strlen($title) + 4, ...array_map(fn (array $row) => array_sum(array_map(fn ($segment) => mb_strlen($segment[0]), $row)), $rows ?: [[]]));
        $this->line(['┌─ '.$title.' '.str_repeat('─', max(0, $width - mb_strlen($title) - 2)).'┐', 't-mute']);

        foreach ($rows as $row) {
            $used = array_sum(array_map(fn ($segment) => mb_strlen($segment[0]), $row));
            $this->lines[] = [['│ ', 't-mute'], ...$row, [str_repeat(' ', max(0, $width - $used)).' │', 't-mute']];
        }

        return $this->line(['└'.str_repeat('─', $width + 2).'┘', 't-mute']);
    }

    /**
     * @param  list<array{0: string, 1: string}>  $row
     * @return list<array{0: string, 1: string}>
     */
    private static function crop(array $row, int $max): array
    {
        $left = $max;
        $cropped = [];

        foreach ($row as [$text, $tone]) {
            if ($left <= 0) {
                break;
            }
            $cropped[] = [mb_strlen($text) > $left ? mb_substr($text, 0, $left - 1).'…' : $text, $tone];
            $left -= mb_strlen($text);
        }

        return $cropped;
    }

    private static function pad(string $text, int $width): string
    {
        return $text.str_repeat(' ', max(0, $width - mb_strlen($text)));
    }
}
