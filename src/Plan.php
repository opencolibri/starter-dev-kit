<?php

declare(strict_types=1);

namespace OpenColibri\Starter\DevKit;

/**
 * The steps that bring the instance from its current state to the wanted one, in the order they run.
 *
 * Only what differs is changed: a new header layout does not rebuild the theme, a new locale does not reload the
 * catalog. Settings left out of the wanted state are not managed.
 */
final readonly class Plan
{
    /** Locales the datasets are translated into (see CatalogFixtures): other locales show no product. */
    public const array CATALOG_LOCALES = ['en_US', 'fr_FR'];

    /**
     * @param list<Step> $steps
     */
    private function __construct(
        public array $steps,
    ) {}

    /**
     * @param list<string> $removablePlugins the plugins sylius:plugin:remove knows how to remove
     */
    public static function between(Manifest $current, Manifest $wanted, array $removablePlugins = []): self
    {
        $steps = self::plugins($current, $wanted, $removablePlugins);

        $datasetChanges = null !== $wanted->dataset && $wanted->dataset !== $current->dataset;
        // The Sylius fixtures purge the database, channel settings included: set them again afterwards.
        $resets = $datasetChanges && Manifest::SYLIUS_FIXTURES === $wanted->dataset;

        if ($resets) {
            $steps[] = new Step('dataset', 'Load the Sylius demo data', Step::COST_HEAVY, ['sylius:fixtures'], [
                'Purges the database: orders, customers and channel settings included',
            ], destructive: true);
        }

        if (null !== $step = self::channel($resets ? new Manifest() : $current, $wanted)) {
            $steps[] = $step;
        }

        if (null !== $step = self::theme($current, $wanted)) {
            $steps[] = $step;
        }

        if ($datasetChanges && !$resets) {
            $steps[] = new Step('dataset', \sprintf('Load the %s dataset', $wanted->dataset), Step::COST_SHORT, ['sylius:dataset:load', (string) $wanted->dataset], [
                null === $current->dataset ? 'Replaces the catalog of the default channel' : \sprintf('Replaces the %s catalog', $current->dataset),
                'Orders and customers are kept',
            ]);
        }

        return new self($steps);
    }

    public function isEmpty(): bool
    {
        return [] === $this->steps;
    }

    public function isDestructive(): bool
    {
        return [] !== array_filter($this->steps, static fn(Step $step): bool => $step->destructive);
    }

    /**
     * @param list<string> $removablePlugins
     *
     * @return list<Step>
     */
    private static function plugins(Manifest $current, Manifest $wanted, array $removablePlugins): array
    {
        if (null === $wanted->plugins) {
            return [];
        }

        $installed = $current->plugins ?? [];
        $add = array_values(array_diff($wanted->plugins, $installed));
        $remove = array_values(array_diff($installed, $wanted->plugins));
        $kept = array_values(array_diff($remove, $removablePlugins));

        if ([] !== $kept) {
            throw new \InvalidArgumentException(\sprintf('No remover for %s: uninstall it by hand, or keep it in the manifest.', implode(', ', $kept)));
        }

        $steps = [];

        // One run of Composer, migrations and asset build for all the plugins of a direction.
        if ([] !== $remove) {
            $steps[] = new Step('plugins', \sprintf('Remove %s', implode(', ', $remove)), Step::COST_HEAVY, ['sylius:plugin:remove', ...$remove], [
                'Rolls back their migrations: their data is lost',
            ], destructive: true);
        }

        if ([] !== $add) {
            $steps[] = new Step('plugins', \sprintf('Install %s', implode(', ', $add)), Step::COST_HEAVY, ['sylius:plugin:add', ...$add], [
                'Composer, configuration, migrations and an asset build',
            ]);
        }

        return $steps;
    }

    private static function channel(Manifest $current, Manifest $wanted): ?Step
    {
        $command = ['dev-kit:channel'];
        $details = [];

        if (null !== $wanted->name && $wanted->name !== $current->name) {
            $command[] = '--name=' . $wanted->name;
            $details[] = \sprintf('Shop name: %s', $wanted->name);
        }

        if (null !== $wanted->locales && !self::sameSet($wanted->locales, $current->locales)) {
            $command[] = '--locales=' . implode(',', $wanted->locales);
            $details[] = \sprintf('Locales: %s', implode(', ', $wanted->locales));

            $untranslated = array_values(array_diff($wanted->locales, self::CATALOG_LOCALES, $current->locales ?? []));

            if ([] !== $untranslated) {
                $details[] = \sprintf('The datasets are translated into %s only: no product shows in %s yet', implode(' and ', self::CATALOG_LOCALES), implode(', ', $untranslated));
            }
        }

        if (null !== $wanted->locale && $wanted->locale !== $current->locale) {
            $command[] = '--default-locale=' . $wanted->locale;
            $details[] = \sprintf('Default locale: %s', $wanted->locale);
        }

        if (null !== $wanted->currencies && ($wanted->baseCurrency() !== $current->baseCurrency() || !self::sameSet($wanted->currencies, $current->currencies))) {
            $command[] = '--currencies=' . implode(',', $wanted->currencies);
            $details[] = \sprintf('Currencies: %s (base %s)', implode(', ', $wanted->currencies), $wanted->baseCurrency());
        }

        if ([] === $details) {
            return null;
        }

        return new Step('channel', 'Update the channel', Step::COST_INSTANT, $command, $details);
    }

    private static function theme(Manifest $current, Manifest $wanted): ?Step
    {
        $theme = $wanted->theme ?? $current->theme;
        $menu = $wanted->menu;
        $search = $wanted->search;

        if (null !== $wanted->theme && $wanted->theme !== $current->theme) {
            $command = ['sylius:theme:setup', $wanted->theme];

            if (Manifest::DEFAULT_THEME !== $wanted->theme) {
                foreach (['menu' => $menu, 'search' => $search] as $option => $value) {
                    if (null !== $value) {
                        $command[] = \sprintf('--%s=%s', $option, $value);
                    }
                }
            }

            return new Step('theme', \sprintf('Apply the %s theme', $wanted->theme), Step::COST_DOWNTIME, $command, [
                'Copies the theme over the app and builds the assets (light and dark)',
                'The storefront answers 500 errors while the assets build',
            ]);
        }

        if (null === $theme || Manifest::DEFAULT_THEME === $theme) {
            return null;
        }

        $command = ['sylius:theme:header'];
        $details = [];

        if (null !== $menu && $menu !== $current->menu) {
            $command[] = '--menu=' . $menu;
            $details[] = \sprintf('Menu: %s', $menu);
        }

        if (null !== $search && $search !== $current->search) {
            $command[] = '--search=' . $search;
            $details[] = \sprintf('Search: %s', $search);
        }

        if ([] === $details) {
            return null;
        }

        $details[] = 'Hook configuration and a cache clear: no asset build';

        return new Step('header', 'Change the header layout', Step::COST_INSTANT, $command, $details);
    }

    /**
     * @param list<string>      $a
     * @param list<string>|null $b
     */
    private static function sameSet(array $a, ?array $b): bool
    {
        if (null === $b) {
            return false;
        }

        sort($a);
        sort($b);

        return $a === $b;
    }
}
