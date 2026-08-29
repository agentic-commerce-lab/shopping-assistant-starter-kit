<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Trace;

use Swag\AssistantStarterKit\Core\Context\ShoppingContext;
use Swag\AssistantStarterKit\Core\Trace\ForeignConversationException;
use Swag\AssistantStarterKit\Core\Trace\TranscriptCodec;

/**
 * {@see InMemoryConversationStore}'s own version of
 * {@see \Swag\AssistantStarterKit\Core\Trace\ConversationScope}: the same match rule and the same two
 * guard clauses, applied to a token's recorded {@see ShoppingContext} directly instead of a DAL row —
 * the double stores the scope as-is, so there is no row to rebuild it from.
 *
 * Split out of `InMemoryConversationStore` for the same reason the real `ConversationScope` is split
 * out of `DalConversationStore`: mago's `cyclomatic-complexity` rule is class-scoped, and the
 * double's own guard clauses pushed it over budget once scoping landed there too.
 */
final class FakeConversationScope
{
    /**
     * @param list<array<string, mixed>> $turns
     *
     * @return list<\Swag\AssistantStarterKit\Core\Trace\ConversationTurn>
     */
    public static function historyOrEmpty(
        ?ShoppingContext $stored,
        ShoppingContext $context,
        TranscriptCodec $codec,
        array $turns,
        int $limit,
    ): array {
        if (!self::matches($stored, $context)) {
            // An unknown token and a foreign one get the same empty answer, on purpose: a
            // shopper-facing read must never disclose which case it was.
            return [];
        }

        return $codec->decodeAll(\array_slice($turns, -$limit));
    }

    /**
     * @throws ForeignConversationException if `$stored` is null or does not match `$context` — the
     *                                       same backstop {@see \Swag\AssistantStarterKit\Core\Trace\ConversationScope::assertMatches()}
     *                                       enforces for the real store.
     */
    public static function assertMatches(?ShoppingContext $stored, ShoppingContext $context): void
    {
        if (!self::matches($stored, $context)) {
            throw new ForeignConversationException();
        }
    }

    private static function matches(?ShoppingContext $stored, ShoppingContext $context): bool
    {
        return $stored instanceof ShoppingContext && $stored->matches($context);
    }
}
