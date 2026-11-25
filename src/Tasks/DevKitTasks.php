<?php

declare(strict_types=1);

namespace OpenColibri\Starter\DevKit\Tasks;

use Castor\Api\Attribute\AsApi;
use Castor\Attribute\AsArgument;
use Castor\Attribute\AsOption;
use Castor\Attribute\AsTask;
use OpenColibri\Starter\Core\App;
use OpenColibri\Starter\Core\Dataset\Datasets;
use OpenColibri\Starter\Core\Progress\TaskProgress;
use OpenColibri\Starter\Core\Util\Symfony;
use OpenColibri\Starter\DevKit\Catalog;
use OpenColibri\Starter\DevKit\Channel;
use OpenColibri\Starter\DevKit\Installer\DevKitAdminInstaller;
use OpenColibri\Starter\DevKit\InstanceState;
use OpenColibri\Starter\DevKit\Manifest;
use OpenColibri\Starter\DevKit\Plan;
use OpenColibri\Starter\DevKit\Step;
use OpenColibri\Starter\Plugins\Tasks\PluginTasks;
use OpenColibri\Starter\Themes\HeaderLayout;
use OpenColibri\Starter\Themes\Themes;

use function Castor\fs;
use function Castor\input;
use function Castor\io;
use function Castor\run;

/**
 * Builds the local shop little by little: starter.yaml holds its wanted state, dev-kit:apply changes only what
 * differs from the running instance. See docs/dev-kit/index.md.
 */
final readonly class DevKitTasks
{
    public const string MANIFEST = 'starter.yaml';

    /** The TaskProgress key of dev-kit:apply. */
    public const string PROGRESS = 'dev-kit';

    public function __construct(
        private App $app,
    ) {}

    /**
     * @return iterable<int, array{task: AsTask, function: callable}>
     */
    public function __invoke(): iterable
    {
        yield [
            'task' => new AsTask('status', 'dev-kit', 'Show what the local shop runs now: dataset, theme, header, locales, currencies, plugins'),
            /** @phpstan-ignore-next-line */
            'function' => #[AsApi] function (
                #[AsOption(description: 'Output format: "yaml" or "json"')]
                string $format = 'yaml',
            ): int {
                $state = (new InstanceState($this->app))->read();

                if ('json' === $format) {
                    io()->writeln(json_encode($state->toArray(), \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE));

                    return 0;
                }

                io()->writeln($state->toYaml(withComment: false));

                if (null === $state->dataset) {
                    io()->note('The loaded dataset is unknown until dev-kit loads one.');
                }

                return 0;
            },
        ];

        yield [
            'task' => new AsTask('init', 'dev-kit', 'Set up the dev-kit: starter.yaml from what the local shop runs now, and its admin'),
            'function' => function (
                #[AsArgument(description: 'The manifest to write')]
                string $file = self::MANIFEST,
                #[AsOption(description: 'Overwrite an existing manifest')]
                bool $force = false,
                #[AsOption(description: 'Do not install the dev-kit admin (a Symfony service of the stack, in dev-kit/)')]
                bool $noAdmin = false,
            ): int {
                if (is_file($file) && !$force) {
                    io()->note(\sprintf('%s already exists: kept (--force to write it again).', $file));
                } else {
                    fs()->dumpFile($file, (new InstanceState($this->app))->read()->toYaml());
                    io()->success(\sprintf('%s describes the shop as it runs now. Edit it, then run "castor dev-kit:apply".', $file));
                }

                if ($noAdmin) {
                    return 0;
                }

                if (is_dir(DevKitAdminInstaller::DIRECTORY)) {
                    io()->note(\sprintf('The dev-kit admin is already installed in %s/.', DevKitAdminInstaller::DIRECTORY));

                    return 0;
                }

                $hostname = $this->app->hostname() ?? 'app.test';
                $domain = 'dev-kit.' . $hostname;

                io()->section('Installing the dev-kit admin');
                // A new process: the installer adds the service to castor.php, builds and starts it.
                run([
                    'castor', 'docker:service:install', DevKitAdminInstaller::NAME, '--no-interaction',
                    '--with-domain=' . $domain,
                    '--with-storefront-url=https://' . $hostname . '/',
                ]);

                io()->success(\sprintf('The dev-kit admin runs on https://%s/: start castor-api next to it with "castor api:serve-development".', $domain));

                return 0;
            },
        ];

        yield [
            'task' => new AsTask('apply', 'dev-kit', 'Bring the local shop to the state of starter.yaml, changing only what differs'),
            // One apply at a time, never next to a dataset load (same lock as sylius:dataset:load).
            /** @phpstan-ignore-next-line */
            'function' => #[AsApi(async: true, lock: 'sylius:database')] function (
                #[AsArgument(description: 'The manifest to apply')]
                string $file = self::MANIFEST,
                #[AsOption(description: 'Show the plan without changing anything')]
                bool $dryRun = false,
                #[AsOption(description: 'Run destructive steps (plugin removal, Sylius fixtures) without asking')]
                bool $yes = false,
                #[AsOption(description: 'Read the wanted state from this JSON file, and write it to the manifest before applying it')]
                ?string $fromJson = null,
                #[AsOption(description: 'Output format of the plan: "text" or "json"')]
                string $format = 'text',
                #[AsOption(description: 'The wanted state as inline JSON, written to the manifest before applying it (the dev-kit admin)')]
                ?string $manifestJson = null,
            ): int {
                $json = 'json' === $format;
                $fail = static function (array $errors) use ($json): int {
                    $json ? io()->writeln(json_encode(['errors' => $errors], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE)) : io()->error($errors);

                    return 1;
                };

                try {
                    $source = $manifestJson ?? (null === $fromJson ? null : (string) file_get_contents($fromJson));
                    $wanted = null === $source ? Manifest::fromFile($file) : Manifest::fromArray((array) json_decode($source, true, flags: \JSON_THROW_ON_ERROR));

                    if ([] !== $errors = self::validate($wanted)) {
                        return $fail($errors);
                    }

                    $state = new InstanceState($this->app);
                    $plan = Plan::between($state->read(), $wanted, PluginTasks::removerNames());
                } catch (\InvalidArgumentException|\JsonException $exception) {
                    return $fail([$exception->getMessage()]);
                }

                if ($json && $dryRun) {
                    io()->writeln(json_encode(['steps' => array_map(static fn(Step $step): array => $step->describe(), $plan->steps)], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES));

                    return 0;
                }

                if (!$dryRun && (null !== $fromJson || null !== $manifestJson)) {
                    fs()->dumpFile($file, $wanted->toYaml());
                }

                if ($plan->isEmpty()) {
                    io()->success('The shop already matches the manifest.');

                    return 0;
                }

                self::show($plan);

                if ($dryRun) {
                    return 0;
                }

                if ($plan->isDestructive() && !$yes && !(input()->isInteractive() && io()->confirm('Some steps delete data. Run the plan?', false))) {
                    io()->warning('Nothing changed. Pass --yes to run destructive steps without asking.');

                    return 1;
                }

                // The dev-kit admin polls this while castor-api runs the task.
                TaskProgress::start(self::PROGRESS, 'Apply changes', \count($plan->steps));

                foreach ($plan->steps as $index => $step) {
                    io()->section(\sprintf('%d/%d %s', $index + 1, \count($plan->steps), $step->title));
                    TaskProgress::current(self::PROGRESS, $step->title);

                    if ('plugins' === $step->key) {
                        // A step that fails halfway leaves its plugins out of the record: the next apply runs it again.
                        $installed = $state->installedPlugins();
                        $state->recordPlugins($installed);
                        run(['castor', ...$step->command]);
                        $changed = \array_slice($step->command, 1);
                        $state->recordPlugins('sylius:plugin:add' === $step->command[0] ? [...$installed, ...$changed] : array_values(array_diff($installed, $changed)));
                        TaskProgress::advance(self::PROGRESS);

                        continue;
                    }

                    if ('theme' === $step->key) {
                        $state->recordThemePending(true);
                    }

                    run(['castor', ...$step->command]);

                    if ('theme' === $step->key) {
                        $state->recordThemePending(false);
                    }

                    if ('dataset' === $step->key) {
                        $state->recordDataset((string) $wanted->dataset);
                    }

                    TaskProgress::advance(self::PROGRESS);
                }

                TaskProgress::finish(self::PROGRESS);

                $state->recordHistory($state->read(), array_map(static fn(Step $step): string => $step->title, $plan->steps));
                io()->success(\sprintf('The shop matches %s.', $file));

                return 0;
            },
        ];

        yield [
            'task' => new AsTask('history', 'dev-kit', 'List the states dev-kit:apply brought the shop to, the latest first'),
            /** @phpstan-ignore-next-line */
            'function' => #[AsApi(exposeSchema: false)] function (): int {
                io()->writeln(json_encode((new InstanceState($this->app))->history(), \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES));

                return 0;
            },
        ];

        yield [
            'task' => new AsTask('catalog', 'dev-kit', 'List what the manifest can pick, as JSON: themes, datasets, plugins'),
            /** @phpstan-ignore-next-line */
            'function' => #[AsApi(exposeSchema: false)] static function (): int {
                io()->writeln(json_encode(Catalog::describe(), \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES));

                return 0;
            },
        ];

        yield [
            'task' => new AsTask('channel', 'dev-kit', 'Change the name, locales and currencies of the default channel'),
            'function' => function (
                #[AsOption(description: 'The shop name')]
                ?string $name = null,
                #[AsOption(description: 'The enabled locales, comma-separated (replaces the current ones)')]
                ?string $locales = null,
                #[AsOption(description: 'The default locale')]
                ?string $defaultLocale = null,
                #[AsOption(description: 'The enabled currencies, comma-separated, the base one first')]
                ?string $currencies = null,
            ): int {
                $split = static fn(?string $codes): ?array => null === $codes ? null : array_values(array_filter(array_map('trim', explode(',', $codes))));

                (new Channel($this->app))->update($name, $split($locales), $defaultLocale, $split($currencies));
                // The channel, its locales and currencies are cached by the shop.
                Symfony::cacheClear($this->app);
                io()->success(\sprintf('The %s channel is updated.', Channel::CODE));

                return 0;
            },
        ];
    }

    /**
     * @return list<string>
     */
    private static function validate(Manifest $manifest): array
    {
        $errors = [];
        $themes = [...array_keys(Themes::installers()), Manifest::DEFAULT_THEME];

        if (null !== $manifest->theme && !\in_array($manifest->theme, $themes, true)) {
            $errors[] = \sprintf('Unknown theme "%s": use one of %s.', $manifest->theme, implode(', ', $themes));
        }

        if (null !== $manifest->theme && Manifest::DEFAULT_THEME !== $manifest->theme && \in_array($manifest->theme, $themes, true)) {
            $errors = [...$errors, ...HeaderLayout::validate($manifest->theme, $manifest->menu, $manifest->search)];
        }

        if (null !== $manifest->dataset && Manifest::SYLIUS_FIXTURES !== $manifest->dataset && null === Datasets::find($manifest->dataset)) {
            $errors[] = \sprintf('Unknown dataset "%s": use "%s" or one of %s.', $manifest->dataset, Manifest::SYLIUS_FIXTURES, implode(', ', array_keys(Datasets::all())));
        }

        $unknown = array_diff($manifest->plugins ?? [], PluginTasks::installerNames());

        if ([] !== $unknown) {
            $errors[] = \sprintf('Unknown plugin(s) %s: use some of %s.', implode(', ', $unknown), implode(', ', PluginTasks::installerNames()));
        }

        return $errors;
    }

    private static function show(Plan $plan): void
    {
        $rows = [];

        foreach ($plan->steps as $index => $step) {
            $rows[] = [
                $index + 1,
                $step->title . ($step->destructive ? ' <fg=red>(deletes data)</>' : ''),
                $step->costLabel(),
                $step->commandLine(),
            ];

            foreach ($step->details as $detail) {
                $rows[] = ['', '  <fg=gray>' . $detail . '</>', '', ''];
            }
        }

        io()->table(['#', 'Step', 'Takes', 'Runs'], $rows);
    }
}
