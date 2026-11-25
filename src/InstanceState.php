<?php

declare(strict_types=1);

namespace OpenColibri\Starter\DevKit;

use OpenColibri\Starter\Core\App;
use OpenColibri\Starter\Plugins\Tasks\PluginTasks;
use OpenColibri\Starter\Themes\HeaderLayout;

use function Castor\fs;

/**
 * What the local instance runs now, read from the instance itself wherever it can be: the theme and its header
 * from the theme hooks, the plugins from composer.json, the channel from the database. Only the loaded dataset is
 * not stored by Sylius: dev-kit records it when it loads one (var/dev-kit/state.json of the app).
 *
 * Plugins are detected from composer.json until dev-kit changes them once; from then on, the list dev-kit records
 * after each successful step wins: a plugin whose install stopped halfway is in composer.json, yet not installed.
 */
final readonly class InstanceState
{
    public function __construct(
        private App $app,
    ) {}

    public function read(): Manifest
    {
        $channel = (new Channel($this->app))->read();
        // A theme setup that stopped halfway has already written its hooks: the theme is unknown until it succeeds.
        $header = $this->themePending() ? null : HeaderLayout::read(HeaderLayout::hooksFileOf($this->app));

        return new Manifest(
            name: $channel['name'],
            dataset: \is_string($dataset = $this->state()['dataset'] ?? null) ? $dataset : null,
            theme: $this->themePending() ? null : ($header['theme'] ?? Manifest::DEFAULT_THEME),
            menu: $header['options']['menu'] ?? null,
            search: $header['options']['search'] ?? null,
            locale: $channel['locale'],
            locales: $channel['locales'],
            currencies: $channel['currencies'],
            plugins: $this->installedPlugins(),
        );
    }

    /**
     * Marks a theme setup as running: until it is cleared, the theme reads as unknown, and the next apply sets it up.
     */
    public function recordThemePending(bool $pending): void
    {
        $this->record(['theme_pending' => $pending]);
    }

    public function themePending(): bool
    {
        return true === ($this->state()['theme_pending'] ?? false);
    }

    public function recordDataset(string $dataset): void
    {
        $this->record(['dataset' => $dataset]);
    }

    /**
     * @param list<string> $plugins the plugins installed, recorded before and after each plugin step
     */
    public function recordPlugins(array $plugins): void
    {
        sort($plugins);
        $this->record(['plugins' => array_values(array_unique($plugins))]);
    }

    /**
     * @return list<string>
     */
    public function installedPlugins(): array
    {
        $recorded = $this->state()['plugins'] ?? null;

        if (\is_array($recorded)) {
            return array_values(array_filter($recorded, \is_string(...)));
        }

        return PluginTasks::installed($this->app->directory());
    }

    /**
     * @param list<string> $steps the titles of the steps that brought the shop to this state
     */
    public function recordHistory(Manifest $state, array $steps): void
    {
        $line = json_encode(['at' => date(\DATE_ATOM), 'steps' => $steps, 'state' => $state->toArray()], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);
        fs()->appendToFile($this->historyFile(), $line . "\n");
    }

    /**
     * @return list<array{at: string, steps: list<string>, state: array<string, mixed>}> the latest first
     */
    public function history(int $limit = 50): array
    {
        if (!is_file($this->historyFile())) {
            return [];
        }

        $lines = array_reverse(array_filter(explode("\n", (string) file_get_contents($this->historyFile()))));
        $entries = [];

        foreach (\array_slice($lines, 0, $limit) as $line) {
            $entry = json_decode($line, true);

            if (\is_array($entry) && \is_string($entry['at'] ?? null) && \is_array($entry['state'] ?? null)) {
                /** @var array{at: string, steps: list<string>, state: array<string, mixed>} $entry */
                $entries[] = $entry;
            }
        }

        return $entries;
    }

    /**
     * @param array<string, mixed> $values
     */
    private function record(array $values): void
    {
        fs()->dumpFile($this->stateFile(), json_encode([...$this->state(), ...$values], \JSON_PRETTY_PRINT | \JSON_THROW_ON_ERROR) . "\n");
    }

    /**
     * @return array<string, mixed>
     */
    private function state(): array
    {
        if (!is_file($this->stateFile())) {
            return [];
        }

        $state = json_decode((string) file_get_contents($this->stateFile()), true);

        return \is_array($state) ? $state : [];
    }

    private function historyFile(): string
    {
        return $this->app->directory() . '/var/dev-kit/history.jsonl';
    }

    private function stateFile(): string
    {
        return $this->app->directory() . '/var/dev-kit/state.json';
    }
}
