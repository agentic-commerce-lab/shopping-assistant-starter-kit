<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Support;

use Shopware\Core\Checkout\Customer\CustomerEntity;
use Swag\AssistantStarterKit\Entity\Conversation\ConversationEntity;
use Swag\AssistantStarterKit\Entity\TraceEvent\TraceEventCollection;
use Swag\AssistantStarterKit\Entity\TraceEvent\TraceEventEntity;

/**
 * Conversation rows as the DAL hands them to the export, built by hand.
 *
 * Extracted from `TraceExportSummaryTest` when a second question — which model answered — needed
 * the same three builders. The same reasoning as {@see UsesCatalogFixture} (ruling R38): a fixture
 * every test rebuilds itself is a fixture that drifts, and the drift shows up as two tests
 * disagreeing about what a conversation looks like rather than as a failure.
 *
 * Nothing here touches a database. The export's own reading is behind
 * {@see \Swag\AssistantStarterKit\Core\Trace\Export\TraceExportSource}, so everything the exported
 * file says about a conversation is derivable from entities assembled in memory.
 */
trait BuildsConversationRows
{
    private static function customer(string $first, string $last): CustomerEntity
    {
        $customer = new CustomerEntity();
        $customer->setId('01a01b4f9e2270a1b2c3d4e5f6a7b8c9');
        $customer->setFirstName($first);
        $customer->setLastName($last);

        return $customer;
    }

    /** @param list<TraceEventEntity> $events */
    private static function conversation(
        array $events = [],
        int $totalMs = 0,
        ?CustomerEntity $customer = null,
    ): ConversationEntity {
        $conversation = new ConversationEntity();
        $conversation->setId('01a0337f413070afa3b29711739324a2');
        $conversation->setSalesChannelId('01a01b4af6567284ac9eeb3616598ac3');
        $conversation->setTurnCount(1);
        $conversation->setOutcome('product_shown');
        $conversation->setTotalMs($totalMs);
        $conversation->setCreatedAt(new \DateTimeImmutable('2026-08-24T10:12:04+00:00'));
        $conversation->setEvents(new TraceEventCollection($events));

        if ($customer !== null) {
            $conversation->setCustomerId($customer->getId());
            $conversation->setCustomer($customer);
        }

        return $conversation;
    }

    /** @param array<string, mixed> $payload */
    private static function event(int $elapsedMs, string $stage, array $payload = []): TraceEventEntity
    {
        static $seq = 0;

        $event = new TraceEventEntity();
        $event->setId(bin2hex(random_bytes(16)));
        $event->setSeq($seq++);
        $event->setStage($stage);
        $event->setElapsedMs($elapsedMs);
        $event->setPayload($payload);

        return $event;
    }
}
