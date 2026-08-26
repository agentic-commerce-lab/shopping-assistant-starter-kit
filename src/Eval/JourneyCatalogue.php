<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Eval;

/**
 * Which catalogue a journey's expectations were written against.
 *
 * ## Why this is not a comment
 *
 * The four `scale_*` journeys carried their requirement in a header comment — `REQUIRES
 * ASSISTANT_EVAL_CATALOG=large` — and nothing enforced it. So a default eval run executed
 * `scale_family_beyond_window` against a twelve-product catalogue that has no thirty-variant family,
 * and paid real-model prices for a red result that meant nothing. A declared requirement is skipped
 * instead, before any credential is read.
 *
 * ## Why `any` is the default, and why that is the honest one
 *
 * Every journey written before this field existed runs against all catalogues by design. Spec decision
 * O10 keeps the twelve real products verbatim inside the generated catalogues precisely so those
 * journeys stay meaningful there — they are the control that tells a scale failure apart from a
 * rewritten expectation. Defaulting to `small` would silently stop running fifteen journeys at scale
 * and nobody would see it happen.
 *
 * ## Why an unknown name throws
 *
 * Same reason {@see Assertion\AssertionRegistry}'s default arm throws on an unknown assertion: a typo
 * must fail loudly at load time, never become a journey that quietly no longer runs. Note the
 * asymmetry with {@see \Swag\AssistantStarterKit\Tests\Fixtures\EvalCatalogue}, which falls BACK to
 * small on an unknown environment variable — there a typo is a selection and the cheap failure is to
 * run the small catalogue; here it is a claim, and a claim nobody checks is worthless.
 */
final readonly class JourneyCatalogue
{
    public const ANY = 'any';

    /** @var list<string> */
    public const KNOWN = [self::ANY, 'small', 'large', 'fashion'];

    private function __construct(
        private string $name,
    ) {}

    public static function parse(mixed $raw, string $journeyId): self
    {
        if (null === $raw) {
            return new self(self::ANY);
        }

        if (!\is_string($raw) || !\in_array($raw, self::KNOWN, strict: true)) {
            throw new \InvalidArgumentException(\sprintf(
                'Journey "%s" declares unknown catalog "%s"; expected one of %s.',
                $journeyId,
                \is_string($raw) ? $raw : get_debug_type($raw),
                implode(', ', self::KNOWN),
            ));
        }

        return new self($raw);
    }

    public function requires(string $catalogueName): bool
    {
        return self::ANY === $this->name || $this->name === $catalogueName;
    }

    public function name(): string
    {
        return $this->name;
    }
}
