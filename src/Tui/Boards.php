<?php

declare(strict_types=1);

namespace OpenColibri\Starter\DevKit\Tui;

use OpenColibri\Starter\DevKit\Manifest;

/**
 * The boards of the TUI: the themes by category, the catalogs (Sylius, ready-made, yours) and the plugins by category,
 * each card or row drawn at the width it is given.
 *
 * @phpstan-type Card array{value: string, lines?: \Closure(int): list<string>, border?: int, section?: string, row?: \Closure(int): string}
 * @phpstan-type Column array{title: string, icon: string, cards: list<Card>, list?: bool}
 */
final readonly class Boards
{
    /** From this width, the plugins make two lists. */
    public const int TWO_LISTS_WIDTH = 150;

    public function __construct(
        private ShopState $state,
    ) {}

    /**
     * @return list<Column>
     */
    public function for(string $view, int $width): array
    {
        return match ($view) {
            'theme' => $this->themes(),
            'catalog' => $this->catalogs(),
            'plugins' => $this->plugins($width >= self::TWO_LISTS_WIDTH),
            default => [],
        };
    }

    /**
     * The column and row of the card of a value, or of the first card.
     *
     * @param list<Column> $board
     *
     * @return array{int, int}
     */
    public static function locate(array $board, ?string $value): array
    {
        foreach ($board as $column => $items) {
            foreach ($items['cards'] as $row => $card) {
                if ($card['value'] === $value) {
                    return [$column, $row];
                }
            }
        }

        return [0, 0];
    }

    /**
     * @return list<Column>
     */
    public function themes(): array
    {
        $columns = [];
        $default = ['name' => Manifest::DEFAULT_THEME, 'menu' => null, 'search' => null, 'category' => null];
        $current = $this->state->current->theme;
        $wanted = $this->state->wanted('theme');

        foreach ([$default, ...$this->state->catalog['themes']] as $theme) {
            $category = $theme['category']['label'] ?? null;
            $key = $category ?? array_key_first($columns) ?? 'General';
            $columns[$key] ??= ['title' => $key, 'icon' => '◆', 'cards' => []];
            $name = (string) $theme['name'];
            $columns[$key]['cards'][] = [
                'value' => $name,
                'border' => $name === $wanted ? Draw::GREEN : Draw::BORDER,
                'lines' => static fn(int $width): array => [
                    Draw::spread(Draw::bold(Draw::fg($name, Draw::PINK)), self::status($current === $name, $wanted === $name, $width), $width),
                    ...(null === $theme['menu'] ? [Draw::fg('The Sylius look', Draw::DIM)] : ($width < 26
                        ? [Draw::fg('menu   ' . $theme['menu'], Draw::DIM), Draw::fg('search ' . $theme['search'], Draw::DIM)]
                        : [Draw::fg(\sprintf('menu %s · search %s', $theme['menu'], $theme['search']), Draw::DIM)])),
                ],
            ];
        }

        // The default theme has no category: it opens the first column.
        if (isset($columns['General']) && 1 === \count($columns['General']['cards']) && \count($columns) > 1) {
            $first = array_key_first(array_diff_key($columns, ['General' => true]));
            array_unshift($columns[$first]['cards'], $columns['General']['cards'][0]);
            unset($columns['General']);
        }

        return array_values($columns);
    }

    /**
     * @return list<Column>
     */
    public function catalogs(): array
    {
        $columns = [
            'sylius' => ['title' => 'Sylius', 'icon' => '○', 'cards' => []],
            'built-in' => ['title' => 'Ready-made', 'icon' => '◐', 'cards' => []],
            'yours' => ['title' => 'Yours', 'icon' => '●', 'cards' => []],
        ];
        $sylius = ['slug' => Manifest::SYLIUS_FIXTURES, 'name' => 'Sylius demo', 'description' => 'The fixtures of Sylius: clothes, caps and posters', 'productCount' => null, 'collectionCount' => null, 'builtIn' => true];
        $current = $this->state->current->dataset;
        $wanted = $this->state->wanted('dataset');

        foreach ([$sylius, ...$this->state->catalog['datasets']] as $dataset) {
            $slug = (string) $dataset['slug'];
            $column = Manifest::SYLIUS_FIXTURES === $slug ? 'sylius' : ($dataset['builtIn'] ? 'built-in' : 'yours');
            $columns[$column]['cards'][] = [
                'value' => $slug,
                'border' => $slug === $wanted ? Draw::GREEN : Draw::BORDER,
                'lines' => static fn(int $width): array => [
                    Draw::spread(Draw::bold(Draw::fg((string) $dataset['name'], Draw::PINK)), self::status($current === $slug, $wanted === $slug, $width), $width),
                    Draw::fg(null === $dataset['productCount'] ? 'Clothes, the Sylius classics' : \sprintf('%d products · %d collections', $dataset['productCount'], $dataset['collectionCount']), Draw::GRAY),
                    ...Draw::wrap((string) ($dataset['description'] ?? ''), $width, max: 2),
                ],
            ];
        }

        return array_values($columns);
    }

    /**
     * One list, or two of about the same length, each category in one piece.
     *
     * @return list<Column>
     */
    public function plugins(bool $twoLists = false): array
    {
        $categories = [];
        $installed = $this->state->current->plugins ?? [];
        $wanted = $this->state->plugins();

        foreach ($this->state->catalog['plugins'] as $plugin) {
            $name = (string) $plugin['name'];
            $category = $plugin['category']['label'] ?? 'Other';
            $on = \in_array($name, $wanted, true);
            $was = \in_array($name, $installed, true);
            $badge = match (true) {
                $on && $was => Draw::fg('● on', Draw::GREEN),
                $on => Draw::bold(Draw::fg('+ add', Draw::ACCENT)),
                $was => Draw::bold(Draw::fg('− remove', Draw::RED)),
                default => '',
            };
            $categories[$category][] = [
                'value' => $name,
                'section' => $category,
                'row' => static fn(int $width): string => Draw::fit(($on ? Draw::fg('■', Draw::GREEN) : Draw::fg('□', Draw::DIM)) . ' ' . Draw::bold(Draw::fg($name, Draw::PINK)), 20)
                    . Draw::fit($badge, 11)
                    . Draw::fit(Draw::fg((string) $plugin['description'], Draw::GRAY), $width - 31),
            ];
        }

        $total = array_sum(array_map('count', $categories));
        $columns = [['title' => 'Plugins', 'icon' => '◇', 'list' => true, 'cards' => []], ['title' => 'More plugins', 'icon' => '◇', 'list' => true, 'cards' => []]];
        $column = 0;

        foreach ($categories as $rows) {
            if ($twoLists && 0 === $column && [] !== $columns[0]['cards'] && \count($columns[0]['cards']) + \count($rows) / 2 > $total / 2) {
                $column = 1;
            }

            $columns[$column]['cards'] = [...$columns[$column]['cards'], ...$rows];
        }

        return array_values(array_filter($columns, static fn(array $list): bool => [] !== $list['cards']));
    }

    /**
     * Whether the card is what the shop runs now, what the manifest wants, or both: an icon alone on a narrow card.
     */
    private static function status(bool $current, bool $wanted, int $width): string
    {
        $compact = $width < 24;

        return match (true) {
            $current && $wanted => Draw::fg($compact ? '●' : '● current', Draw::GREEN),
            $wanted => Draw::bold(Draw::fg($compact ? '★' : '★ wanted', Draw::ACCENT)),
            $current => Draw::fg($compact ? '○' : '○ current', Draw::GRAY),
            default => '',
        };
    }
}
