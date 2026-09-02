<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Config;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Config\StoredValueReader;

/**
 * How a stored blocklist becomes a list of ids, in both the shapes the field has ever had.
 *
 * The field is a `sw-entity-multi-id-select` today and was a textarea before it, under the same key —
 * an id list means the same thing however it was picked, and renaming the key would have needed a
 * migration whose only job was to reformat a string. So both shapes are read, and a shop that
 * configured its blocklist before the picker existed keeps it.
 *
 * Every failure this covers is silent, which is why each has a test of its own rather than one case
 * asserting "it works": D5 makes the blocklist a compliance control, and a compliance control that
 * quietly matches nothing is worse than an absent one. Whether the ids then reach the right half of
 * `CatalogScope` is a wiring question and belongs to {@see SystemConfigAssistantConfigTest}.
 */
final class StoredValueReaderIdListTest extends TestCase
{
    private const CHANNEL = '01a01b4af6567284ac9eeb3616598ac3';

    private const PREFIX = 'SwagAssistantStarterKit.config.';

    private const KEY = 'blockedProducts';

    public function testATextareaBlocklistIsSplitPerLineAndTrimmed(): void
    {
        // One long string would mean the blocklist matches nothing at all.
        self::assertSame(['a2a2', 'b3b3', 'c4c4'], $this->idList("a2a2\n  b3b3  \n\nc4c4"));
    }

    public function testCarriageReturnsFromAWindowsTextareaDoNotBecomePartOfAnId(): void
    {
        // A merchant pasting ids from Windows sends \r\n. An id with a trailing \r matches nothing.
        self::assertSame(['a2a2', 'b3b3'], $this->idList("a2a2\r\nb3b3"));
    }

    public function testIdsPickedInTheMultiSelectArriveAsAList(): void
    {
        // The picker stores a real JSON array, and `getString()` on an array returns ''. Read through
        // the string getter, a merchant who picked eight products would get an empty blocklist.
        self::assertSame(['a2a2', 'b3b3'], $this->idList(['a2a2', 'b3b3']));
    }

    public function testAnEmptyFieldYieldsAnEmptyArrayAndNotAnArrayWithAnEmptyString(): void
    {
        // [''] compares every product id against the empty string — which matches nothing while
        // reporting a *configured* blocklist in the trace.
        self::assertSame([], $this->idList("\n  \n"));
        self::assertSame([], $this->idList([]));
    }

    public function testAnEntryThePickerCouldNotHaveWrittenIsDropped(): void
    {
        // Nothing in the Administration produces this, but `system_config` is a table an integration
        // can write to, and a non-string id is a type error inside the DAL filter at request time.
        self::assertSame(['a2a2', 'b3b3'], $this->idList(['a2a2', '', 42, '  b3b3  ', null]));
    }

    public function testAnAbsentKeyIsAnEmptyList(): void
    {
        self::assertSame(
            [],
            (new StoredValueReader(new FakeSystemConfigService([]), self::PREFIX))->idList(self::KEY, self::CHANNEL),
        );
    }

    /**
     * @param string|list<mixed> $stored
     *
     * @return list<string>
     */
    private function idList(string|array $stored): array
    {
        $reader = new StoredValueReader(new FakeSystemConfigService([
            self::PREFIX . self::KEY => $stored,
        ]), self::PREFIX);

        return $reader->idList(self::KEY, self::CHANNEL);
    }
}
