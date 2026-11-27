<?php

declare(strict_types=1);

namespace OpenColibri\Starter\DevKit\Tui;

/**
 * Draws a board: a column per group, its cards or rows scrolled to keep the selected one in sight.
 *
 * @phpstan-import-type Card from Boards
 * @phpstan-import-type Column from Boards
 */
final class BoardView
{
    /** @var array<string, int> the first card or line shown of each column, by "view.column" */
    private array $scroll = [];

    /**
     * @param list<Column>   $board
     * @param array{int,int} $cursor the selected column and row
     *
     * @return list<string>
     */
    public function draw(array $board, string $view, array $cursor, int $width, int $height): array
    {
        if ([] === $board) {
            return Draw::box([Draw::fg('Nothing to pick here.', Draw::DIM)], $width, height: $height);
        }

        $count = \count($board);
        $gap = 1;
        $columnWidth = intdiv($width - $gap * ($count - 1), $count);
        [$focusedColumn, $focusedRow] = $cursor;
        $blocks = [];
        $widths = [];

        foreach ($board as $index => $column) {
            $columnWidth = $index === $count - 1 ? $width - ($columnWidth + $gap) * ($count - 1) : $columnWidth;
            $inner = $columnWidth - 4;
            $focused = $index === $focusedColumn;
            $lines = [
                Draw::spread(Draw::fg($column['icon'], $focused ? Draw::ACCENT : Draw::GRAY) . ' ' . Draw::bold(mb_strtoupper($column['title'])), Draw::fg((string) \count($column['cards']), Draw::GRAY), $inner),
                Draw::rule($inner),
            ];

            $scrollKey = $view . '.' . $index;
            $selected = $focused ? $focusedRow : null;
            $available = $height - 2 - \count($lines);
            $lines = [...$lines, ...(($column['list'] ?? false)
                ? $this->rows($column['cards'], $scrollKey, $selected, $inner, $available)
                : $this->cards($column['cards'], $view, $scrollKey, $selected, $inner, $available))];
            $blocks[] = Draw::box($lines, $columnWidth, $focused ? Draw::ACCENT : Draw::BORDER, height: $height);
            $widths[] = $columnWidth;
        }

        return Draw::columns($blocks, $widths, $gap);
    }

    /**
     * The cards that fit in the height, scrolled to show the selected one.
     *
     * @param list<Card> $cards
     *
     * @return list<string>
     */
    private function cards(array $cards, string $view, string $scrollKey, ?int $selected, int $width, int $height): array
    {
        if ([] === $cards) {
            return [...Draw::wrap('Nothing here yet.', $width, Draw::GRAY), '', ...Draw::wrap('catalog' === $view ? 'Build one with castor sylius:dataset:build, or from the Catalog page of the dev-kit admin.' : '', $width)];
        }

        $rendered = [];

        foreach ($cards as $index => $card) {
            $isSelected = $index === $selected;
            $rendered[] = Draw::box(isset($card['lines']) ? ($card['lines'])($width - 4) : [], $width, $isSelected ? Draw::ACCENT : ($card['border'] ?? Draw::BORDER), $isSelected);
        }

        $first = $this->scroll[$scrollKey] ?? 0;

        if (null !== $selected) {
            $first = min($first, $selected);

            // Moves down until the selected card fits, a line kept for each "more" marker.
            while ($first < $selected && self::span($rendered, $first, $selected) + ($first > 0 ? 1 : 0) + 1 > $height) {
                ++$first;
            }
        }

        $first = max(0, min($first, \count($rendered) - 1));
        $this->scroll[$scrollKey] = $first;
        $lines = $first > 0 ? [Draw::fit(str_repeat(' ', max(0, intdiv($width - 10, 2))) . Draw::fg(\sprintf('↑ %d more', $first), Draw::GRAY), $width)] : [];
        $last = $first - 1;

        for ($index = $first; $index < \count($rendered); ++$index) {
            $more = $index < \count($rendered) - 1 ? 1 : 0;

            if (\count($lines) + \count($rendered[$index]) + $more > $height && $index > $first) {
                break;
            }

            $lines = [...$lines, ...$rendered[$index]];
            $last = $index;
        }

        if ($last < \count($rendered) - 1) {
            $lines = \array_slice($lines, 0, max(0, $height - 1));
            $lines[] = str_repeat(' ', max(0, intdiv($width - 10, 2))) . Draw::fg(\sprintf('↓ %d more', \count($rendered) - 1 - $last), Draw::GRAY);
        }

        return $lines;
    }

    /**
     * The rows of a list, under the title of their section, scrolled to show the selected one.
     *
     * @param list<Card> $rows
     *
     * @return list<string>
     */
    private function rows(array $rows, string $scrollKey, ?int $selected, int $width, int $height): array
    {
        $lines = [];
        $selectedLine = 0;
        $section = null;

        foreach ($rows as $index => $row) {
            if (($row['section'] ?? '') !== $section) {
                $section = $row['section'] ?? '';
                $count = \count(array_filter($rows, static fn(array $item): bool => ($item['section'] ?? '') === $section));

                if ([] !== $lines) {
                    $lines[] = '';
                }

                $lines[] = Draw::bold(Draw::fg($section, Draw::ACCENT)) . Draw::fg('  ' . $count, Draw::GRAY);
            }

            $line = Draw::fit(isset($row['row']) ? ($row['row'])($width - 2) : $row['value'], $width - 2);

            if ($index === $selected) {
                $selectedLine = \count($lines);
                $lines[] = Draw::fg('▌ ', Draw::ACCENT) . $line;
            } else {
                $lines[] = '  ' . $line;
            }
        }

        $first = $this->scroll[$scrollKey] ?? 0;

        if (null !== $selected) {
            $first = max(min($first, $selectedLine - 2), $selectedLine - $height + 2, 0);
        }

        $first = min($first, max(0, \count($lines) - $height));
        $this->scroll[$scrollKey] = $first;
        $window = \array_slice($lines, $first, $height);

        if ($first + $height < \count($lines)) {
            $window[$height - 1] = Draw::fg(\sprintf('  ↓ %d more lines', \count($lines) - $first - $height + 1), Draw::GRAY);
        }

        return $window;
    }

    /**
     * @param list<list<string>> $rendered
     */
    private static function span(array $rendered, int $from, int $to): int
    {
        $lines = 0;

        for ($index = $from; $index <= $to; ++$index) {
            $lines += \count($rendered[$index]);
        }

        return $lines;
    }
}
