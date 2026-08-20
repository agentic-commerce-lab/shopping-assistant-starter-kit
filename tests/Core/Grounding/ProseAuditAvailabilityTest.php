<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Grounding;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Commerce\Dto\ProductCard;
use Swag\AssistantStarterKit\Core\Commerce\Dto\StockSource;
use Swag\AssistantStarterKit\Core\Grounding\ProseAudit;

/**
 * Split out of {@see ProseAuditTest} (too-many-methods) rather than suppressed: that class keeps the
 * price half, this one the availability half.
 *
 * The cases where the audit must stay **silent** matter as much as the ones where it fires. Ruling
 * R85: an assertion that flags correct behaviour gets ignored, and these are the controls that must
 * never be doubted.
 */
final class ProseAuditAvailabilityTest extends TestCase
{
    private function card(float $price, int $stock): ProductCard
    {
        return new ProductCard(
            id: 'a2a2a2a2a2a2a2a2a2a2a2a2a2a2a2a2',
            parentId: 'fafafafafafafafafafafafafafafafa',
            name: 'Trail Jersey',
            description: null,
            price: $price,
            currency: 'EUR',
            stock: $stock,
            stockSource: StockSource::Variant,
            deliveryTime: null,
            url: '/detail/a2',
            imageUrl: null,
        );
    }

    public function testAnAvailabilityClaimIsFlaggedWhenEveryCardIsSoldOut(): void
    {
        // The measured defect: prose said "is available" beside a card reporting stock 0.
        $unbacked = (new ProseAudit())->unbackedAvailabilityClaims('Yes, the Trail Jersey is available in Blue, size M.', [$this->card(
            74.90,
            0,
        )]);

        self::assertSame(['is available'], $unbacked);
    }

    public function testAnAvailabilityClaimIsNotFlaggedWhenACardIsInStock(): void
    {
        self::assertSame(
            [],
            (new ProseAudit())->unbackedAvailabilityClaims('Yes, the Trail Jersey is available in Blue, size L.', [$this->card(
                79.90,
                12,
            )]),
        );
    }

    public function testAMixedCardSetDoesNotFlagAndThatLimitIsDeliberate(): void
    {
        // Documented limitation rather than an oversight: with one in-stock and one sold-out card,
        // "we have it" most likely means the in-stock one, and guessing which product a sentence is
        // about is exactly the inference that manufactures false positives.
        self::assertSame(
            [],
            (new ProseAudit())->unbackedAvailabilityClaims('Yes, we have it.', [
                $this->card(74.90, 0),
                $this->card(79.90, 12),
            ]),
        );
    }

    public function testAnAvailabilityClaimWithNoCardsAtAllIsFlagged(): void
    {
        // Nothing could back it. Asserted as non-empty rather than by exact phrase list: the claim
        // patterns deliberately overlap ("we have it" and "yes, we have" both match here), so the
        // count carries no meaning — only the presence of a claim does.
        self::assertNotSame([], (new ProseAudit())->unbackedAvailabilityClaims('Yes, we have it.', []));
    }

    public function testProseThatDefersToTheCardIsNotFlaggedEvenWhenSoldOut(): void
    {
        // The behaviour the prompt now asks for. Flagging it would punish the right answer.
        self::assertSame(
            [],
            (new ProseAudit())->unbackedAvailabilityClaims('I found the Trail Jersey in Blue, size M — the card shows its current stock and price.', [$this->card(
                74.90,
                0,
            )]),
        );
    }
}
