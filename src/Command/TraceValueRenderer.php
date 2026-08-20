<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Command;

/**
 * Renders one trace payload value as text.
 *
 * Split out of {@see TraceDumper} (cyclomatic-complexity) rather than suppressed. It owns the two
 * rules that decide whether a trace can be trusted by the person reading it:
 *
 * - **The four fields that are how this product lies are never shortened.** `filtersDropped`,
 *   `inventedProductIds`, `modelClaimsDiscarded` and `stockSource` print in full, however long.
 *   `ARCHITECTURE.md` requires that of the Administration; it has to hold here too, because this
 *   is the only trace reader that exists.
 * - **A shortened value says so.** An unmarked cut reads as the whole value, which is how a reader
 *   ends up believing a list was complete.
 */
final readonly class TraceValueRenderer
{
    /**
     * Keep in sync with `ARCHITECTURE.md`'s "Trace data model" section, which is the authority.
     */
    public const NEVER_ABBREVIATED = [
        'filtersDropped',
        'inventedProductIds',
        'modelClaimsDiscarded',
        'stockSource',
    ];

    private const MAX_VALUE_LENGTH = 400;

    public function render(string $key, mixed $value): string
    {
        $rendered = $this->stringify($value);

        if (\in_array($key, self::NEVER_ABBREVIATED, strict: true)) {
            return $rendered;
        }

        if (mb_strlen($rendered) <= self::MAX_VALUE_LENGTH) {
            return $rendered;
        }

        return \sprintf(
            '%s… (%d of %d characters shown)',
            mb_substr($rendered, 0, self::MAX_VALUE_LENGTH),
            self::MAX_VALUE_LENGTH,
            mb_strlen($rendered),
        );
    }

    private function stringify(mixed $value): string
    {
        return match (true) {
            \is_string($value) => $value,
            \is_bool($value) => $value ? 'true' : 'false',
            $value === null => 'null',
            \is_int($value), \is_float($value) => (string) $value,
            // json_encode rather than print_r or a cast: `variant.resolve` records a list of
            // attempt maps and `render` records card fields, and "Array" tells a reader nothing
            // about either.
            default => json_encode($value, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE) ?: '(unrenderable value)',
        };
    }
}
