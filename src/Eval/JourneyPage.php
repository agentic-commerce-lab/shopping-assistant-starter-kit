<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Eval;

/**
 * Where the shopper is standing for the duration of a journey: the product they have open, the
 * category they are browsing, or neither.
 *
 * The same two ids the storefront sends, and only those, because that is the whole contract the
 * chat endpoint has. A journey able to declare anything else would be describing a page the
 * storefront cannot produce.
 *
 * **Unknown keys are refused, not ignored** — the rule {@see JourneyConfig} already applies to its
 * own block, for the identical reason: a key nothing reads makes the journey a claim about a page it
 * was not run on, and the cheapest way to make that claim false is a typo.
 *
 * **The 32-hex shape {@see \Swag\AssistantStarterKit\Controller\PageContext} enforces is
 * deliberately not enforced here.** That class parses an untrusted HTTP payload; this one reads
 * committed source, and the catalogue these journeys run against is the fixture, whose ids look like
 * `fx-026-blue-m`. The two validate different things because they guard different risks.
 */
final readonly class JourneyPage
{
    private function __construct(
        public ?string $productId,
        public ?string $categoryId,
    ) {}

    public static function parse(mixed $page, string $journeyId): self
    {
        if ($page === null) {
            return new self(null, null);
        }

        if (!\is_array($page)) {
            throw new \InvalidArgumentException(\sprintf(
                'Journey "%s" must declare "page" as an array of ids.',
                $journeyId,
            ));
        }

        $unknown = array_diff(array_keys($page), ['productId', 'categoryId']);

        if ($unknown !== []) {
            throw new \InvalidArgumentException(\sprintf(
                'Journey "%s" declares page key(s) %s, which nothing reads. '
                . 'The storefront sends a product id and a category id, and nothing else.',
                $journeyId,
                implode(', ', array_map(static fn(mixed $key): string => '"' . (string) $key . '"', $unknown)),
            ));
        }

        return new self(
            self::id($page['productId'] ?? null, 'productId', $journeyId),
            self::id($page['categoryId'] ?? null, 'categoryId', $journeyId),
        );
    }

    private static function id(mixed $value, string $key, string $journeyId): ?string
    {
        if ($value === null) {
            return null;
        }

        if (!\is_string($value) || $value === '') {
            throw new \InvalidArgumentException(\sprintf(
                'Journey "%s" has a non-string or empty page "%s".',
                $journeyId,
                $key,
            ));
        }

        return $value;
    }
}
