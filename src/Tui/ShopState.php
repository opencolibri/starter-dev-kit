<?php

declare(strict_types=1);

namespace OpenColibri\Starter\DevKit\Tui;

use OpenColibri\Starter\DevKit\Manifest;
use OpenColibri\Starter\DevKit\Plan;
use OpenColibri\Starter\Themes\HeaderLayout;

/**
 * What the shop runs now, what the manifest wants, and the rules that change it: the state the TUI edits and draws.
 *
 * Every change returns the reason it was refused, if it was, and leaves the wanted state as it was then.
 *
 * @phpstan-type Catalog array{themes: list<array<string, mixed>>, datasets: list<array<string, mixed>>, plugins: list<array<string, mixed>>}
 */
final class ShopState
{
    /** @var array{name: ?string, dataset: ?string, theme: ?string, menu: ?string, search: ?string, locale: ?string, locales: ?list<string>, currencies: ?list<string>, plugins: ?list<string>} */
    private array $wanted;

    /**
     * @param \Closure(Manifest): list<string> $validate         the errors of a manifest (DevKitTasks::validate())
     * @param Catalog                          $catalog          what a manifest can pick (Catalog::describe())
     * @param list<string>                     $removablePlugins
     */
    public function __construct(
        public readonly Manifest $current,
        Manifest $wanted,
        private readonly \Closure $validate,
        public readonly array $catalog,
        private readonly array $removablePlugins,
    ) {
        $this->wanted = [
            'name' => $wanted->name,
            'dataset' => $wanted->dataset,
            'theme' => $wanted->theme,
            'menu' => $wanted->menu,
            'search' => $wanted->search,
            'locale' => $wanted->locale,
            'locales' => $wanted->locales,
            'currencies' => $wanted->currencies,
            'plugins' => $wanted->plugins,
        ];
    }

    public function manifest(): Manifest
    {
        return new Manifest(...$this->wanted);
    }

    /**
     * The wanted value of a setting, null when the manifest does not manage it.
     *
     * @return string|list<string>|null
     */
    public function wanted(string $key): string|array|null
    {
        return $this->wanted[$key];
    }

    /**
     * The wanted value, or the current one when the manifest does not manage the setting.
     *
     * @return string|list<string>|null
     */
    public function effective(string $key): string|array|null
    {
        return $this->wanted[$key] ?? $this->current->{$key};
    }

    /**
     * @return list<string> the plugins the shop will have
     */
    public function plugins(): array
    {
        return $this->wanted['plugins'] ?? $this->current->plugins ?? [];
    }

    /**
     * @param string|list<string>|null $value
     *
     * @return string|null why the change is refused
     */
    public function set(string $key, string|array|null $value): ?string
    {
        $previous = $this->wanted;
        $this->wanted[$key] = $value;

        // The header placements depend on the theme, the default locale on the locales.
        if ('theme' === $key && \is_string($value) && Manifest::DEFAULT_THEME !== $value) {
            $defaults = HeaderLayout::resolve($value);

            if (!\in_array($this->wanted['menu'], HeaderLayout::menuPlacements($value), true)) {
                $this->wanted['menu'] = $defaults['menu'];
            }

            $this->wanted['search'] ??= $defaults['search'];
        }

        if ('locales' === $key && \is_array($value) && !\in_array($this->wanted['locale'], $value, true)) {
            $this->wanted['locale'] = $value[0] ?? null;
        }

        try {
            $this->manifest();
        } catch (\InvalidArgumentException $exception) {
            $this->wanted = $previous;

            return $exception->getMessage();
        }

        return null;
    }

    /**
     * Adds the plugin, or removes it.
     *
     * @return string|null why the change is refused
     */
    public function toggle(string $plugin): ?string
    {
        $plugins = $this->plugins();

        if (!\in_array($plugin, $plugins, true)) {
            $plugins[] = $plugin;
            sort($plugins);

            return $this->set('plugins', $plugins);
        }

        if (\in_array($plugin, $this->current->plugins ?? [], true) && !\in_array($plugin, $this->removablePlugins, true)) {
            return \sprintf('The dev-kit cannot remove %s: it has no remover.', $plugin);
        }

        return $this->set('plugins', array_values(array_diff($plugins, [$plugin])));
    }

    /**
     * The next value of the default locale or of a header placement.
     *
     * @param 'locale'|'menu'|'search' $key
     *
     * @return string|null why the change is refused
     */
    public function cycle(string $key, int $direction = 1): ?string
    {
        $values = match ($key) {
            'locale' => $this->wanted['locales'] ?? $this->current->locales ?? [],
            'menu' => HeaderLayout::menuPlacements((string) $this->effective('theme')),
            default => HeaderLayout::SEARCH_PLACEMENTS,
        };

        if ([] === $values) {
            return null;
        }

        $index = array_search($this->effective($key), $values, true);
        $index = false === $index ? 0 : ($index + $direction + \count($values)) % \count($values);

        return $this->set($key, $values[$index]);
    }

    public function changed(string $key): bool
    {
        if ('plugins' === $key) {
            $wanted = $this->wanted['plugins'];
            $current = $this->current->plugins ?? [];

            return null !== $wanted && [] !== array_diff($wanted, $current) + array_diff($current, $wanted);
        }

        return null !== $this->wanted[$key] && $this->wanted[$key] != $this->current->{$key};
    }

    /**
     * The errors of the manifest, or the plan of dev-kit:apply.
     *
     * @return array{list<string>, ?Plan}
     */
    public function plan(): array
    {
        try {
            $manifest = $this->manifest();
        } catch (\InvalidArgumentException $exception) {
            return [[$exception->getMessage()], null];
        }

        $errors = ($this->validate)($manifest);

        return [$errors, [] === $errors ? Plan::between($this->current, $manifest, $this->removablePlugins) : null];
    }

    /**
     * The value as text: "—" when not managed and "none" for an empty list, or an empty string when plain.
     */
    public function show(string $key, bool $plain = false, bool $current = false): string
    {
        $value = $current ? $this->current->{$key} : $this->wanted[$key];

        if (null === $value) {
            return $plain ? '' : '—';
        }

        if (\is_array($value)) {
            return [] === $value ? ($plain ? '' : 'none') : implode(', ', $value);
        }

        return $value;
    }

    /**
     * What the shop runs now, what the manifest wants (null when it does not manage it) and a detail.
     *
     * @return array{string, ?string, string}
     */
    public function describe(string $key): array
    {
        $current = $this->current;
        $wanted = $this->wanted;

        return match ($key) {
            'name' => [$current->name ?? '—', $wanted['name'], 'The name of the default channel'],
            'theme' => [$current->theme ?? 'unknown', $wanted['theme'], $this->themeCategory($wanted['theme'] ?? $current->theme)],
            'header' => [
                \sprintf('menu %s · search %s', $current->menu ?? '—', $current->search ?? '—'),
                null === $wanted['menu'] && null === $wanted['search'] ? null : \sprintf('menu %s · search %s', $wanted['menu'] ?? $current->menu ?? '—', $wanted['search'] ?? $current->search ?? '—'),
                'Where the theme header shows the category menu and the search',
            ],
            'dataset' => [$this->datasetName($current->dataset), null === $wanted['dataset'] ? null : $this->datasetName($wanted['dataset']), $this->datasetDetail($wanted['dataset'] ?? $current->dataset)],
            'locales' => [
                self::languages($current->locales, $current->locale),
                null === $wanted['locales'] && null === $wanted['locale'] ? null : self::languages($wanted['locales'] ?? $current->locales, $wanted['locale'] ?? $current->locale),
                'The default language is marked with *',
            ],
            'currencies' => [
                implode(', ', $current->currencies ?? []) ?: '—',
                null === $wanted['currencies'] ? null : implode(', ', $wanted['currencies']),
                \sprintf('The first one is the base currency (%s)', ($wanted['currencies'] ?? $current->currencies ?? ['—'])[0] ?? '—'),
            ],
            'plugins' => $this->pluginSummary(),
            default => throw new \InvalidArgumentException(\sprintf('No setting "%s".', $key)),
        };
    }

    /**
     * @param list<string>|null $locales
     */
    private static function languages(?array $locales, ?string $default): string
    {
        return implode(', ', array_map(static fn(string $code): string => $code === $default ? $code . '*' : $code, $locales ?? [])) ?: '—';
    }

    /**
     * @return array{string, ?string, string}
     */
    private function pluginSummary(): array
    {
        $installed = $this->current->plugins ?? [];
        $now = \sprintf('%d installed', \count($installed));

        if (null === $this->wanted['plugins']) {
            return [$now, null, implode(', ', $installed)];
        }

        $added = array_diff($this->wanted['plugins'], $installed);
        $removed = array_diff($installed, $this->wanted['plugins']);
        $changes = trim(([] === $added ? '' : '+ ' . implode(', ', $added)) . ([] === $removed ? '' : '  − ' . implode(', ', $removed)));

        return [$now, '' === $changes ? $now : $changes, implode(', ', $this->wanted['plugins']) ?: 'none'];
    }

    private function themeCategory(?string $theme): string
    {
        foreach ($this->catalog['themes'] as $item) {
            if ($item['name'] === $theme) {
                return (string) ($item['category']['label'] ?? '');
            }
        }

        return Manifest::DEFAULT_THEME === $theme ? 'The Sylius look' : '';
    }

    private function datasetName(?string $slug): string
    {
        if (null === $slug) {
            return 'unknown';
        }

        foreach ($this->catalog['datasets'] as $dataset) {
            if ($dataset['slug'] === $slug) {
                return (string) $dataset['name'];
            }
        }

        return Manifest::SYLIUS_FIXTURES === $slug ? 'Sylius demo' : $slug;
    }

    private function datasetDetail(?string $slug): string
    {
        foreach ($this->catalog['datasets'] as $dataset) {
            if ($dataset['slug'] === $slug) {
                return \sprintf('%d products · %d collections', $dataset['productCount'], $dataset['collectionCount']);
            }
        }

        return Manifest::SYLIUS_FIXTURES === $slug ? 'The fixtures of Sylius' : 'Not a dataset of this project';
    }
}
