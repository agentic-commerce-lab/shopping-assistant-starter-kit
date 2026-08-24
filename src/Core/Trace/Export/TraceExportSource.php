<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Trace\Export;

use Shopware\Core\Framework\Context;
use Swag\AssistantStarterKit\Entity\Conversation\ConversationEntity;

/**
 * Everything an export needs to read, behind one call.
 *
 * An interface for the reason {@see \Swag\AssistantStarterKit\Core\Agent\ChatTurnRunnerInterface} is
 * one: the controller's job is **policy** — the size bound, the refusals, the file name — and every
 * one of those has a failure mode that must be observable without a database.
 *
 * Mocking `EntityRepository` would be possible, but its `search()` returns an `EntitySearchResult`
 * whose own constructor takes six arguments including an `EntityCollection` and a `Criteria`. Every
 * test would then spend more lines building a return value than asserting the policy it exists to
 * check. This is also the honest dependency: the controller needs "load conversations for export",
 * not "a repository".
 *
 * Both halves come back together because they are read together — a conversation without its sales
 * channel's name exports an id where a merchant expects a shop.
 */
interface TraceExportSource
{
    /**
     * @param list<string> $ids
     *
     * @return array{conversations: list<ConversationEntity>, salesChannelNames: array<string, string>}
     *         `conversations` holds only the ids that resolved — the caller compares counts to learn
     *         how many were dropped. `salesChannelNames` maps id => name and may be missing an id,
     *         which the serialisers render as the id itself.
     */
    public function load(array $ids, Context $context): array;
}
