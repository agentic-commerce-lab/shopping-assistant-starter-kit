<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Command;

use Symfony\Component\Console\Input\InputInterface;

/**
 * Parses this command's input into a value object, so {@see BenchmarkCommand} holds CLI concerns and
 * nothing else. Mirrors {@see ProbeRequest}.
 */
final class BenchmarkRequest
{
    /**
     * The committed query list.
     *
     * Fixed rather than sampled, because a benchmark whose inputs move cannot be compared with its
     * own previous run — and the spec asks for a table that is re-runnable and quotable. The shapes
     * mirror this project's journeys: narrow, broad, plural, variant-bearing, price-shaped,
     * misspelled, multi-word, and one that should match nothing.
     */
    public const DEFAULT_TERMS = [
        'jacket',
        'shoes',
        'gloves',
        'blue jersey',
        'water bottle',
        'jaket',
        'winter cycling jacket',
        'zzzznotathing',
    ];

    /** How many repetitions one run may ask for. A mistyped flag should not run for an hour. */
    private const MAX_REPETITIONS = 1_000;

    /**
     * @param list<string> $terms
     * @param list<string> $cardIds
     */
    private function __construct(
        public readonly string $salesChannelId,
        public readonly array $terms,
        public readonly int $repetitions,
        public readonly array $cardIds,
        public readonly string $shopLabel,
    ) {}

    /**
     * `$default` is resolved lazily on the right-hand side of `?:` below, so a command given an
     * explicit `--sales-channel` never queries the shop and never sees
     * {@see NoDefaultSalesChannelException}.
     *
     * @throws \InvalidArgumentException when `--repetitions` is outside 1..1000
     */
    public static function fromInput(InputInterface $input, DefaultSalesChannel $default): self
    {
        $repetitions = (int) self::string($input, 'repetitions', '20');

        if ($repetitions < 1 || $repetitions > self::MAX_REPETITIONS) {
            throw new \InvalidArgumentException(\sprintf(
                '--repetitions must be between 1 and %d, got %d.',
                self::MAX_REPETITIONS,
                $repetitions,
            ));
        }

        $terms = self::strings($input, 'term');

        return new self(
            salesChannelId: self::string($input, 'sales-channel', '') ?: $default->id(),
            terms: [] === $terms ? self::DEFAULT_TERMS : $terms,
            repetitions: $repetitions,
            cardIds: self::strings($input, 'card-id'),
            shopLabel: self::string($input, 'label', 'shop'),
        );
    }

    private static function string(InputInterface $input, string $option, string $fallback): string
    {
        $value = $input->getOption($option);

        return \is_string($value) && '' !== $value ? $value : $fallback;
    }

    /** @return list<string> */
    private static function strings(InputInterface $input, string $option): array
    {
        $value = $input->getOption($option);

        if (!\is_array($value)) {
            return [];
        }

        return array_values(array_filter(
            array_map(static fn(mixed $item): string => \is_string($item) ? $item : '', $value),
            static fn(string $item): bool => '' !== $item,
        ));
    }
}
