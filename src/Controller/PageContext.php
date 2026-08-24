<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Controller;

/**
 * What the storefront says the current page is about: the open product, the browsed category, or
 * neither.
 *
 * Its own class for the reason {@see CardIdList} records — that folding a second request shape into
 * {@see ChatRequest} pushes its complexity past the gate, which is the gate correctly reporting that
 * one class had grown two jobs. This is a different subject from the chat request itself: the
 * message and the token are what the shopper sent, and these are what the *page* happened to be.
 *
 * **Shape only — nothing here is a trust decision.** Whether a product id names something this
 * shopper may see is settled later by resolving it through the catalogue scope, which is what
 * enforces the blocklist and the excluded categories. A category id is never resolved at all: it is
 * used only to narrow a search, and narrowing is safe by construction (P8).
 *
 * A malformed value yields null rather than rejecting the request. These are **hints**: a page
 * template emitting something unexpected must cost the shopper an optimisation, never their answer.
 */
final readonly class PageContext
{
    private function __construct(
        public ?string $productId,
        public ?string $categoryId,
    ) {}

    /**
     * @param array<string, mixed> $payload
     */
    public static function fromPayload(array $payload): self
    {
        return new self(
            productId: self::catalogueId($payload['productId'] ?? null),
            categoryId: self::catalogueId($payload['categoryId'] ?? null),
        );
    }

    /**
     * A 32-character hex catalogue id, or null.
     *
     * Validating the shape keeps a malformed value out of a repository lookup, exactly as
     * {@see ChatRequest} does for a conversation token — and for the same reason: the endpoint is
     * public.
     */
    private static function catalogueId(mixed $value): ?string
    {
        if (!\is_string($value)) {
            return null;
        }

        $id = trim($value);

        return preg_match(CardIdList::ID_PATTERN, $id) === 1 ? $id : null;
    }
}
