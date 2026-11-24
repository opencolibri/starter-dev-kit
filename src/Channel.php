<?php

declare(strict_types=1);

namespace OpenColibri\Starter\DevKit;

use OpenColibri\Starter\Core\App;
use OpenColibri\Starter\Core\Util\Docker;

use function Castor\with;

/**
 * Reads and changes the name, locales and currencies of the default channel (WEB_STORE), in SQL through
 * "bin/console dbal:run-sql": Sylius has no console command for them. PostgreSQL only, like the Docker stack.
 */
final readonly class Channel
{
    public const string CODE = 'WEB_STORE';

    public function __construct(
        private App $app,
    ) {}

    /**
     * @return array{name: string, locale: string, locales: list<string>, currencies: list<string>}
     */
    public function read(): array
    {
        $code = self::CODE;
        $json = $this->fetchValue(<<<SQL
            SELECT json_build_object(
                'name', c.name,
                'locale', l.code,
                'locales', (SELECT json_agg(x.code ORDER BY x.code) FROM sylius_channel_locales cl JOIN sylius_locale x ON x.id = cl.locale_id WHERE cl.channel_id = c.id),
                'currencies', (SELECT json_agg(y.code ORDER BY y.id = c.base_currency_id DESC, y.code) FROM sylius_channel_currencies cc JOIN sylius_currency y ON y.id = cc.currency_id WHERE cc.channel_id = c.id),
                'base', b.code
            )
            FROM sylius_channel c JOIN sylius_locale l ON l.id = c.default_locale_id JOIN sylius_currency b ON b.id = c.base_currency_id
            WHERE c.code = '{$code}'
            SQL);

        /** @var array{name: string, locale: string, locales: list<string>|null, currencies: list<string>|null, base: string} $data */
        $data = json_decode($json, true, flags: \JSON_THROW_ON_ERROR);
        $currencies = array_values(array_diff($data['currencies'] ?? [], [$data['base']]));

        return [
            'name' => $data['name'],
            'locale' => $data['locale'],
            'locales' => $data['locales'] ?? [$data['locale']],
            'currencies' => [$data['base'], ...$currencies],
        ];
    }

    /**
     * @param list<string>|null $locales    the enabled locales, replacing the current ones
     * @param list<string>|null $currencies the enabled currencies, the base one first
     */
    public function update(?string $name = null, ?array $locales = null, ?string $defaultLocale = null, ?array $currencies = null): void
    {
        // Validates the codes before they reach the SQL.
        new Manifest(name: $name, locale: $defaultLocale, locales: $locales, currencies: $currencies);

        $channel = \sprintf("(SELECT id FROM sylius_channel WHERE code = '%s')", self::CODE);
        $statements = [];

        if (null !== $name) {
            $statements[] = \sprintf("UPDATE sylius_channel SET name = %s WHERE code = '%s'", self::quote($name), self::CODE);
        }

        if (null !== $locales) {
            $statements = [...$statements, ...self::ensure('sylius_locale', $locales)];
            $statements[] = \sprintf('DELETE FROM sylius_channel_locales WHERE channel_id = %s', $channel);
            $statements[] = \sprintf("INSERT INTO sylius_channel_locales (channel_id, locale_id) SELECT %s, id FROM sylius_locale WHERE code IN (%s)", $channel, self::inList($locales));
        }

        if (null !== $defaultLocale) {
            $statements = [...$statements, ...self::ensure('sylius_locale', [$defaultLocale])];
            $statements[] = \sprintf("UPDATE sylius_channel SET default_locale_id = (SELECT id FROM sylius_locale WHERE code = '%s') WHERE code = '%s'", $defaultLocale, self::CODE);
        }

        if (null !== $currencies) {
            $statements = [...$statements, ...self::ensure('sylius_currency', $currencies)];
            $statements[] = \sprintf('DELETE FROM sylius_channel_currencies WHERE channel_id = %s', $channel);
            $statements[] = \sprintf("INSERT INTO sylius_channel_currencies (channel_id, currency_id) SELECT %s, id FROM sylius_currency WHERE code IN (%s)", $channel, self::inList($currencies));
            $statements[] = \sprintf("UPDATE sylius_channel SET base_currency_id = (SELECT id FROM sylius_currency WHERE code = '%s') WHERE code = '%s'", $currencies[0], self::CODE);
        }

        if ([] === $statements) {
            return;
        }

        // One transaction: the channel never keeps a default locale it does not enable.
        $this->console('BEGIN; ' . implode('; ', $statements) . '; COMMIT');
    }

    private function fetchValue(string $sql): string
    {
        $output = $this->console($sql);

        // The value sits in the only data row of the table dbal:run-sql prints.
        foreach (explode("\n", $output) as $line) {
            $line = trim($line);

            if (str_starts_with($line, '{') || str_starts_with($line, '[')) {
                return $line;
            }
        }

        throw new \RuntimeException(\sprintf('The %s channel was not found: load the fixtures first.', self::CODE));
    }

    private function console(string $sql): string
    {
        $command = 'php bin/console dbal:run-sql --no-ansi ' . escapeshellarg(preg_replace('/\s+/', ' ', $sql) ?? $sql);

        return with(fn(): string => Docker::run($this->app, $command)->getOutput(), quiet: true);
    }

    /**
     * @param list<string> $codes
     *
     * @return list<string>
     */
    private static function ensure(string $table, array $codes): array
    {
        return array_map(
            static fn(string $code): string => \sprintf(
                "INSERT INTO %1\$s (id, code, created_at, updated_at) SELECT nextval('%1\$s_id_seq'), '%2\$s', NOW(), NOW() WHERE NOT EXISTS (SELECT 1 FROM %1\$s WHERE code = '%2\$s')",
                $table,
                $code,
            ),
            $codes,
        );
    }

    /**
     * @param list<string> $codes validated locale or currency codes
     */
    private static function inList(array $codes): string
    {
        return implode(', ', array_map(static fn(string $code): string => "'" . $code . "'", $codes));
    }

    private static function quote(string $value): string
    {
        return "'" . str_replace("'", "''", $value) . "'";
    }
}
