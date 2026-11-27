<?php

declare(strict_types=1);

namespace OpenColibri\Starter\DevKit\Tui;

/**
 * Draws the overview: a card per setting, with what the shop runs now, what the manifest changes, and what Enter does.
 *
 * @phpstan-type OverviewCard array{key: string, title: string, action: 'edit'|'theme'|'catalog'|'plugins'|'save', hint: string}
 */
final class OverviewView
{
    /**
     * The cards, and what Enter does on each: edit the value in place, open the view that picks it, or save.
     *
     * @var list<OverviewCard>
     */
    public const array CARDS = [
        ['key' => 'name', 'title' => 'Shop name', 'action' => 'edit', 'hint' => 'rename the shop'],
        ['key' => 'theme', 'title' => 'Theme', 'action' => 'theme', 'hint' => 'choose a theme'],
        ['key' => 'header', 'title' => 'Header', 'action' => 'theme', 'hint' => 'place the menu and the search'],
        ['key' => 'dataset', 'title' => 'Catalog', 'action' => 'catalog', 'hint' => 'choose a catalog'],
        ['key' => 'locales', 'title' => 'Languages', 'action' => 'edit', 'hint' => 'edit the list'],
        ['key' => 'currencies', 'title' => 'Currencies', 'action' => 'edit', 'hint' => 'edit the list, base first'],
        ['key' => 'plugins', 'title' => 'Plugins', 'action' => 'plugins', 'hint' => 'add or remove plugins'],
        ['key' => 'file', 'title' => 'Manifest', 'action' => 'save', 'hint' => 'save'],
    ];

    /**
     * How many cards make a row at this width.
     */
    public static function columns(int $width): int
    {
        return $width >= 160 ? 4 : ($width >= 90 ? 3 : 2);
    }

    /**
     * @param array{key: string, buffer: string, cursor: int}|null $editing the value typed in a card, if any
     *
     * @return list<string>
     */
    public static function draw(ShopState $state, string $file, bool $dirty, int $selected, ?array $editing, int $width, int $height): array
    {
        $columns = self::columns($width);
        $rows = (int) ceil(\count(self::CARDS) / $columns);
        $gap = 1;
        $cardWidth = intdiv($width - $gap * ($columns - 1), $columns);
        $cardHeight = max(7, min(11, intdiv($height - 3, $rows) - 1));
        $lines = [
            Draw::fg(\sprintf('What the shop runs now, and what %s changes.', $file), Draw::GRAY),
            Draw::fg('Move to a card and press Enter to change it: the plan shows what dev-kit:apply will run.', Draw::DIM),
            '',
        ];

        foreach (array_chunk(self::CARDS, $columns, true) as $chunk) {
            $blocks = [];
            $widths = [];

            foreach ($chunk as $index => $card) {
                $content = 'file' === $card['key'] ? self::manifest($state, $file, $dirty) : $state->describe($card['key']);
                $blocks[] = self::card($card, $content, $index === $selected, $editing, $cardWidth, $cardHeight);
                $widths[] = $cardWidth;
            }

            $lines = [...$lines, ...Draw::columns($blocks, $widths, $gap), ''];
        }

        return \array_slice(array_pad($lines, $height, ''), 0, $height);
    }

    /**
     * The manifest card: the file, saved or not, and what applying it runs.
     *
     * @return array{string, null, string, string}
     */
    private static function manifest(ShopState $state, string $file, bool $dirty): array
    {
        $plan = $state->plan()[1];
        $detail = null === $plan || $plan->isEmpty() ? 'Nothing to apply: the shop matches it' : \sprintf('dev-kit:apply runs %d %s', \count($plan->steps), 1 === \count($plan->steps) ? 'step' : 'steps');

        return [$file, null, $detail, $dirty ? Draw::fg('● unsaved', Draw::YELLOW) : Draw::fg('✔ saved', Draw::GREEN)];
    }

    /**
     * @param OverviewCard                                         $card
     * @param array{0: string, 1: ?string, 2: string, 3?: string}  $content now, wanted, detail, and the state to show instead of the computed one
     * @param array{key: string, buffer: string, cursor: int}|null $editing
     *
     * @return list<string>
     */
    private static function card(array $card, array $content, bool $selected, ?array $editing, int $width, int $height): array
    {
        [$now, $wanted, $detail] = $content;
        $inner = $width - 4;
        $changed = null !== $wanted && $wanted !== $now;
        $edited = null !== $editing && $editing['key'] === $card['key'] ? $editing : null;

        $state = $content[3] ?? match (true) {
            null !== $edited => Draw::fg('✎ editing', Draw::ACCENT),
            null === $wanted => Draw::fg('— not managed', Draw::DIM),
            $changed => Draw::bold(Draw::fg('● changes', Draw::YELLOW)),
            default => Draw::fg('✔ as is', Draw::GREEN),
        };

        $title = mb_strtoupper($card['title']);
        $lines = [Draw::spread(Draw::bold($selected ? Draw::fg('▸ ' . $title, Draw::ACCENT) : $title), $state, $inner)];

        if (null !== $edited) {
            ['buffer' => $buffer, 'cursor' => $cursor] = $edited;
            $lines[] = Draw::fg('now  ', Draw::DIM) . Draw::fg($now, Draw::GRAY);
            $lines[] = Draw::fg('new  ', Draw::ACCENT) . mb_substr($buffer, 0, $cursor) . "\e[7m" . (mb_substr($buffer, $cursor, 1) ?: ' ') . "\e[27m" . mb_substr($buffer, $cursor + 1);
        } elseif ($changed) {
            $lines[] = Draw::fg('now  ', Draw::DIM) . Draw::fg($now, Draw::GRAY);
            $lines[] = Draw::fg('new  ', Draw::ACCENT) . Draw::bold(Draw::fg($wanted, Draw::ACCENT));
        } else {
            $lines[] = Draw::bold($now);
            $lines[] = null === $wanted && 'file' !== $card['key'] ? Draw::fg('dev-kit:apply leaves it as it is', Draw::DIM) : '';
        }

        // The detail takes the lines left between the values and the keys, a blank line around it.
        $lines = [...$lines, '', ...Draw::wrap($detail, $inner, max: max(1, $height - 2 - \count($lines) - 3))];
        $lines = \array_slice(array_pad($lines, $height - 3, ''), 0, $height - 3);
        $lines[] = null !== $edited
            ? Draw::keys(['enter' => 'keep', 'esc' => 'cancel'])
            : Draw::keys('file' === $card['key'] ? ['s' => 'save', 'a' => 'save & apply'] : ['enter' => $card['hint']]);

        return Draw::box($lines, $width, $selected ? Draw::ACCENT : ($changed ? Draw::YELLOW : Draw::BORDER), $selected, $height);
    }
}
