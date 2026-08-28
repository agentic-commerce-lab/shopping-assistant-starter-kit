<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Tool;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Tool\BoundedProperties;

final class BoundedPropertiesTest extends TestCase
{
    public function testPassesThroughASmallPropertySet(): void
    {
        $properties = ['Material' => ['Merino'], 'Fit' => ['Regular']];

        self::assertSame($properties, BoundedProperties::of($properties));
    }

    public function testCapsValuesPerGroupAtFour(): void
    {
        $properties = ['Colour' => ['Red', 'Blue', 'Green', 'Black', 'White', 'Yellow']];

        self::assertSame(['Colour' => ['Red', 'Blue', 'Green', 'Black']], BoundedProperties::of($properties));
    }

    public function testCapsGroupsAtSix(): void
    {
        $properties = [];
        for ($i = 1; $i <= 8; ++$i) {
            $properties['Group' . $i] = ['Value'];
        }

        self::assertCount(6, BoundedProperties::of($properties));
    }
}
