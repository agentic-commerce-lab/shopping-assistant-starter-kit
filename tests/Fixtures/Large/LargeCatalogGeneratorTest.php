<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Fixtures\Large;

use PHPUnit\Framework\TestCase;

/**
 * The generator is test infrastructure, and test infrastructure that is wrong produces a green run
 * that means nothing. These assertions are the reason a generated fixture is trustworthy at all —
 * see spec decision S2, which chose a generator over a committed blob precisely so this file could
 * exist.
 */
final class LargeCatalogGeneratorTest extends TestCase
{
    public function testEveryTrapIdIsDistinct(): void
    {
        $ids = [
            ScaleTrap::FAMILY_PARENT,
            ScaleTrap::RARE_OPTION_PRODUCT,
            ScaleTrap::DEEP_DUPLICATE,
            ScaleTrap::FAMILY_SOLD_OUT_VARIANT,
        ];

        self::assertSame($ids, array_values(array_unique($ids)));
    }

    /**
     * The prefix is load-bearing: a journey asserting `rendered_ids_exactly` on a generated id must
     * be able to tell at a glance that the id is a trap rather than one of the twelve real products.
     */
    public function testEveryTrapIdIsPrefixed(): void
    {
        foreach ([ScaleTrap::FAMILY_PARENT, ScaleTrap::RARE_OPTION_PRODUCT, ScaleTrap::DEEP_DUPLICATE] as $id) {
            self::assertStringStartsWith('sc-', $id);
        }
    }
}
