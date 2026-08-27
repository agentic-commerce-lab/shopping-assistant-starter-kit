<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Eval\Assertion;

use Swag\AssistantStarterKit\Core\Agent\AssistantTurn;
use Swag\AssistantStarterKit\Core\Trace\TraceRecorder;
use Swag\AssistantStarterKit\Eval\Assertion;
use Swag\AssistantStarterKit\Eval\AssertionResult;

/**
 * The rendered cards include something from every group the journey names.
 *
 * ## The failure it locks out
 *
 * Measured 2026-08-26 on the fashion catalogue: asked what to wear to a wedding, the assistant
 * described dresses **and** suits and rendered five cards, all of them men's suits. It had searched
 * twice and the shop renders only the most recent search, so the dress cards were discarded — the
 * shopper read about one thing and was shown another.
 *
 * Every existing assertion passed straight through that. The dresses are real products, so
 * `no_invented_product` was satisfied, and nothing in the suite noticed the shopper could not see what
 * was being described.
 *
 * Groups are **id prefixes**, not category paths, on purpose. `ProductCard::categoryPath` is populated
 * by the fixture and left empty by the DAL, so an assertion that read it would pass in the eval suite
 * and mean nothing about production — the exact trap this project's spec decision O1 was written to
 * avoid. A trap product's id is deterministic and gateway-independent.
 */
final class RenderedIdsFromEach implements Assertion
{
    public function name(): string
    {
        return 'rendered_ids_from_each';
    }

    /** @param array<string, mixed> $expectations */
    public function evaluate(AssistantTurn $turn, TraceRecorder $trace, array $expectations): AssertionResult
    {
        $declared = $expectations['groups'] ?? null;
        $groups = \is_array($declared) ? IdPrefixGroups::parse($declared) : null;

        if ($groups === null) {
            return new AssertionResult(
                $this->name(),
                false,
                'Declare "groups" as at least two non-empty lists of id prefixes. One group asserts nothing.',
            );
        }

        $ids = array_map(static fn($card): string => $card->id, $turn->cards);
        $missing = IdPrefixMatch::unrepresented($groups, $ids);

        if ($missing === []) {
            return new AssertionResult(
                $this->name(),
                true,
                \sprintf('every group is represented among %d rendered card(s).', \count($ids)),
            );
        }

        return new AssertionResult(
            $this->name(),
            false,
            \sprintf(
                'nothing rendered for group(s) %s; rendered: %s',
                implode(', ', $missing),
                $ids === [] ? '(none)' : implode(', ', $ids),
            ),
        );
    }

    /** Not safety: a one-sided row is a worse answer, not an unsafe one, and model variance is real. */
    public function isSafety(): bool
    {
        return false;
    }
}
