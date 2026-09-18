<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Commerce\Dto;

use Shopware\Core\Checkout\Order\OrderStates;

/**
 * What a tool asks the order history for, already bounded and already validated.
 *
 * A DTO rather than three parameters, for {@see ProductQuery}'s reason: the next filter is added
 * here instead of at every call site, and a gateway implementation cannot receive them in the wrong
 * order.
 *
 * ## Both filters are model-supplied, which is what this class is for
 *
 * They are the only values in this feature that travel from the model into a database query, so
 * neither crosses the gateway boundary unchecked. {@see self::of()} is the only way to build one.
 *
 * - **`state` is a closed vocabulary** — Shopware's own technical names, which are stable and
 *   untranslated, unlike the labels a shop shows. Anything else is **dropped**, not refused: an
 *   unknown state is a model inventing a word, and answering the unfiltered question is closer to
 *   what the shopper asked than failing the turn over a word they never said.
 * - **`withinDays` is clamped**, for the reason every other bound here is: a model asking for ten
 *   years of history wants the list, not an error it will spend another call on.
 *
 * Neither is interpolated anywhere. The DAL builds `EqualsFilter` and `RangeFilter` from them, so a
 * value that survived validation still cannot become SQL.
 */
final readonly class OrderQuery
{
    private const MAX_LIMIT = 10;

    /** Two years. Longer than anybody asks for conversationally, and short of "all of it". */
    private const MAX_DAYS = 730;

    /** Shopware's own, from {@see OrderStates}. Not the shop's labels, which are translated. */
    private const STATES = [
        OrderStates::STATE_OPEN,
        OrderStates::STATE_IN_PROGRESS,
        OrderStates::STATE_COMPLETED,
        OrderStates::STATE_CANCELLED,
    ];

    private function __construct(
        public int $limit,
        public ?int $withinDays,
        public ?string $state,
    ) {}

    public static function of(int $limit, ?int $withinDays, ?string $state): self
    {
        return new self(
            limit: max(1, min($limit, self::MAX_LIMIT)),
            withinDays: $withinDays === null ? null : max(1, min($withinDays, self::MAX_DAYS)),
            state: self::knownState($state),
        );
    }

    private static function knownState(?string $state): ?string
    {
        if ($state === null) {
            return null;
        }

        $normalised = strtolower(trim($state));

        return \in_array($normalised, self::STATES, true) ? $normalised : null;
    }
}
