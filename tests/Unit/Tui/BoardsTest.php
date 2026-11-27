<?php

declare(strict_types=1);

namespace OpenColibri\Starter\DevKit\Tests\Unit\Tui;

use OpenColibri\Starter\DevKit\Manifest;
use OpenColibri\Starter\DevKit\Tui\Boards;
use OpenColibri\Starter\DevKit\Tui\Draw;
use OpenColibri\Starter\DevKit\Tui\ShopState;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Tui\Ansi\AnsiUtils;

#[CoversClass(Boards::class)]
final class BoardsTest extends TestCase
{
    public function testTheThemesMakeAColumnPerCategoryTheDefaultOneFirst(): void
    {
        $board = self::boards(new Manifest(theme: 'roastery'))->themes();

        static::assertSame(['General purpose', 'Gear and sports', 'Food and drink', 'Games, music and culture'], array_column($board, 'title'));
        static::assertSame(['default', 'canvas'], array_column($board[0]['cards'], 'value'));
        static::assertSame([2, 0], Boards::locate($board, 'roastery'));
        static::assertSame(Draw::GREEN, $board[2]['cards'][0]['border'] ?? null);
    }

    public function testAThemeCardSaysWhatTheShopRunsAndWhatIsWanted(): void
    {
        $board = self::boards(new Manifest(theme: 'roastery'))->themes();

        static::assertStringContainsString('★ wanted', self::text($board[2]['cards'][0], 40));
        static::assertStringContainsString('○ current', self::text($board[3]['cards'][0], 40));
        // A narrow card keeps the icon alone, and the placements on two lines.
        static::assertSame(['roastery         ★', 'menu   inline', 'search none'], self::lines($board[2]['cards'][0], 18));
    }

    public function testTheCatalogsAreSyliusReadyMadeAndYours(): void
    {
        $board = self::boards(new Manifest())->catalogs();

        static::assertSame(['Sylius', 'Ready-made', 'Yours'], array_column($board, 'title'));
        static::assertSame([['sylius'], ['roastery', 'video-games'], ['my-shop']], array_map(static fn(array $column): array => array_column($column['cards'], 'value'), $board));
        static::assertSame([1, 1], Boards::locate($board, 'video-games'));
        static::assertSame([0, 0], Boards::locate($board, 'nowhere'));
    }

    public function testThePluginsMakeOneListByCategory(): void
    {
        $board = self::boards(new Manifest(plugins: ['refund', 'seo', 'sitemap']))->plugins();

        static::assertCount(1, $board);
        static::assertSame(['stripe', 'altcha', 'seo', 'sitemap', 'refund', 'bugsnag'], array_column($board[0]['cards'], 'value'));
        static::assertSame(['Payment gateways', 'Security and privacy', 'Catalog and content', 'Catalog and content', 'Orders and customers', 'Other'], array_column($board[0]['cards'], 'section'));
        static::assertStringContainsString('+ add', self::row($board[0]['cards'][3]));
        static::assertStringContainsString('● on', self::row($board[0]['cards'][4]));
    }

    public function testAWideScreenSplitsThePluginsWithoutCuttingACategory(): void
    {
        $board = self::boards(new Manifest())->for('plugins', Boards::TWO_LISTS_WIDTH);

        static::assertCount(2, $board);
        static::assertSame(['stripe', 'altcha', 'seo', 'sitemap'], array_column($board[0]['cards'], 'value'));
        static::assertSame(['refund', 'bugsnag'], array_column($board[1]['cards'], 'value'));
    }

    public function testARemovedPluginIsMarked(): void
    {
        $board = self::boards(new Manifest(plugins: ['refund']))->plugins();

        static::assertStringContainsString('− remove', self::row($board[0]['cards'][2]));
    }

    private static function boards(Manifest $wanted): Boards
    {
        $current = new Manifest(dataset: 'video-games', theme: 'arena', plugins: ['refund', 'seo']);

        return new Boards(new ShopState($current, $wanted, static fn(Manifest $manifest): array => [], ShopStateTest::catalog(), []));
    }

    /**
     * @param array<string, mixed> $card
     *
     * @return list<string>
     */
    private static function lines(array $card, int $width): array
    {
        static::assertInstanceOf(\Closure::class, $card['lines'] ?? null);

        return array_map(static fn(string $line): string => rtrim(AnsiUtils::stripAnsiCodes($line)), ($card['lines'])($width));
    }

    /**
     * @param array<string, mixed> $card
     */
    private static function text(array $card, int $width): string
    {
        return implode("\n", self::lines($card, $width));
    }

    /**
     * @param array<string, mixed> $card
     */
    private static function row(array $card): string
    {
        static::assertInstanceOf(\Closure::class, $card['row'] ?? null);

        return AnsiUtils::stripAnsiCodes(($card['row'])(80));
    }
}
