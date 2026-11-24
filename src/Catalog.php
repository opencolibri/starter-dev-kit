<?php

declare(strict_types=1);

namespace OpenColibri\Starter\DevKit;

use OpenColibri\Starter\Core\Dataset\Datasets;
use OpenColibri\Starter\Datasets\DatasetPreview;
use OpenColibri\Starter\Plugins\Tasks\PluginTasks;
use OpenColibri\Starter\Themes\HeaderLayout;
use OpenColibri\Starter\Themes\ThemePreview;
use OpenColibri\Starter\Themes\Themes;

/**
 * What a manifest can pick, for the dev-kit interface: the themes with their thumbnails and header placements, the
 * datasets with their preview, the plugins with their description.
 */
final readonly class Catalog
{
    /**
     * Full-page homepage screenshots of the themes (docs/images of the colibri-starter checkout), when there.
     */
    public const string SCREENSHOTS_DIR = 'docs/images';

    /**
     * @return array{themes: list<array<string, mixed>>, datasets: list<array<string, mixed>>, plugins: list<array<string, mixed>>}
     */
    public static function describe(): array
    {
        $themes = [];

        foreach (array_keys(Themes::installers()) as $theme) {
            $layout = HeaderLayout::describe($theme, HeaderLayout::resolve($theme));
            $themes[] = [
                'name' => $theme,
                'label' => ucfirst(str_replace('_', ' ', $theme)),
                'menu' => $layout['menu'],
                'search' => $layout['search'],
                'menus' => $layout['menus'],
                'searches' => $layout['searches'],
                'thumbnails' => self::byScheme(static fn(string $scheme): ?string => ThemePreview::path($theme, $scheme)),
                'screenshots' => self::byScheme(static function (string $scheme) use ($theme): ?string {
                    $file = \sprintf('%s/%s/%s-homepage-normal-%s.webp', getcwd(), self::SCREENSHOTS_DIR, str_replace('_', '-', $theme), $scheme);

                    return is_file($file) ? $file : null;
                }),
            ];
        }

        $datasets = [];

        foreach (Datasets::all() as $dataset) {
            $datasets[] = $dataset->describe() + ['preview' => DatasetPreview::path($dataset->slug)];
        }

        $plugins = [];
        $removable = PluginTasks::removerNames();

        foreach (PluginTasks::descriptions() as $name => $description) {
            $plugins[] = ['name' => $name, 'description' => $description, 'removable' => \in_array($name, $removable, true)];
        }

        return ['themes' => $themes, 'datasets' => $datasets, 'plugins' => $plugins];
    }

    /**
     * @param callable(string): ?string $path
     *
     * @return array<string, string|null>
     */
    private static function byScheme(callable $path): array
    {
        $paths = [];

        foreach (ThemePreview::SCHEMES as $scheme) {
            $paths[$scheme] = $path($scheme);
        }

        return $paths;
    }
}
