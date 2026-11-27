<?php

declare(strict_types=1);

namespace OpenColibri\Starter\DevKit\Tui;

use OpenColibri\Starter\DevKit\Step;

/**
 * Draws the plan of dev-kit:apply: a card per step with its cost, or the errors of the manifest.
 */
final class PlanView
{
    /**
     * @return list<string>
     */
    public static function draw(ShopState $state, int $width, int $height, bool $focused = false): array
    {
        $inner = $width - 4;
        [$errors, $plan] = $state->plan();
        $steps = $plan->steps ?? [];
        $lines = [
            Draw::spread(Draw::fg('▶', Draw::ACCENT) . ' ' . Draw::bold('PLAN'), Draw::fg((string) \count($steps), Draw::GRAY), $inner),
            Draw::rule($inner),
        ];

        if ([] !== $errors) {
            foreach ($errors as $error) {
                $lines = [...$lines, ...Draw::box(Draw::wrap('✘ ' . $error, $inner - 4, Draw::RED), $inner, Draw::RED)];
            }
        } elseif ([] === $steps) {
            $lines[] = '';
            $lines[] = Draw::fg('✔ The shop matches this state.', Draw::GREEN);
            $lines[] = Draw::fg('Change a setting to see what', Draw::DIM);
            $lines[] = Draw::fg('dev-kit:apply would run.', Draw::DIM);
        } else {
            $destructive = \count(array_filter($steps, static fn(Step $step): bool => $step->destructive));
            $lines[] = Draw::fg(\sprintf('dev-kit:apply runs %d %s', \count($steps), 1 === \count($steps) ? 'step' : 'steps'), Draw::GRAY)
                . ($destructive > 0 ? Draw::fg(' · ⚠ deletes data', Draw::RED) : '');
            $lines[] = '';

            foreach ($steps as $index => $step) {
                $lines = [...$lines, ...Draw::box(self::step($step, $index + 1, $inner - 4), $inner, $step->destructive ? Draw::RED : Draw::BORDER)];
            }
        }

        if (\count($lines) > $height - 2) {
            $lines = [...\array_slice($lines, 0, $height - 3), Draw::fg('↓ more: widen the terminal', Draw::GRAY)];
        }

        return Draw::box($lines, $width, $focused ? Draw::ACCENT : Draw::BORDER, height: $height);
    }

    /**
     * @return list<string>
     */
    private static function step(Step $step, int $number, int $width): array
    {
        $card = [
            Draw::fg((string) $number, Draw::PINK) . '  ' . Draw::bold($step->title),
            ...Draw::wrap('◷ ' . $step->costLabel(), $width, match ($step->cost) {
                Step::COST_INSTANT => Draw::GREEN,
                Step::COST_SHORT => Draw::CYAN,
                Step::COST_DOWNTIME => Draw::YELLOW,
                default => Draw::RED,
            }),
        ];

        foreach ($step->details as $detail) {
            $card = [...$card, ...Draw::wrap($detail, $width)];
        }

        if ($step->destructive) {
            $card[] = Draw::fg('⚠ deletes data', Draw::RED);
        }

        return $card;
    }
}
