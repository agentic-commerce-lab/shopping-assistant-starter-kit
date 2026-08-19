<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Commerce\Dto;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Commerce\Dto\ProductQuery;

final class ProductQueryTest extends TestCase
{
    public function testRetrievalFallsBackToTheReturnLimitWhenNoWindowIsGiven(): void
    {
        $query = new ProductQuery(limit: 10);

        self::assertSame(10, $query->retrievalLimit());
    }

    public function testAWiderCandidateWindowIsWhatRetrievalUses(): void
    {
        $query = new ProductQuery(limit: 3, candidateLimit: 20);

        self::assertSame(20, $query->retrievalLimit());
        self::assertSame(3, $query->limit);
    }

    public function testACandidateWindowNarrowerThanTheReturnLimitCannotShrinkRetrieval(): void
    {
        // Otherwise a caller could reintroduce the very truncation this split removes,
        // by passing a candidate window smaller than the number it wants back.
        $query = new ProductQuery(limit: 10, candidateLimit: 2);

        self::assertSame(10, $query->retrievalLimit());
    }
}
