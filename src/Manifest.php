<?php

declare(strict_types=1);

namespace OpenColibri\Starter\DevKit;

use Symfony\Component\Yaml\Yaml;

/**
 * The wanted state of the local shop (starter.yaml): what dev-kit:apply brings the instance to.
 *
 * Every setting is optional: a setting left out (null) is not managed, dev-kit:apply leaves it as it is.
 * The first currency is the base currency of the channel.
 */
final readonly class Manifest
{
    /** The Sylius demo data (castor sylius:fixtures) instead of a dataset of opencolibri/starter-datasets. */
    public const string SYLIUS_FIXTURES = 'sylius';

    /** The Sylius look, without a theme of opencolibri/starter-themes. */
    public const string DEFAULT_THEME = 'default';

    /**
     * @param list<string>|null $locales    the locales of the channel
     * @param list<string>|null $currencies the currencies of the channel, the base one first
     * @param list<string>|null $plugins    the plugins installed by sylius:plugin:add
     */
    public function __construct(
        public ?string $name = null,
        public ?string $dataset = null,
        public ?string $theme = null,
        public ?string $menu = null,
        public ?string $search = null,
        public ?string $locale = null,
        public ?array $locales = null,
        public ?array $currencies = null,
        public ?array $plugins = null,
    ) {
        foreach ($locales ?? [] as $code) {
            self::assertLocale($code);
        }

        if (null !== $locale) {
            self::assertLocale($locale);
        }

        if (null !== $locale && null !== $locales && !\in_array($locale, $locales, true)) {
            throw new \InvalidArgumentException(\sprintf('The default locale "%s" must be one of the enabled locales (%s).', $locale, implode(', ', $locales)));
        }

        if ([] === $locales) {
            throw new \InvalidArgumentException('Enable at least one locale.');
        }

        if ([] === $currencies) {
            throw new \InvalidArgumentException('Enable at least one currency.');
        }

        foreach ($currencies ?? [] as $code) {
            if (!preg_match('/^[A-Z]{3}$/', $code)) {
                throw new \InvalidArgumentException(\sprintf('Invalid currency "%s": use an ISO 4217 code such as EUR.', $code));
            }
        }

        if (null !== $name && '' === trim($name)) {
            throw new \InvalidArgumentException('The shop name cannot be empty.');
        }
    }

    public static function fromFile(string $file): self
    {
        if (!is_file($file)) {
            throw new \InvalidArgumentException(\sprintf('No manifest at "%s": write one with "castor dev-kit:init".', $file));
        }

        $data = Yaml::parseFile($file);

        return self::fromArray(\is_array($data) ? $data : []);
    }

    /**
     * @param array<mixed> $data the parsed starter.yaml
     */
    public static function fromArray(array $data): self
    {
        $unknown = array_diff(array_keys($data), ['shop', 'dataset', 'theme', 'locales', 'currencies', 'plugins']);

        if ([] !== $unknown) {
            throw new \InvalidArgumentException(\sprintf('Unknown setting(s) in the manifest: %s.', implode(', ', $unknown)));
        }

        $shop = self::map($data, 'shop');
        $theme = $data['theme'] ?? null;
        $theme = \is_string($theme) ? ['name' => $theme] : self::map($data, 'theme');
        $header = self::map($theme, 'header');
        $locales = self::map($data, 'locales');

        return new self(
            name: self::string($shop, 'name'),
            dataset: self::string($data, 'dataset'),
            theme: self::string($theme, 'name'),
            menu: self::string($header, 'menu'),
            search: self::string($header, 'search'),
            locale: self::string($locales, 'default'),
            locales: self::list($locales, 'enabled'),
            currencies: self::list($data, 'currencies'),
            plugins: self::list($data, 'plugins'),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $header = array_filter(['menu' => $this->menu, 'search' => $this->search], static fn(?string $value): bool => null !== $value);
        $theme = array_filter(['name' => $this->theme, 'header' => [] === $header ? null : $header], static fn(mixed $value): bool => null !== $value);
        $locales = array_filter(['default' => $this->locale, 'enabled' => $this->locales], static fn(mixed $value): bool => null !== $value);

        return array_filter([
            'shop' => null === $this->name ? null : ['name' => $this->name],
            'dataset' => $this->dataset,
            'theme' => [] === $theme ? null : $theme,
            'locales' => [] === $locales ? null : $locales,
            'currencies' => $this->currencies,
            'plugins' => $this->plugins,
        ], static fn(mixed $value): bool => null !== $value);
    }

    public function toYaml(bool $withComment = true): string
    {
        $yaml = Yaml::dump($this->toArray(), 4, 2, Yaml::DUMP_EMPTY_ARRAY_AS_SEQUENCE);

        if (!$withComment) {
            return $yaml;
        }

        return "# The wanted state of the local shop: \"castor dev-kit:apply\" brings the instance to it.\n"
            . "# Remove a setting to leave it as it is. See docs/dev-kit/index.md.\n"
            . $yaml;
    }

    public function baseCurrency(): ?string
    {
        return $this->currencies[0] ?? null;
    }

    private static function assertLocale(string $code): void
    {
        if (!preg_match('/^[a-z]{2}(_[A-Z]{2})?$/', $code)) {
            throw new \InvalidArgumentException(\sprintf('Invalid locale "%s": use a code such as fr_FR.', $code));
        }
    }

    /**
     * @param array<mixed> $data
     *
     * @return array<mixed>
     */
    private static function map(array $data, string $key): array
    {
        $value = $data[$key] ?? [];

        if (!\is_array($value)) {
            throw new \InvalidArgumentException(\sprintf('"%s" must be a map.', $key));
        }

        return $value;
    }

    /**
     * @param array<mixed> $data
     */
    private static function string(array $data, string $key): ?string
    {
        $value = $data[$key] ?? null;

        if (null !== $value && !\is_string($value)) {
            throw new \InvalidArgumentException(\sprintf('"%s" must be a string.', $key));
        }

        return $value;
    }

    /**
     * @param array<mixed> $data
     *
     * @return list<string>|null
     */
    private static function list(array $data, string $key): ?array
    {
        if (!\array_key_exists($key, $data)) {
            return null;
        }

        $value = $data[$key] ?? [];

        if (!\is_array($value) || !array_is_list($value) || array_filter($value, static fn(mixed $item): bool => !\is_string($item)) !== []) {
            throw new \InvalidArgumentException(\sprintf('"%s" must be a list of strings.', $key));
        }

        /** @var list<string> $value */
        return array_values(array_unique($value));
    }
}
