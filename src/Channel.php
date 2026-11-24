<?php

declare(strict_types=1);

namespace OpenColibri\Starter\DevKit;

use OpenColibri\Starter\Core\App;
use OpenColibri\Starter\Core\Util\Docker;

use function Castor\fs;
use function Castor\with;

/**
 * Reads and changes the name, locales and currencies of the default channel (WEB_STORE). Sylius has no console
 * command for them: the dev-kit ships its own into the application (resources/application/), which goes through the
 * Sylius repositories and factories, so it works on any database Doctrine supports.
 */
final readonly class Channel
{
    public const string CODE = 'WEB_STORE';

    /** Shipped into the application and refreshed before each call, like the reset command of opencolibri/starter-fixtures. */
    public const string COMMAND_FILE = 'src/Command/DevKitChannelCommand.php';

    public function __construct(
        private App $app,
    ) {}

    /**
     * @return array{name: string, locale: string, locales: list<string>, currencies: list<string>}
     */
    public function read(): array
    {
        return $this->run([]);
    }

    /**
     * @param list<string>|null $locales    the enabled locales, replacing the current ones
     * @param list<string>|null $currencies the enabled currencies, the base one first
     */
    public function update(?string $name = null, ?array $locales = null, ?string $defaultLocale = null, ?array $currencies = null): void
    {
        // Validates the codes before they reach the command.
        new Manifest(name: $name, locale: $defaultLocale, locales: $locales, currencies: $currencies);

        $options = array_filter([
            'name' => $name,
            'locales' => null !== $locales ? implode(',', $locales) : null,
            'default-locale' => $defaultLocale,
            'currencies' => null !== $currencies ? implode(',', $currencies) : null,
        ], static fn(?string $value): bool => null !== $value);

        if ([] !== $options) {
            $this->run($options);
        }
    }

    /**
     * @param array<string, string> $options
     *
     * @return array{name: string, locale: string, locales: list<string>, currencies: list<string>}
     */
    private function run(array $options): array
    {
        $this->installCommand();

        $command = 'php bin/console sylius:dev-kit:channel --no-ansi ' . escapeshellarg(self::CODE);
        foreach ($options as $option => $value) {
            $command .= ' --' . $option . '=' . escapeshellarg($value);
        }

        $output = with(fn(): string => Docker::run($this->app, $command)->getOutput(), quiet: true);

        // The JSON is the last line: Symfony may print deprecation notices before it.
        foreach (array_reverse(explode("\n", trim($output))) as $line) {
            if (str_starts_with(trim($line), '{')) {
                /** @var array{name: string, locale: string, locales: list<string>, currencies: list<string>} */
                return json_decode(trim($line), true, flags: \JSON_THROW_ON_ERROR);
            }
        }

        throw new \RuntimeException(\sprintf('Unable to read the %s channel: %s', self::CODE, trim($output)));
    }

    private function installCommand(): void
    {
        $source = \dirname(__DIR__) . '/resources/application/' . self::COMMAND_FILE;
        $target = $this->app->directory() . '/' . self::COMMAND_FILE;

        if (is_file($target) && file_get_contents($source) === file_get_contents($target)) {
            return;
        }

        fs()->copy($source, $target, true);
        // A new command class: the autoloader and the container (built once in prod) must see it.
        with(fn() => Docker::run($this->app, 'composer dump-autoload -q && php bin/console cache:clear -n'), quiet: true);
    }
}
