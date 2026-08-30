<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Trace;

use Swag\AssistantStarterKit\Core\Context\ShoppingMode;
use Swag\AssistantStarterKit\Entity\Conversation\ConversationEntity;
use Swag\AssistantStarterKit\Entity\TraceEvent\TraceEventEntity;

/**
 * Turns a plain row array — the shape {@see ConversationScopeTest}'s repository doubles store — back
 * into the entity `DalConversationStore` expects to read. Split out of that test class purely to keep
 * its own method count under mago's `too-many-methods` budget: this mapping belongs to no one test.
 *
 * The query-narrowing half of the same double lives in {@see FakeTraceEventSearch} instead of here —
 * a second split, not laziness: folding both back into one class merely relocated the same total
 * complexity mago's `cyclomatic-complexity` rule already flagged, rather than reducing it.
 *
 * The `mixed`-to-typed narrowing these two mapping methods lean on lives in a third sibling,
 * {@see FakeRepositoryRowValues}, for the same reason again — see that class's docblock.
 */
final class FakeRepositoryRows
{
    /**
     * @param array<string, mixed> $row
     */
    public static function toConversationEntity(array $row): ConversationEntity
    {
        $entity = new ConversationEntity();
        $entity->setId((string) $row['id']);
        $entity->setSalesChannelId((string) $row['salesChannelId']);
        $entity->setCustomerId(FakeRepositoryRowValues::nullableString($row['customerId'] ?? null));
        $entity->setScopeType((string) ($row['scopeType'] ?? ShoppingMode::Guest->value));
        $entity->setCommercialEmployeeId(FakeRepositoryRowValues::nullableString($row['commercialEmployeeId'] ?? null));
        $entity->setCommercialOrganisationId(FakeRepositoryRowValues::nullableString(
            $row['commercialOrganisationId'] ?? null,
        ));
        $entity->setLocale(FakeRepositoryRowValues::nullableString($row['locale'] ?? null));
        $entity->setTurnCount((int) ($row['turnCount'] ?? 0));
        $entity->setOutcome(FakeRepositoryRowValues::nullableString($row['outcome'] ?? null));
        $entity->setTotalMs((int) ($row['totalMs'] ?? 0));
        $entity->setTranscript(FakeRepositoryRowValues::nullableList($row['transcript'] ?? []));

        return $entity;
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function toTraceEventEntity(array $row): TraceEventEntity
    {
        $entity = new TraceEventEntity();
        $entity->setId((string) $row['id']);
        $entity->setConversationId((string) $row['conversationId']);
        $entity->setSeq((int) $row['seq']);
        $entity->setStage((string) $row['stage']);
        $entity->setElapsedMs((int) $row['elapsedMs']);
        $entity->setPayload(FakeRepositoryRowValues::nullableMap($row['payload'] ?? null));

        return $entity;
    }
}
