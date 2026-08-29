<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Trace;

use Swag\AssistantStarterKit\Core\Context\ShoppingContext;
use Swag\AssistantStarterKit\Core\Context\ShoppingMode;
use Swag\AssistantStarterKit\Entity\Conversation\ConversationEntity;

/**
 * Turns a stored conversation row into the {@see ShoppingContext} it was opened under, and compares
 * it against a presented one.
 *
 * Split out of `DalConversationStore` for the same reason documented on
 * {@see \Swag\AssistantStarterKit\Core\Tool\CartCorrectionNote}'s split: mago's
 * `cyclomatic-complexity` rule is class-scoped and sums every method's own complexity, and the
 * row-to-context mapping plus its two call sites (`history()` and `append()`) pushed
 * `DalConversationStore` over the project's threshold. Nothing here changes behaviour — only which
 * class owns the mapping and the comparison.
 */
final class ConversationScope
{
    /**
     * The scope a conversation row was opened under, rebuilt from its own stored columns.
     *
     * Built with `ShoppingMode::from()` rather than a stored enum: `scope_type` is a plain string
     * column (see `ConversationDefinition`), and this is the one place that turns it back into the
     * type {@see ShoppingContext::matches()} compares.
     */
    public static function of(ConversationEntity $conversation): ShoppingContext
    {
        return new ShoppingContext(
            mode: ShoppingMode::from($conversation->getScopeType()),
            salesChannelId: $conversation->getSalesChannelId(),
            customerId: $conversation->getCustomerId(),
            employeeId: $conversation->getCommercialEmployeeId(),
            organisationId: $conversation->getCommercialOrganisationId(),
        );
    }

    /**
     * Whether `$context` may read or append to `$conversation`.
     *
     * A missing row is never a match: there is nothing to compare against, and both `history()`
     * (an unknown token) and `append()` (a backstop against a skipped validation) need exactly that
     * answer for a null row.
     */
    public static function matches(?ConversationEntity $conversation, ShoppingContext $context): bool
    {
        return $conversation instanceof ConversationEntity && self::of($conversation)->matches($context);
    }

    /**
     * The turns `DalConversationStore::history()` should return: empty when the row is missing or
     * `$context` doesn't match the scope it was opened under, otherwise the requested tail of the
     * transcript.
     *
     * The guard clause itself lives here, not only the comparison it relies on — `history()`'s own
     * `if` counted against `DalConversationStore`'s complexity budget exactly as much as
     * {@see self::matches()} did, so moving only the comparison was not enough to clear it.
     *
     * @return list<ConversationTurn>
     */
    public static function historyOrEmpty(
        ?ConversationEntity $conversation,
        ShoppingContext $context,
        TranscriptCodec $codec,
        int $limit,
    ): array {
        if (!self::matches($conversation, $context)) {
            // An unknown token and a foreign one get the same empty answer, on purpose: a
            // shopper-facing read must never disclose which case it was.
            return [];
        }

        $turns = $codec->decodeAll(array_values($conversation?->getTranscript() ?? []));

        // The tail, not the head: the context window is bounded so history has to be, and "add that
        // to my cart" refers to the newest card set — so the OLDEST turns get dropped.
        return \array_slice($turns, -$limit);
    }

    /**
     * The backstop `DalConversationStore::append()` needs: throws {@see ForeignConversationException}
     * unless `$context` matches the scope `$conversation` was opened under. See that exception's own
     * docblock for why this is a throw rather than a silent no-op.
     */
    public static function assertMatches(?ConversationEntity $conversation, ShoppingContext $context): void
    {
        if (!self::matches($conversation, $context)) {
            throw new ForeignConversationException();
        }
    }
}
