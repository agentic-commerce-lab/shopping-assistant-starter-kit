<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Trace\Export;

use PHPUnit\Framework\TestCase;
use Shopware\Core\Checkout\Customer\CustomerEntity;
use Swag\AssistantStarterKit\Core\Trace\Export\TraceCsvSerialiser;
use Swag\AssistantStarterKit\Entity\Conversation\ConversationEntity;
use Swag\AssistantStarterKit\Entity\TraceEvent\TraceEventCollection;

final class TraceCsvSerialiserTest extends TestCase
{
    private const CHANNEL = '01a01b4af6567284ac9eeb3616598ac3';

    public function testItStartsWithAHeaderSoTheFileOpensAsATable(): void
    {
        $csv = TraceCsvSerialiser::serialise([self::conversation()], [self::CHANNEL => 'Storefront']);

        self::assertStringStartsWith(
            "id,createdAt,salesChannel,user,turns,outcome,totalMs,shopMs,modelMs,toolCalls\n",
            $csv,
        );
    }

    /**
     * The one thing a hand-rolled CSV writer gets wrong. A name with a comma splits the row; a name
     * with a quote breaks the quoting. Spec T4 put real customer names in this file, which is what
     * makes this load-bearing rather than theoretical.
     */
    public function testANameWithACommaAndAQuoteSurvives(): void
    {
        $csv = TraceCsvSerialiser::serialise([self::conversation(self::customer('Anna "Ann"', 'Schmidt, Dr.'))], [
            self::CHANNEL => 'Storefront',
        ]);

        $rows = array_map(static fn(string $line): array => str_getcsv(
            $line,
            escape: '',
        ), array_values(array_filter(explode("\n", trim($csv)))));

        self::assertCount(2, $rows, 'the name must not have split the row');
        self::assertSame('Anna "Ann" Schmidt, Dr.', $rows[1][3]);
    }

    public function testAGuestAndACustomerSitInTheSameFile(): void
    {
        $csv = TraceCsvSerialiser::serialise([
            self::conversation(),
            self::conversation(self::customer('Anna', 'Schmidt')),
        ], [self::CHANNEL => 'Storefront']);

        self::assertStringContainsString('Guest user', $csv);
        self::assertStringContainsString('Anna Schmidt', $csv);
    }

    public function testAnUnknownSalesChannelFallsBackToItsIdRatherThanBlank(): void
    {
        // A blank cell reads as "no sales channel". The id reads as "one this export could not
        // name", which is true and traceable.
        $csv = TraceCsvSerialiser::serialise([self::conversation()], []);

        self::assertStringContainsString(self::CHANNEL, $csv);
    }

    public function testAnEmptySelectionStillProducesAHeader(): void
    {
        // A file with a header and no rows says "nothing matched". A zero-byte file says the export
        // is broken, and the merchant cannot tell which.
        $csv = TraceCsvSerialiser::serialise([], []);

        self::assertStringStartsWith('id,createdAt', $csv);
    }

    private static function customer(string $first, string $last): CustomerEntity
    {
        $customer = new CustomerEntity();
        $customer->setId('01a01b4f9e2270a1b2c3d4e5f6a7b8c9');
        $customer->setFirstName($first);
        $customer->setLastName($last);

        return $customer;
    }

    private static function conversation(?CustomerEntity $customer = null): ConversationEntity
    {
        $conversation = new ConversationEntity();
        $conversation->setId('01a0337f413070afa3b29711739324a2');
        $conversation->setSalesChannelId(self::CHANNEL);
        $conversation->setTurnCount(1);
        $conversation->setOutcome('product_shown');
        $conversation->setTotalMs(3656);
        $conversation->setCreatedAt(new \DateTimeImmutable('2026-08-24T10:12:04+00:00'));
        $conversation->setEvents(new TraceEventCollection([]));

        if ($customer !== null) {
            $conversation->setCustomerId($customer->getId());
            $conversation->setCustomer($customer);
        }

        return $conversation;
    }
}
