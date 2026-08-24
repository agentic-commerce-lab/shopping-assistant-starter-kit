<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Trace\Export;

use PHPUnit\Framework\TestCase;
use Shopware\Core\Checkout\Customer\CustomerEntity;
use Swag\AssistantStarterKit\Core\Trace\Export\TraceExportRow;
use Swag\AssistantStarterKit\Entity\Conversation\ConversationEntity;
use Swag\AssistantStarterKit\Entity\TraceEvent\TraceEventCollection;
use Swag\AssistantStarterKit\Entity\TraceEvent\TraceEventEntity;

/**
 * The numbers in an exported file have to be the numbers on the screen. This is the one place they
 * are derived, so it is the one place that can make them disagree.
 */
final class TraceExportRowTest extends TestCase
{
    public function testTheShopKeepsWhatItSpentAndTheModelTheRest(): void
    {
        // A real turn's offsets, measured 2026-08-24: the shop works up to 22ms, the model thinks
        // until 3634, the shop finishes at 3656. Model time is the gaps nothing was recorded in.
        $row = TraceExportRow::of(self::conversation(events: [
            self::event(0, 'facet.probe'),
            self::event(22, 'prompt'),
            self::event(3634, 'validate'),
            self::event(3656, 'turn.end'),
        ], totalMs: 3656), 'Storefront');

        self::assertSame(3656, $row['totalMs']);
        self::assertSame(3612, $row['modelMs'], 'the gap between prompt and validate is the model');
        self::assertSame(44, $row['shopMs'], 'everything else is the shop');
    }

    public function testAGapTooShortToBeARoundTripIsShopTime(): void
    {
        // 249ms is below the threshold phases.js measured — scheduling noise, not a model call.
        $row = TraceExportRow::of(self::conversation(events: [
            self::event(0, 'facet.probe'),
            self::event(249, 'turn.end'),
        ], totalMs: 249), 'Storefront');

        self::assertSame(0, $row['modelMs']);
        self::assertSame(249, $row['shopMs']);
    }

    public function testToolCallsAreCounted(): void
    {
        $row = TraceExportRow::of(self::conversation(events: [
            self::event(10, 'tool.call'),
            self::event(20, 'retrieve'),
            self::event(30, 'tool.call'),
        ]), 'Storefront');

        self::assertSame(2, $row['toolCalls']);
    }

    public function testALoggedInShopperIsNamed(): void
    {
        $row = TraceExportRow::of(self::conversation(customer: self::customer('Anna', 'Schmidt')), 'Storefront');

        self::assertSame('Anna Schmidt', $row['user']);
    }

    /**
     * Two states, and the second is not a fallback. `ON DELETE SET NULL` removes the id when a
     * customer deletes their account, so a conversation with no customer genuinely has none —
     * whether it never had one or no longer does.
     */
    public function testNoCustomerReadsAsGuest(): void
    {
        self::assertSame('Guest user', TraceExportRow::of(self::conversation(), 'Storefront')['user']);
    }

    public function testAnEmptyTraceStillProducesARow(): void
    {
        // A conversation whose turn failed before any event was recorded is exactly the row a
        // merchant is looking for. It must not be the row that throws.
        $row = TraceExportRow::of(self::conversation(events: []), 'Storefront');

        self::assertSame(0, $row['shopMs']);
        self::assertSame(0, $row['modelMs']);
        self::assertSame(0, $row['toolCalls']);
    }

    /**
     * The header order the CSV writer relies on: it writes `array_values()`, so a reordering here
     * silently moves every column under the wrong heading.
     */
    public function testTheKeyOrderIsTheColumnOrder(): void
    {
        self::assertSame(
            [
                'id',
                'createdAt',
                'salesChannel',
                'user',
                'turns',
                'outcome',
                'totalMs',
                'shopMs',
                'modelMs',
                'toolCalls',
            ],
            array_keys(TraceExportRow::of(self::conversation(), 'Storefront')),
        );
    }

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

    private static function event(int $elapsedMs, string $stage): TraceEventEntity
    {
        static $seq = 0;

        $event = new TraceEventEntity();
        $event->setId(bin2hex(random_bytes(16)));
        $event->setSeq($seq++);
        $event->setStage($stage);
        $event->setElapsedMs($elapsedMs);
        $event->setPayload([]);

        return $event;
    }
}
