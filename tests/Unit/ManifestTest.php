<?php

declare(strict_types=1);

namespace OpenColibri\Starter\DevKit\Tests\Unit;

use OpenColibri\Starter\DevKit\Manifest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

#[CoversClass(Manifest::class)]
final class ManifestTest extends TestCase
{
    public function testItReadsEverySetting(): void
    {
        $manifest = Manifest::fromArray(Yaml::parse(<<<'YAML'
            shop:
              name: 'Maison Roastery'
            dataset: roastery
            theme:
              name: roastery
              header: { menu: below, search: icon }
            locales:
              default: fr_FR
              enabled: [fr_FR, en_US]
            currencies: [EUR, USD]
            plugins: [altcha]
            YAML));

        static::assertSame('Maison Roastery', $manifest->name);
        static::assertSame('roastery', $manifest->dataset);
        static::assertSame('roastery', $manifest->theme);
        static::assertSame('below', $manifest->menu);
        static::assertSame('icon', $manifest->search);
        static::assertSame('fr_FR', $manifest->locale);
        static::assertSame(['fr_FR', 'en_US'], $manifest->locales);
        static::assertSame('EUR', $manifest->baseCurrency());
        static::assertSame(['altcha'], $manifest->plugins);
    }

    public function testSettingsLeftOutAreNotManaged(): void
    {
        $manifest = Manifest::fromArray(['theme' => 'canvas']);

        static::assertSame('canvas', $manifest->theme);
        static::assertNull($manifest->menu);
        static::assertNull($manifest->locales);
        static::assertNull($manifest->plugins);
        static::assertSame(['theme' => ['name' => 'canvas']], $manifest->toArray());
    }

    public function testAnEmptyPluginListIsManaged(): void
    {
        static::assertSame([], Manifest::fromArray(['plugins' => []])->plugins);
        static::assertSame([], Manifest::fromArray(['plugins' => null])->plugins);
    }

    public function testItRoundTripsThroughYaml(): void
    {
        $manifest = new Manifest(name: "L'atelier", dataset: 'teahouse', theme: 'teahouse', menu: 'bar', locale: 'en_US', locales: ['en_US'], currencies: ['GBP'], plugins: []);

        static::assertEquals($manifest, Manifest::fromArray(Yaml::parse($manifest->toYaml())));
    }

    public function testTheDefaultLocaleMustBeEnabled(): void
    {
        $this->expectExceptionMessage('The default locale "de_DE" must be one of the enabled locales (fr_FR).');

        new Manifest(locale: 'de_DE', locales: ['fr_FR']);
    }

    public function testItRejectsCodesThatAreNotLocalesOrCurrencies(): void
    {
        $this->expectExceptionMessage('Invalid currency "euro"');

        new Manifest(currencies: ['euro']);
    }

    public function testItRejectsLocalesThatCouldReachTheSql(): void
    {
        $this->expectExceptionMessage('Invalid locale');

        new Manifest(locales: ["fr_FR'); DROP TABLE sylius_order; --"]);
    }

    public function testItRejectsUnknownSettings(): void
    {
        $this->expectExceptionMessage('Unknown setting(s) in the manifest: payment.');

        Manifest::fromArray(['payment' => 'stripe']);
    }
}
