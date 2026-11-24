<?php

declare(strict_types=1);

namespace OpenColibri\Starter\DevKit\Tests\Unit;

use OpenColibri\Starter\DevKit\Manifest;
use OpenColibri\Starter\DevKit\Plan;
use OpenColibri\Starter\DevKit\Step;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Plan::class)]
#[CoversClass(Step::class)]
final class PlanTest extends TestCase
{
    private Manifest $current;

    protected function setUp(): void
    {
        $this->current = new Manifest(
            name: 'Web store',
            dataset: 'video-games',
            theme: 'arena',
            menu: 'inline',
            search: 'none',
            locale: 'en_US',
            locales: ['en_US'],
            currencies: ['EUR'],
            plugins: ['refund'],
        );
    }

    public function testTheSameStateNeedsNoStep(): void
    {
        static::assertTrue(Plan::between($this->current, $this->current)->isEmpty());
        static::assertTrue(Plan::between($this->current, new Manifest())->isEmpty());
    }

    public function testAHeaderChangeDoesNotRebuildTheTheme(): void
    {
        $plan = Plan::between($this->current, new Manifest(theme: 'arena', search: 'icon'));

        static::assertSame([['sylius:theme:header', '--search=icon']], self::commands($plan));
        static::assertSame(Step::COST_INSTANT, $plan->steps[0]->cost);
    }

    public function testANewThemeIsSetUpWithItsHeader(): void
    {
        $plan = Plan::between($this->current, new Manifest(theme: 'roastery', menu: 'below'));

        static::assertSame([['sylius:theme:setup', 'roastery', '--menu=below']], self::commands($plan));
        static::assertSame(Step::COST_DOWNTIME, $plan->steps[0]->cost);
    }

    public function testTheSyliusLookTakesNoHeaderOption(): void
    {
        $plan = Plan::between($this->current, new Manifest(theme: 'default', menu: 'below'));

        static::assertSame([['sylius:theme:setup', 'default']], self::commands($plan));
    }

    public function testChannelSettingsGoInOneStep(): void
    {
        $plan = Plan::between($this->current, new Manifest(name: 'Maison', locale: 'fr_FR', locales: ['fr_FR', 'en_US'], currencies: ['EUR', 'USD']));

        static::assertSame([['dev-kit:channel', '--name=Maison', '--locales=fr_FR,en_US', '--default-locale=fr_FR', '--currencies=EUR,USD']], self::commands($plan));
    }

    public function testLocalesAreComparedAsASet(): void
    {
        $current = new Manifest(locales: ['en_US', 'fr_FR']);

        static::assertTrue(Plan::between($current, new Manifest(locales: ['fr_FR', 'en_US']))->isEmpty());
    }

    public function testANewBaseCurrencyIsAChange(): void
    {
        $current = new Manifest(currencies: ['EUR', 'USD']);

        static::assertSame([['dev-kit:channel', '--currencies=USD,EUR']], self::commands(Plan::between($current, new Manifest(currencies: ['USD', 'EUR']))));
    }

    public function testItWarnsAboutLocalesTheDatasetsAreNotTranslatedInto(): void
    {
        $plan = Plan::between($this->current, new Manifest(locales: ['en_US', 'de_DE']));

        static::assertStringContainsString('no product shows in de_DE', implode("\n", $plan->steps[0]->details));
    }

    public function testStepsRunInOrder(): void
    {
        $plan = Plan::between($this->current, new Manifest(name: 'Maison', dataset: 'roastery', theme: 'roastery', plugins: ['refund', 'altcha']));

        static::assertSame(['plugins', 'channel', 'theme', 'dataset'], array_map(static fn(Step $step): string => $step->key, $plan->steps));
        static::assertFalse($plan->isDestructive());
    }

    public function testPluginRemovalIsDestructiveAndRunsFirst(): void
    {
        $plan = Plan::between($this->current, new Manifest(plugins: ['altcha']), removablePlugins: ['refund']);

        static::assertSame([['sylius:plugin:remove', 'refund'], ['sylius:plugin:add', 'altcha']], self::commands($plan));
        static::assertTrue($plan->isDestructive());
    }

    public function testAPluginWithoutRemoverCannotBeDropped(): void
    {
        $this->expectExceptionMessage('No remover for refund');

        Plan::between($this->current, new Manifest(plugins: []));
    }

    public function testTheSyliusFixturesResetTheChannelSettings(): void
    {
        $plan = Plan::between($this->current, new Manifest(name: 'Web store', dataset: 'sylius', locales: ['en_US']));

        static::assertSame([['sylius:fixtures'], ['dev-kit:channel', '--name=Web store', '--locales=en_US']], self::commands($plan));
        static::assertTrue($plan->isDestructive());
    }

    public function testCommandLinesAreQuoted(): void
    {
        $plan = Plan::between($this->current, new Manifest(name: "L'atelier"));

        static::assertSame("castor dev-kit:channel '--name=L'\\''atelier'", $plan->steps[0]->commandLine());
    }

    /**
     * @return list<list<string>>
     */
    private static function commands(Plan $plan): array
    {
        return array_map(static fn(Step $step): array => $step->command, $plan->steps);
    }
}
