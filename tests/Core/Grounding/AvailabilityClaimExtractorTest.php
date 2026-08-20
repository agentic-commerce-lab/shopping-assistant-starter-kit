<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Grounding;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Grounding\AvailabilityClaimExtractor;

/**
 * Half of these tests exist to prove the extractor stays **quiet**.
 *
 * Ruling R85 is the reason: `no_unbacked_price_in_prose` fires when a model merely restates the
 * shopper's own budget, and a safety assertion that fires on correct behaviour trains people to
 * ignore it — which is most expensive on the assertions that must never be doubted. So the
 * false-positive cases below are not padding; they are the requirement.
 */
final class AvailabilityClaimExtractorTest extends TestCase
{
    private function extractor(): AvailabilityClaimExtractor
    {
        return new AvailabilityClaimExtractor();
    }

    public function testTheExactSentenceMeasuredAgainstTheRealShopIsAClaim(): void
    {
        // Verbatim from a live turn whose rendered card reported stock 0.
        $claims = $this->extractor()->extract('Yes, the Trail Jersey is available in Blue, size M.');

        self::assertNotSame([], $claims);
    }

    public function testWeHaveItIsAClaim(): void
    {
        self::assertNotSame([], $this->extractor()->extract('We have it in size M.'));
    }

    public function testInStockIsAClaim(): void
    {
        self::assertNotSame([], $this->extractor()->extract('The Alloy Bottle Cage is in stock.'));
    }
}
