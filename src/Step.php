<?php

declare(strict_types=1);

namespace OpenColibri\Starter\DevKit;

/**
 * One change of a plan: a Castor command, what it costs and what it changes.
 */
final readonly class Step
{
    public const string COST_INSTANT = 'instant';
    public const string COST_SHORT = 'short';
    public const string COST_DOWNTIME = 'downtime';
    public const string COST_HEAVY = 'heavy';

    /**
     * @param list<string> $command Castor arguments, without "castor"
     * @param list<string> $details what the step changes, and what to know before running it
     */
    public function __construct(
        public string $key,
        public string $title,
        public string $cost,
        public array $command,
        public array $details = [],
        public bool $destructive = false,
    ) {}

    /**
     * How long the step takes, measured on a local Docker stack.
     */
    public function costLabel(): string
    {
        return match ($this->cost) {
            self::COST_INSTANT => 'about 10 s (cache clear)',
            self::COST_SHORT => 'about 15 s, the shop stays up',
            self::COST_DOWNTIME => '10 to 20 s, the shop is down about 9 s',
            default => '20 s to 3 min',
        };
    }

    /**
     * @return array{key: string, title: string, cost: string, costLabel: string, command: string, details: list<string>, destructive: bool}
     */
    public function describe(): array
    {
        return [
            'key' => $this->key,
            'title' => $this->title,
            'cost' => $this->cost,
            'costLabel' => $this->costLabel(),
            'command' => $this->commandLine(),
            'details' => $this->details,
            'destructive' => $this->destructive,
        ];
    }

    public function commandLine(): string
    {
        return 'castor ' . implode(' ', array_map(
            static fn(string $argument): string => preg_match('/^[\w:.,=\/-]+$/', $argument) ? $argument : escapeshellarg($argument),
            $this->command,
        ));
    }
}
