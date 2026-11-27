<?php

declare(strict_types=1);

namespace OpenColibri\Starter\DevKit\Tests\Unit\Tui;

use OpenColibri\Starter\DevKit\Manifest;
use OpenColibri\Starter\DevKit\Tui\ShopState;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ShopState::class)]
final class ShopStateTest extends TestCase
{
    public function testItStartsFromTheManifest(): void
    {
        $state = self::state(new Manifest(name: 'Maison Roastery', theme: 'roastery'));

        static::assertSame('Maison Roastery', $state->wanted('name'));
        static::assertNull($state->wanted('dataset'));
        static::assertSame('video-games', $state->effective('dataset'));
        static::assertSame('—', $state->show('dataset'));
        static::assertSame('', $state->show('dataset', plain: true));
        static::assertSame('video-games', $state->show('dataset', current: true));
    }

    public function testAThemeKeepsAHeaderItSupports(): void
    {
        $state = self::state(new Manifest(theme: 'arena', menu: 'below', search: 'icon'));

        static::assertNull($state->set('theme', 'roastery'));
        static::assertSame('below', $state->wanted('menu'));
        static::assertSame('icon', $state->wanted('search'));
    }

    public function testAThemeMovesTheMenuToAPlacementItSupports(): void
    {
        $state = self::state(new Manifest(theme: 'arena', menu: 'inline'));

        static::assertNull($state->set('theme', 'drive'));
        static::assertSame('bar', $state->wanted('menu'));
        // The default placement of the theme, as the manifest did not manage the search.
        static::assertSame('inline', $state->wanted('search'));
    }

    public function testTheHeaderPlacementsCycle(): void
    {
        $state = self::state(new Manifest(theme: 'arena', menu: 'inline', search: 'icon'));

        static::assertNull($state->cycle('menu'));
        static::assertSame('below', $state->wanted('menu'));
        static::assertNull($state->cycle('search'));
        static::assertSame('none', $state->wanted('search'));
        static::assertNull($state->cycle('search', -1));
        static::assertSame('icon', $state->wanted('search'));
    }

    public function testTheDefaultLocaleFollowsTheLocales(): void
    {
        $state = self::state(new Manifest(locale: 'en_US', locales: ['en_US']));

        static::assertNull($state->set('locales', ['fr_FR', 'de_DE']));
        static::assertSame('fr_FR', $state->wanted('locale'));
        static::assertNull($state->cycle('locale'));
        static::assertSame('de_DE', $state->wanted('locale'));
        static::assertSame(['en_US*', 'fr_FR, de_DE*'], \array_slice($state->describe('locales'), 0, 2));
    }

    public function testAnInvalidValueIsRefusedAndLeavesTheStateAsItWas(): void
    {
        $state = self::state(new Manifest(currencies: ['EUR']));

        static::assertSame('Invalid currency "euro": use an ISO 4217 code such as EUR.', $state->set('currencies', ['euro']));
        static::assertSame(['EUR'], $state->wanted('currencies'));
    }

    public function testAPluginIsAddedInOrder(): void
    {
        $state = self::state(new Manifest(plugins: ['refund']));

        static::assertNull($state->toggle('altcha'));
        static::assertSame(['altcha', 'refund'], $state->plugins());
        static::assertTrue($state->changed('plugins'));
        // The manifest no longer lists seo, which the shop has: applying it removes seo.
        static::assertSame(['2 installed', '+ altcha  − seo', 'altcha, refund'], $state->describe('plugins'));
    }

    public function testAPluginIsRemovedOnlyWithARemover(): void
    {
        $state = self::state(new Manifest(plugins: ['refund', 'seo']), removable: ['seo']);

        static::assertSame('The dev-kit cannot remove refund: it has no remover.', $state->toggle('refund'));
        static::assertSame(['refund', 'seo'], $state->plugins());
        static::assertNull($state->toggle('seo'));
        static::assertSame(['refund'], $state->plugins());
    }

    public function testUnmanagedPluginsKeepWhatTheShopHas(): void
    {
        $state = self::state(new Manifest());

        static::assertSame(['refund', 'seo'], $state->plugins());
        static::assertFalse($state->changed('plugins'));
        static::assertNull($state->toggle('altcha'));
        static::assertSame(['altcha', 'refund', 'seo'], $state->plugins());
    }

    public function testThePlanFollowsTheChanges(): void
    {
        $state = self::state(new Manifest(theme: 'arena'));

        static::assertSame([], $state->plan()[1]?->steps);

        $state->set('theme', 'roastery');
        $state->set('name', 'Maison Roastery');
        $titles = array_map(static fn($step): string => $step->title, $state->plan()[1]->steps ?? []);

        static::assertSame(['Update the channel', 'Apply the roastery theme'], $titles);
    }

    public function testTheErrorsOfTheManifestReplaceThePlan(): void
    {
        $state = self::state(new Manifest(), validate: static fn(Manifest $manifest): array => null === $manifest->dataset ? [] : ['Unknown dataset.']);

        $state->set('dataset', 'nowhere');

        static::assertSame([['Unknown dataset.'], null], $state->plan());
    }

    public function testItDescribesASetting(): void
    {
        $state = self::state(new Manifest(dataset: 'roastery'));

        static::assertSame(['Video Games', 'Roastery', '16 products · 6 collections'], $state->describe('dataset'));
        static::assertSame(['Web store', null, 'The name of the default channel'], $state->describe('name'));
    }

    /**
     * @param \Closure(Manifest): list<string>|null $validate
     * @param list<string>                          $removable
     */
    private static function state(Manifest $wanted, ?\Closure $validate = null, array $removable = []): ShopState
    {
        $current = new Manifest(
            name: 'Web store',
            dataset: 'video-games',
            theme: 'arena',
            menu: 'inline',
            search: 'none',
            locale: 'en_US',
            locales: ['en_US'],
            currencies: ['EUR'],
            plugins: ['refund', 'seo'],
        );

        return new ShopState($current, $wanted, $validate ?? static fn(Manifest $manifest): array => [], self::catalog(), $removable);
    }

    /**
     * @return array{themes: list<array<string, mixed>>, datasets: list<array<string, mixed>>, plugins: list<array<string, mixed>>}
     */
    public static function catalog(): array
    {
        return [
            'themes' => [
                ['name' => 'canvas', 'menu' => 'inline', 'search' => 'none', 'category' => ['name' => 'general', 'label' => 'General purpose']],
                ['name' => 'drive', 'menu' => 'bar', 'search' => 'inline', 'category' => ['name' => 'gear', 'label' => 'Gear and sports']],
                ['name' => 'roastery', 'menu' => 'inline', 'search' => 'none', 'category' => ['name' => 'food', 'label' => 'Food and drink']],
                ['name' => 'arena', 'menu' => 'inline', 'search' => 'none', 'category' => ['name' => 'culture', 'label' => 'Games, music and culture']],
            ],
            'datasets' => [
                ['slug' => 'roastery', 'name' => 'Roastery', 'description' => 'Coffees', 'builtIn' => true, 'productCount' => 16, 'collectionCount' => 6],
                ['slug' => 'video-games', 'name' => 'Video Games', 'description' => 'Consoles', 'builtIn' => true, 'productCount' => 18, 'collectionCount' => 6],
                ['slug' => 'my-shop', 'name' => 'My shop', 'description' => 'Mine', 'builtIn' => false, 'productCount' => 3, 'collectionCount' => 1],
            ],
            'plugins' => [
                ['name' => 'stripe', 'description' => 'Stripe', 'category' => ['name' => 'payment', 'label' => 'Payment gateways']],
                ['name' => 'altcha', 'description' => 'ALTCHA', 'category' => ['name' => 'security', 'label' => 'Security and privacy']],
                ['name' => 'seo', 'description' => 'SEO', 'category' => ['name' => 'catalog', 'label' => 'Catalog and content']],
                ['name' => 'sitemap', 'description' => 'Sitemap', 'category' => ['name' => 'catalog', 'label' => 'Catalog and content']],
                ['name' => 'refund', 'description' => 'Refunds', 'category' => ['name' => 'orders', 'label' => 'Orders and customers']],
                ['name' => 'bugsnag', 'description' => 'BugSnag', 'category' => null],
            ],
        ];
    }
}
