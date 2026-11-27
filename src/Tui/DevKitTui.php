<?php

declare(strict_types=1);

namespace OpenColibri\Starter\DevKit\Tui;

use OpenColibri\Starter\DevKit\Manifest;
use Symfony\Component\Tui\Ansi\ScreenBufferHtmlRenderer;
use Symfony\Component\Tui\Event\InputEvent;
use Symfony\Component\Tui\Input\Keybindings;
use Symfony\Component\Tui\Terminal\ScreenBuffer;
use Symfony\Component\Tui\Terminal\TerminalInterface;
use Symfony\Component\Tui\Terminal\VirtualTerminal;
use Symfony\Component\Tui\Tui;

/**
 * The dev-kit in the terminal: an overview and a board per setting (theme, catalog, plugins), with the plan of
 * dev-kit:apply next to them, recomputed on every change.
 *
 * It owns the screen, the keys and the manifest file; ShopState holds what changes, Boards and the views draw it.
 *
 * @phpstan-import-type OverviewCard from OverviewView
 */
final class DevKitTui
{
    public const string QUIT = 'quit';
    public const string APPLY = 'apply';

    private const array VIEWS = ['shop' => 'Overview', 'theme' => 'Theme', 'catalog' => 'Catalog', 'plugins' => 'Plugins', 'plan' => 'Plan'];

    /** Below this width, the plan is a view of its own instead of a sidebar. */
    private const int SIDEBAR_MIN_COLUMNS = 130;
    private const int SIDEBAR_WIDTH = 42;

    private readonly Boards $boards;
    private readonly BoardView $boardView;

    /** @var array<string, mixed>|null the manifest as saved in the file */
    private ?array $saved;

    private string $view = 'shop';

    /** @var array<string, array{int, int}> the column and row of the cursor of each view */
    private array $cursor = [];

    /** @var array{key: string, buffer: string, cursor: int}|null the value typed in an overview card */
    private ?array $editing = null;

    /** @var array{string, int}|null the message of the status bar and its color */
    private ?array $message = null;

    /** The action that waits for its key to be pressed again: apply (destructive steps) or quit (unsaved changes). */
    private ?string $pending = null;

    private string $outcome = self::QUIT;
    private bool $planShown = true;

    /** The size of the last drawing: the keys move in its layout. */
    private int $columns = 160;
    private int $mainWidth = 120;

    private Tui $tui;
    private Screen $screen;
    private readonly Keybindings $keybindings;

    public function __construct(
        private readonly ShopState $state,
        private readonly string $file,
    ) {
        $this->boards = new Boards($state);
        $this->boardView = new BoardView();
        $this->saved = is_file($file) ? $state->manifest()->toArray() : null;
        $this->keybindings = new Keybindings();
        $this->cursor['theme'] = Boards::locate($this->boards->themes(), $state->effective('theme'));
        $this->cursor['catalog'] = Boards::locate($this->boards->catalogs(), $state->effective('dataset'));
    }

    /**
     * Runs until the visitor quits or applies.
     *
     * @return self::QUIT|self::APPLY
     */
    public function run(?TerminalInterface $terminal = null): string
    {
        $this->start($terminal);
        $this->tui->run();

        return $this->outcome;
    }

    /**
     * The screen after these keys, as HTML: for the documentation, and to check the layout without a terminal.
     *
     * @param list<string> $keys raw terminal input, one key each
     */
    public function snapshot(int $columns, int $rows, array $keys = []): string
    {
        $terminal = new VirtualTerminal($columns, $rows);
        $this->start($terminal);
        // The layout the keys move in (the cards of a row, the lists of plugins) comes from a drawing.
        $this->draw($columns, $rows);

        foreach ($keys as $key) {
            $this->tui->handleInput($key);
        }

        $this->tui->requestRender(true);
        $this->tui->processRender();
        $screen = new ScreenBuffer($columns, $rows);
        $screen->write($terminal->getOutput());
        $this->tui->stop();

        return (new ScreenBufferHtmlRenderer())->convert($screen);
    }

    public function manifest(): Manifest
    {
        return $this->state->manifest();
    }

    private function start(?TerminalInterface $terminal): void
    {
        $this->tui = null === $terminal ? new Tui(keybindings: $this->keybindings) : new Tui(terminal: $terminal, keybindings: $this->keybindings);
        $this->screen = new Screen($this->draw(...));
        $this->tui->add($this->screen);
        $this->tui->addListener(function (InputEvent $event): void {
            $event->stopPropagation();
            $this->input($event->getData());
            $this->screen->invalidate();
            $this->tui->requestRender();
        });
    }

    // Keys

    private function input(string $data): void
    {
        $parser = $this->keybindings->getParser();

        if ($parser->isKeyRelease($data)) {
            return;
        }

        $key = $parser->parse($data)['key'] ?? null;
        $text = 1 === mb_strlen($data) && !preg_match('/[\x00-\x1F\x7F]/', $data) ? $data : $parser->decodePrintable($data);

        if (null !== $this->editing) {
            $this->edit($key, $text);

            return;
        }

        $pending = $this->pending;
        $this->pending = null;
        $views = $this->views();
        $index = (int) array_search($this->view, $views, true);

        match (true) {
            'q' === $text, 'ctrl+c' === $key => $this->quit($pending),
            's' === $text => $this->save(),
            'a' === $text => $this->apply($pending),
            'p' === $text => $this->planShown = !$this->planShown,
            'tab' === $key => $this->view = $views[($index + 1) % \count($views)],
            'shift+tab' === $key => $this->view = $views[($index - 1 + \count($views)) % \count($views)],
            null !== $text && ctype_digit($text) && isset($views[(int) $text - 1]) => $this->view = $views[(int) $text - 1],
            'up' === $key, 'k' === $text => $this->move(0, -1),
            'down' === $key, 'j' === $text => $this->move(0, 1),
            'left' === $key, 'h' === $text => $this->move(-1, 0),
            'right' === $key, 'l' === $text => $this->move(1, 0),
            'd' === $text && 'shop' === $this->view && 'locales' === $this->card()['key'] => $this->report($this->state->cycle('locale')),
            'm' === $text && 'theme' === $this->view => $this->cycleHeader('menu'),
            'f' === $text && 'theme' === $this->view => $this->cycleHeader('search'),
            'enter' === $key, 'space' === $key, ' ' === $text => $this->activate(),
            default => null,
        };
    }

    private function edit(?string $key, ?string $text): void
    {
        \assert(null !== $this->editing);
        ['key' => $field, 'buffer' => $buffer, 'cursor' => $cursor] = $this->editing;

        if ('escape' === $key) {
            $this->editing = null;

            return;
        }

        if ('enter' === $key) {
            $this->editing = null;
            $value = trim($buffer);
            $this->report($this->state->set($field, match ($field) {
                'locales', 'currencies' => '' === $value ? null : array_values(array_unique(array_filter(array_map('trim', explode(',', $value))))),
                default => '' === $value ? null : $value,
            }));

            return;
        }

        [$buffer, $cursor] = match (true) {
            'backspace' === $key => $cursor > 0 ? [mb_substr($buffer, 0, $cursor - 1) . mb_substr($buffer, $cursor), $cursor - 1] : [$buffer, $cursor],
            'delete' === $key => [mb_substr($buffer, 0, $cursor) . mb_substr($buffer, $cursor + 1), $cursor],
            'left' === $key => [$buffer, max(0, $cursor - 1)],
            'right' === $key => [$buffer, min(mb_strlen($buffer), $cursor + 1)],
            'home' === $key, 'ctrl+a' === $key => [$buffer, 0],
            'end' === $key, 'ctrl+e' === $key => [$buffer, mb_strlen($buffer)],
            'ctrl+u' === $key => ['', 0],
            null !== $text => [mb_substr($buffer, 0, $cursor) . $text . mb_substr($buffer, $cursor), $cursor + 1],
            default => [$buffer, $cursor],
        };

        $this->editing = ['key' => $field, 'buffer' => $buffer, 'cursor' => $cursor];
    }

    private function move(int $columns, int $rows): void
    {
        [$column, $row] = $this->cursor[$this->view] ?? [0, 0];

        if ('plan' === $this->view) {
            return;
        }

        if ('shop' === $this->view) {
            $index = $row + $columns + $rows * OverviewView::columns($this->mainWidth);
            $this->cursor['shop'] = [0, $index >= 0 && $index < \count(OverviewView::CARDS) ? $index : $row];

            return;
        }

        $board = $this->boards->for($this->view, $this->mainWidth);
        $column = max(0, min(\count($board) - 1, $column + $columns));
        $row = max(0, min(\count($board[$column]['cards'] ?? []) - 1, $row + $rows));
        $this->cursor[$this->view] = [$column, $row];
    }

    private function activate(): void
    {
        if ('shop' === $this->view) {
            $card = $this->card();

            match ($card['action']) {
                'edit' => $this->editing = ['key' => $card['key'], 'buffer' => $buffer = $this->state->show($card['key'], plain: true) ?: $this->state->show($card['key'], plain: true, current: true), 'cursor' => mb_strlen($buffer)],
                'save' => $this->save(),
                default => $this->view = $card['action'],
            };

            return;
        }

        if ('plan' === $this->view) {
            return;
        }

        [$column, $row] = $this->cursor[$this->view] ?? [0, 0];
        $value = $this->boards->for($this->view, $this->mainWidth)[$column]['cards'][$row]['value'] ?? null;

        if (null === $value) {
            return;
        }

        $this->report(match ($this->view) {
            'theme' => $this->state->set('theme', $value),
            'catalog' => $this->state->set('dataset', $value),
            default => $this->state->toggle($value),
        });
    }

    /**
     * @param 'menu'|'search' $key
     */
    private function cycleHeader(string $key): void
    {
        if (!$this->report($this->state->cycle($key))) {
            return;
        }

        $this->message = [\sprintf('● The header of %s: menu %s · search %s', $this->state->effective('theme'), $this->state->effective('menu'), $this->state->effective('search')), Draw::ACCENT];
    }

    /**
     * Shows why a change was refused, or clears the message.
     *
     * @return bool whether the change was made
     */
    private function report(?string $error): bool
    {
        $this->message = null === $error ? null : ['✘ ' . $error, Draw::RED];

        return null === $error;
    }

    /**
     * @return OverviewCard
     */
    private function card(): array
    {
        return OverviewView::CARDS[$this->cursor['shop'][1] ?? 0];
    }

    // Manifest

    private function save(): void
    {
        if ([] !== $errors = $this->state->plan()[0]) {
            $this->message = ['✘ Fix the manifest first: ' . $errors[0], Draw::RED];

            return;
        }

        file_put_contents($this->file, $this->state->manifest()->toYaml());
        $this->saved = $this->state->manifest()->toArray();
        $this->message = [\sprintf('✔ Saved %s', $this->file), Draw::GREEN];
    }

    private function apply(?string $pending): void
    {
        [$errors, $plan] = $this->state->plan();

        if ([] !== $errors) {
            $this->message = ['✘ Fix the manifest first: ' . $errors[0], Draw::RED];

            return;
        }

        if (null === $plan || $plan->isEmpty()) {
            $this->message = ['✔ Nothing to apply: the shop already matches.', Draw::GREEN];

            return;
        }

        if ($plan->isDestructive() && self::APPLY !== $pending) {
            $this->pending = self::APPLY;
            $this->message = ['⚠ Some steps delete data: press a again to run the plan.', Draw::RED];

            return;
        }

        $this->save();
        $this->outcome = self::APPLY;
        $this->tui->stop();
    }

    private function quit(?string $pending): void
    {
        if ($this->dirty() && self::QUIT !== $pending) {
            $this->pending = self::QUIT;
            $this->message = ['⚠ The changes are not saved: press q again to quit without them, s to save.', Draw::YELLOW];

            return;
        }

        $this->outcome = self::QUIT;
        $this->tui->stop();
    }

    private function dirty(): bool
    {
        try {
            return $this->state->manifest()->toArray() !== $this->saved;
        } catch (\InvalidArgumentException) {
            return true;
        }
    }

    // Screen

    private function wide(): bool
    {
        return $this->columns >= self::SIDEBAR_MIN_COLUMNS && $this->planShown;
    }

    /**
     * @return list<string>
     */
    private function views(): array
    {
        return array_keys(array_filter(self::VIEWS, fn(string $label, string $view): bool => 'plan' !== $view || !$this->wide(), \ARRAY_FILTER_USE_BOTH));
    }

    /**
     * @return list<string>
     */
    private function draw(int $columns, int $rows): array
    {
        $this->columns = $columns;

        if ('plan' === $this->view && $this->wide()) {
            $this->view = 'shop';
        }

        $height = max(6, $rows - 6);
        $sidebar = $this->wide() ? self::SIDEBAR_WIDTH : 0;
        $this->mainWidth = $main = $columns - ($sidebar > 0 ? $sidebar + 1 : 0);

        $body = match ($this->view) {
            'shop' => OverviewView::draw($this->state, $this->file, $this->dirty(), $this->cursor['shop'][1] ?? 0, $this->editing, $main, $height),
            'plan' => PlanView::draw($this->state, $main, $height, focused: true),
            default => $this->boardView->draw($this->boards->for($this->view, $main), $this->view, $this->cursor[$this->view] ?? [0, 0], $main, $height),
        };

        if ($sidebar > 0) {
            $body = Draw::columns([$body, PlanView::draw($this->state, $sidebar, $height)], [$main, $sidebar]);
        }

        return [$this->header($columns), '', ...$body, '', ...$this->footer($columns)];
    }

    private function header(int $width): string
    {
        $name = $this->state->show('name', plain: true) ?: $this->state->show('name', plain: true, current: true) ?: 'Local shop';
        $left = Draw::badge('DEV-KIT', Draw::ACCENT) . '  ' . Draw::bold($name) . Draw::fg('  ·  ' . $this->file, Draw::GRAY);
        $changes = [
            'shop' => $this->state->changed('name') || $this->state->changed('locales') || $this->state->changed('locale') || $this->state->changed('currencies'),
            'theme' => $this->state->changed('theme') || $this->state->changed('menu') || $this->state->changed('search'),
            'catalog' => $this->state->changed('dataset'),
            'plugins' => $this->state->changed('plugins'),
            'plan' => false,
        ];
        $tabs = [];

        foreach ($this->views() as $index => $view) {
            $label = ($index + 1) . ' ' . self::VIEWS[$view];
            $tab = $view === $this->view ? Draw::bold(Draw::underline($label)) : Draw::fg($label, Draw::GRAY);
            $tabs[] = $tab . ($changes[$view] ? Draw::fg('•', Draw::ACCENT) : ' ');
        }

        return Draw::spread($left, implode('  ', $tabs), $width);
    }

    /**
     * @return list<string>
     */
    private function footer(int $width): array
    {
        [$errors, $plan] = $this->state->plan();
        $message = $this->message ?? match (true) {
            [] !== $errors => ['✘ ' . $errors[0], Draw::RED],
            null === $plan || $plan->isEmpty() => ['✔ The shop matches this state', Draw::GREEN],
            default => [\sprintf('● %d %s to apply', \count($plan->steps), 1 === \count($plan->steps) ? 'step' : 'steps'), Draw::ACCENT],
        };

        $keys = match (true) {
            null !== $this->editing => ['enter' => 'keep', 'esc' => 'cancel', '←/→' => 'cursor', 'ctrl+u' => 'clear'],
            'shop' === $this->view => ['arrows' => 'card', 'enter' => $this->card()['hint'], ...('locales' === $this->card()['key'] ? ['d' => 'next default language'] : []), 'tab' => 'next view'],
            'theme' === $this->view => ['←/→' => 'column', '↑/↓' => 'card', 'enter' => 'pick', 'm' => 'menu place', 'f' => 'search place', 'tab' => 'next view'],
            'plan' === $this->view => ['tab' => 'next view'],
            default => ['←/→' => 'column', '↑/↓' => 'card', 'plugins' === $this->view ? 'space' : 'enter' => 'plugins' === $this->view ? 'add / remove' : 'pick', 'tab' => 'next view'],
        };

        $file = match (true) {
            null === $this->saved => Draw::fg($this->file . '  ○ new', Draw::YELLOW),
            $this->dirty() => Draw::fg($this->file . '  ● unsaved', Draw::YELLOW),
            default => Draw::fg($this->file . '  ✔ saved', Draw::GREEN),
        };

        $global = ['s' => 'save', 'a' => 'save & apply', ...($this->columns >= self::SIDEBAR_MIN_COLUMNS ? ['p' => $this->wide() ? 'hide plan' : 'show plan'] : []), '1-' . \count($this->views()) => 'views', 'q' => 'quit'];

        return [
            Draw::fit(Draw::fg($message[0], $message[1]), $width),
            Draw::spread(Draw::keys($keys), Draw::fg($this->position(), Draw::GRAY), $width),
            Draw::spread(Draw::keys($global), $file, $width),
        ];
    }

    private function position(): string
    {
        if (\in_array($this->view, ['shop', 'plan'], true)) {
            return 'shop' === $this->view ? $this->card()['title'] : '';
        }

        [$column, $row] = $this->cursor[$this->view] ?? [0, 0];
        $board = $this->boards->for($this->view, $this->mainWidth)[$column] ?? null;

        if (null === $board || [] === $board['cards']) {
            return '';
        }

        $card = $board['cards'][$row] ?? [];

        return \sprintf('%s · %s %d/%d', $card['value'] ?? '', $card['section'] ?? $board['title'], $row + 1, \count($board['cards']));
    }
}
