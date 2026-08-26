<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Eval\Assertion;

/**
 * The `retrieve.shopinfo` events of one run, summed.
 *
 * Its own class because Mago's complexity budget is per class and {@see RetrievedShopInfo} needs its
 * own for the decision it makes. Narrowing untyped trace payloads is loops and type checks, and it
 * belongs beside neither the decision nor the trace.
 */
final readonly class ShopInfoRetrievals
{
    /**
     * @param list<string> $scores formatted, in the order they were recorded
     */
    private function __construct(
        public int $retrievals,
        public int $accepted,
        public array $scores,
    ) {}

    /**
     * @param list<array<string, mixed>> $payloads
     */
    public static function of(array $payloads): self
    {
        $accepted = 0;
        $scores = [];

        foreach ($payloads as $payload) {
            $accepted += \is_int($payload['accepted'] ?? null) ? $payload['accepted'] : 0;
            $scores = [...$scores, ...self::scoresIn($payload)];
        }

        return new self(\count($payloads), $accepted, $scores);
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return list<string>
     */
    private static function scoresIn(array $payload): array
    {
        $raw = $payload['scores'] ?? null;
        $scores = [];

        foreach (\is_array($raw) ? $raw : [] as $score) {
            if (\is_float($score) || \is_int($score)) {
                $scores[] = \sprintf('%.4f', $score);
            }
        }

        return $scores;
    }
}
