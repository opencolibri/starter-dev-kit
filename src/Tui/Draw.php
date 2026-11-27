<?php

declare(strict_types=1);

namespace OpenColibri\Starter\DevKit\Tui;

use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Ansi\TextWrapper;

/**
 * Drawing primitives of the dev-kit TUI: 256 colors, boxes, cards and columns, as lines of ANSI text.
 *
 * No background is ever painted: the TUI keeps the one of the terminal, light or dark.
 */
final class Draw
{
    public const int ACCENT = 141;
    public const int PINK = 211;
    public const int GREEN = 114;
    public const int YELLOW = 221;
    public const int RED = 203;
    public const int CYAN = 117;
    public const int GRAY = 246;
    public const int DIM = 242;
    public const int BORDER = 239;

    public static function fg(string $text, int $color): string
    {
        return "\e[38;5;{$color}m{$text}\e[39m";
    }

    public static function bold(string $text): string
    {
        return "\e[1m{$text}\e[22m";
    }

    public static function underline(string $text): string
    {
        return "\e[4m{$text}\e[24m";
    }

    public static function badge(string $text, int $background, int $foreground = 16): string
    {
        return "\e[48;5;{$background}m\e[38;5;{$foreground}m\e[1m {$text} \e[22m\e[39m\e[49m";
    }

    /**
     * The text cut or padded to exactly this width.
     */
    public static function fit(string $text, int $width): string
    {
        if ($width <= 0) {
            return '';
        }

        return AnsiUtils::truncateToWidth($text, $width, '…', true);
    }

    /**
     * The left text, then the right one at the end of the width.
     */
    public static function spread(string $left, string $right, int $width): string
    {
        $space = $width - AnsiUtils::visibleWidth($left) - AnsiUtils::visibleWidth($right);

        if ($space < 1) {
            return self::fit(self::fit($left, max(0, $width - AnsiUtils::visibleWidth($right) - 1)) . ' ' . $right, $width);
        }

        return $left . str_repeat(' ', $space) . $right;
    }

    /**
     * @return list<string>
     */
    public static function wrap(string $text, int $width, int $color = self::DIM, int $max = \PHP_INT_MAX): array
    {
        $lines = TextWrapper::wrapTextWithAnsi($text, max(1, $width));

        if (\count($lines) > $max) {
            $lines = \array_slice($lines, 0, $max);
            $lines[$max - 1] = self::fit(rtrim($lines[$max - 1]) . '…', $width);
        }

        return array_map(static fn(string $line): string => self::fg($line, $color), $lines);
    }

    /**
     * A rounded box around the lines, the inner width being the width minus the border and a space on each side.
     *
     * @param list<string> $lines
     *
     * @return list<string>
     */
    public static function box(array $lines, int $width, int $border = self::BORDER, bool $focused = false, ?int $height = null, bool $padded = true): array
    {
        $inner = $width - ($padded ? 4 : 2);

        if (null !== $height) {
            $lines = \array_slice(array_pad($lines, $height - 2, ''), 0, max(0, $height - 2));
        }

        // The focus is a heavy border, the background staying the one of the terminal.
        [$top, $side, $bottom] = $focused ? ['┏━┓', '┃', '┗━┛'] : ['╭─╮', '│', '╰─╯'];
        $edge = static fn(string $corners): string => self::fg(mb_substr($corners, 0, 1) . str_repeat(mb_substr($corners, 1, 1), max(0, $width - 2)) . mb_substr($corners, 2, 1), $border);
        $side = self::fg($side, $border);
        $space = $padded ? ' ' : '';
        $out = [$edge($top)];

        foreach ($lines as $line) {
            $out[] = $side . $space . self::fit($line, $inner) . $space . $side;
        }

        $out[] = $edge($bottom);

        return $out;
    }

    public static function rule(int $width, int $color = self::BORDER): string
    {
        return self::fg(str_repeat('─', max(0, $width)), $color);
    }

    /**
     * Blocks of lines side by side, each padded to its width.
     *
     * @param list<list<string>> $blocks
     * @param list<int>          $widths
     *
     * @return list<string>
     */
    public static function columns(array $blocks, array $widths, int $gap = 1): array
    {
        $height = max(array_map('count', $blocks) ?: [0]);
        $lines = [];

        for ($row = 0; $row < $height; ++$row) {
            $cells = [];

            foreach ($blocks as $index => $block) {
                $cells[] = self::fit($block[$row] ?? '', $widths[$index]);
            }

            $lines[] = implode(str_repeat(' ', $gap), $cells);
        }

        return $lines;
    }

    /**
     * Keys and what they do, as "key label · key label".
     *
     * @param array<string, string> $keys
     */
    public static function keys(array $keys): string
    {
        $parts = [];

        foreach ($keys as $key => $label) {
            $parts[] = self::bold(self::fg($key, self::ACCENT)) . ' ' . self::fg($label, self::GRAY);
        }

        return implode(self::fg(' · ', self::DIM), $parts);
    }
}
