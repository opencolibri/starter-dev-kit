<?php

declare(strict_types=1);

namespace OpenColibri\Starter\DevKit\Tui;

use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Widget\AbstractWidget;
use Symfony\Component\Tui\Widget\VerticallyExpandableInterface;

/**
 * The whole screen of the dev-kit TUI, drawn by a callback at the size of the terminal.
 */
final class Screen extends AbstractWidget implements VerticallyExpandableInterface
{
    private bool $expanded = true;

    /**
     * @param \Closure(int, int): list<string> $draw draws the screen at these columns and rows
     */
    public function __construct(
        private readonly \Closure $draw,
    ) {}

    public function expandVertically(bool $expand): static
    {
        $this->expanded = $expand;

        return $this;
    }

    public function isVerticallyExpanded(): bool
    {
        return $this->expanded;
    }

    public function render(RenderContext $context): array
    {
        return ($this->draw)($context->getColumns(), $context->getRows());
    }
}
