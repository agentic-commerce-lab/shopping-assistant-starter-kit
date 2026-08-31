<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Eval\Assertion;

use Swag\AssistantStarterKit\Eval\Assertion;

/**
 * Maps a journey's assertion name to its implementation for {@see \Swag\AssistantStarterKit\Eval\JourneyAssertions}.
 * The unconditional throw in the default arm is deliberate and load-bearing: a typo in
 * a journey file (e.g. "no_invented_produtc") must fail loudly at load time, never be
 * silently dropped or skipped.
 *
 * ## The class-name escape hatch
 *
 * The short names above are a closed vocabulary, and stay one. But a plugin that contributes a
 * grounded tool has to be able to assert something about *that* tool, or an extension is testable
 * by us and not by its author — so a journey may also name a class directly:
 *
 * ```php
 * 'assertions' => [\Acme\Assistant\Eval\FitmentDeclared::class => ['forModel' => '2019']],
 * ```
 *
 * **A DI tag cannot serve this.** Journeys parse through a static call chain in a plain PHPUnit
 * process — {@see \Swag\AssistantStarterKit\Eval\Journey::fromFile()}, no kernel, no container —
 * so there is nothing to read a tag from at the moment a name must resolve.
 *
 * The fail-loud property survives, which is the only reason this is acceptable: a mistyped short
 * name still hits the default arm, and a mistyped class name is not a loadable class, so it hits it
 * too. The contract is narrow on purpose — implement {@see Assertion}, take no constructor
 * arguments — and each half is checked with its own message rather than folded into "unknown".
 */
final class AssertionRegistry
{
    public static function resolve(string $name, string $journeyId): Assertion
    {
        return match ($name) {
            'no_invented_product' => new NoInventedProduct(),
            'price_matches_source' => new PriceMatchesSource(),
            'stock_matches_source' => new StockMatchesSource(),
            'blocklist_respected' => new BlocklistRespected(),
            'no_unbacked_price_in_prose' => new NoUnbackedPriceInProse(),
            'no_unbacked_property_claim_in_prose' => new NoUnbackedPropertyClaimInProse(),
            'cart_contains' => new CartContains(),
            'cart_quantity_stored' => new CartQuantityStored(),
            'rendered_ids_exactly' => new RenderedIdsExactly(),
            'no_absence_claim_in_prose' => new NoAbsenceClaimInProse(),
            'no_handoff_claim_in_prose' => new NoHandoffClaimInProse(),
            'escalated_with_handoff' => new EscalatedWithHandoff(),
            'tool_calls_at_most' => new ToolCallsAtMost(),
            'questions_at_most' => new QuestionsAtMost(),
            'renders_at_least' => new RendersAtLeast(),
            'rendered_ids_from_each' => new RenderedIdsFromEach(),
            'retrieved_shop_info' => new RetrievedShopInfo(),
            'no_unsupported_period_in_prose' => new NoUnsupportedPeriodInProse(),
            'rendered_family_spread' => new RenderedFamilySpread(),
            default => self::byClassName($name, $journeyId),
        };
    }

    /**
     * Resolves a name that is not one of ours, or explains precisely why it cannot be.
     */
    private static function byClassName(string $name, string $journeyId): Assertion
    {
        if (!class_exists($name)) {
            throw new \InvalidArgumentException(\sprintf(
                'Journey "%s" declares unknown assertion "%s". Use one of this plugin\'s assertion '
                . 'names, or the fully qualified class name of your own %s implementation.',
                $journeyId,
                $name,
                Assertion::class,
            ));
        }

        if (!is_subclass_of($name, Assertion::class)) {
            throw new \InvalidArgumentException(\sprintf(
                'Journey "%s" declares assertion class "%s", which does not implement %s.',
                $journeyId,
                $name,
                Assertion::class,
            ));
        }

        try {
            $constructor = (new \ReflectionClass($name))->getConstructor();
        } catch (\ReflectionException $e) {
            // Unreachable while class_exists() above has returned true, and converted rather than
            // left to escape because every failure here must name the journey that caused it.
            throw new \InvalidArgumentException(
                \sprintf('Journey "%s" declares assertion class "%s", which cannot be reflected.', $journeyId, $name),
                previous: $e,
            );
        }

        // Checked rather than left to fatal: an ArgumentCountError names a parameter, not the journey
        // that asked for it, and journeys resolve in a loop over every committed file.
        if ($constructor !== null && $constructor->getNumberOfRequiredParameters() > 0) {
            throw new \InvalidArgumentException(\sprintf(
                'Journey "%s" declares assertion class "%s", which requires constructor arguments. '
                . 'An assertion is constructed by name and configured from its expectations block, '
                . 'so it must be constructible with none.',
                $journeyId,
                $name,
            ));
        }

        return new $name();
    }
}
