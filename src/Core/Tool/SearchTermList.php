<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Tool;

/**
 * The bound on `search_products`'s search terms: at most {@see self::MAX_TERMS}, each a non-empty
 * string within {@see self::MAX_LENGTH}, deduplicated case-insensitively.
 *
 * Its own class rather than a method on {@see Guard} for the reason that class's own docblock records:
 * a fourth method there pushed its aggregate cyclomatic complexity over this project's threshold, which
 * is why {@see VariantSelectionGuard} was split out. This is the same split for the same reason.
 *
 * **Reject, never coerce** — inherited from `Guard`. A silently truncated term list is a search the
 * model believes it made and did not.
 */
final class SearchTermList
{
    /**
     * The most search terms one call may carry, **`term` and `terms` counted together**.
     *
     * That the two share one budget was always true and was not written down anywhere the model
     * could read — see {@see \Swag\AssistantStarterKit\Tests\Core\Tool\SearchTermListBoundTest}
     * for what that cost.
     *
     * Three. The reason a list exists at all is an answer spanning two disjoint kinds of product
     * (measured: dresses and suits for a wedding), and three leaves room for a third without letting
     * the argument become a way to sweep the catalogue in one call. Each term is a full retrieval pass,
     * so this is also a cost bound.
     */
    public const MAX_TERMS = 3;

    /** Matches the single-term bound `Guard::boundedString()` applies to `term`. */
    public const MAX_LENGTH = 200;

    private function __construct() {}

    /**
     * @param ?string                      $term  the single-term argument, kept for the common case
     * @param ?array<array-key, mixed>     $terms the multi-term argument
     *
     * @return list<string> in the order given, `term` first; empty when neither was supplied
     */
    public static function of(?string $term, ?array $terms, string $name): array
    {
        $raw = $term === null ? [] : [$term];

        foreach ($terms ?? [] as $candidate) {
            if (!\is_string($candidate)) {
                throw new ToolArgumentException(\sprintf('Argument "%s" accepts strings only.', $name));
            }

            $raw[] = $candidate;
        }

        return self::normalise($raw, $name);
    }

    /**
     * @param list<string> $raw
     *
     * @return list<string>
     */
    private static function normalise(array $raw, string $name): array
    {
        $kept = [];
        $seen = [];

        foreach ($raw as $candidate) {
            $trimmed = trim($candidate);

            if ('' === $trimmed) {
                continue;
            }

            if (mb_strlen($trimmed) > self::MAX_LENGTH) {
                throw new ToolArgumentException(\sprintf(
                    'Argument "%s" exceeds %d characters.',
                    $name,
                    self::MAX_LENGTH,
                ));
            }

            $key = mb_strtolower($trimmed);

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $kept[] = $trimmed;
        }

        if (\count($kept) > self::MAX_TERMS) {
            // **Names the total and the other argument.** The old message read
            // `Argument "terms" accepts at most 3 terms.` for an overflow that a `term` alongside
            // three `terms` had caused — so it pointed at the wrong argument and stated a bound
            // that is not the one enforced. Measured with `openai/gpt-5-mini` on 2026-09-02: the
            // model was rejected, did not work out what to change, and the turn rendered nothing.
            // A model that reads only this sentence must be able to fix the call from it.
            throw new ToolArgumentException(\sprintf(
                'Argument "%s" accepts at most %d search terms in total, counting "term" when you '
                . 'pass it as well. Drop the extra terms and call the tool once more.',
                $name,
                self::MAX_TERMS,
            ));
        }

        return $kept;
    }
}
