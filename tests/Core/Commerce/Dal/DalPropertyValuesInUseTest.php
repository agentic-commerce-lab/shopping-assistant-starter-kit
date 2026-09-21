<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Commerce\Dal;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Commerce\Dal\DalPropertyValuesInUse;
use Swag\AssistantStarterKit\Core\Commerce\Dto\CatalogScope;
use Swag\AssistantStarterKit\Core\Commerce\Dto\FacetType;

/**
 * The gate in front of the fast path, and the grouping behind it.
 *
 * The SQL itself is not unit-testable without a database, and its equivalence to the aggregation it
 * replaces was established the only way that means anything — against a real catalogue of 118,632
 * products, comparing both id sets in both directions, twice. See the class docblock. What IS
 * testable here is everything around it: when the fast path may run at all, and that a translation
 * chain collapses to one value per name.
 */
final class DalPropertyValuesInUseTest extends TestCase
{
    /**
     * The common shape: no blocklist, no allowlist, so `active` and visibility are the whole filter
     * — which is exactly what the SQL applies.
     */
    public function testAnUnrestrictedScopeMayUseTheFastPath(): void
    {
        self::assertTrue(DalPropertyValuesInUse::canAnswer(new CatalogScope()));
    }

    /**
     * Each narrowing field bars it on its own. A value surviving only on a blocked product must
     * never reach the vocabulary, and this class does not mirror the scope into SQL — it declines.
     */
    public function testEveryScopeNarrowingBarsTheFastPath(): void
    {
        self::assertFalse(DalPropertyValuesInUse::canAnswer(new CatalogScope(includeCategoryIds: ['c1'])));
        self::assertFalse(DalPropertyValuesInUse::canAnswer(new CatalogScope(blockedProductIds: ['p1'])));
        self::assertFalse(DalPropertyValuesInUse::canAnswer(new CatalogScope(blockedCategoryIds: ['c2'])));
        // A group's conditions narrow the catalogue exactly as the two id lists do, and a value
        // surviving only on a blocked product must not reach the model's vocabulary — it would
        // offer a colour the shop will never show.
        self::assertFalse(DalPropertyValuesInUse::canAnswer(new CatalogScope(blockedStreamIds: ['s1'])));
    }

    /**
     * `minDescriptionWords` decides which products may be SHOWN, never which words the catalogue
     * knows — and `DalCriteriaBuilder` does not apply it to the facet criteria either. Treating it
     * as a narrowing would turn the fast path off for a setting that does not affect the answer.
     */
    public function testADescriptionLengthSettingIsNotANarrowing(): void
    {
        self::assertTrue(DalPropertyValuesInUse::canAnswer(new CatalogScope(minDescriptionWords: 20)));
    }

    /**
     * No language means no translation to read, and an empty result rather than a query with an
     * empty `IN ()` — which is a syntax error in MySQL, not an empty set.
     */
    public function testNoLanguageAsksTheDatabaseNothing(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::never())->method('fetchAllAssociative');

        self::assertSame([], (new DalPropertyValuesInUse($connection))->facets('sc', [], 50));
    }

    /**
     * One value carries a row per language in the chain, and the most specific one wins — the same
     * resolution the DAL performs, done here because the query asks for the whole chain at once.
     */
    public function testATranslationChainCollapsesToOneValuePerName(): void
    {
        $german = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
        $system = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

        $connection = $this->createMock(Connection::class);
        $connection
            ->method('fetchAllAssociative')
            ->willReturn([
                ['group_name' => 'Farbe', 'value_name' => 'Blau', 'language_id' => hex2bin($german)],
                ['group_name' => 'Farbe', 'value_name' => 'Blue', 'language_id' => hex2bin($system)],
                ['group_name' => 'Farbe', 'value_name' => 'Blau', 'language_id' => hex2bin($system)],
            ]);

        $facets = (new DalPropertyValuesInUse($connection))->facets('sc', [$german, $system], 50);

        self::assertCount(1, $facets);

        $farbe = $facets[0] ?? null;
        self::assertNotNull($farbe);
        self::assertSame('properties.Farbe', $farbe->field);
        self::assertSame(FacetType::Terms, $farbe->type);
        self::assertSame(['Blau', 'Blue'], $farbe->values);
    }

    /**
     * The same cap the aggregation carried, applied to the same namespace, so a group with
     * thousands of values cannot inflate the probe's result.
     */
    public function testTheValueLimitIsEnforcedPerGroup(): void
    {
        $language = 'cccccccccccccccccccccccccccccccc';
        $rows = [];

        foreach (range(1, 80) as $i) {
            $rows[] = [
                'group_name' => 'Länge',
                'value_name' => \sprintf('%03d mm', $i),
                'language_id' => hex2bin($language),
            ];
        }

        $connection = $this->createMock(Connection::class);
        $connection->method('fetchAllAssociative')->willReturn($rows);

        $facets = (new DalPropertyValuesInUse($connection))->facets('sc', [$language], 50);

        $laenge = $facets[0] ?? null;
        self::assertNotNull($laenge);
        self::assertCount(50, $laenge->values);
        self::assertSame('001 mm', $laenge->values[0] ?? null);
    }

    /**
     * A malformed id cannot match a row, and must not throw inside a facet probe — the probe is on
     * the path of every turn, and a broken id is a configuration problem, not a reason to fail one.
     */
    public function testAMalformedIdDoesNotThrow(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchAllAssociative')->willReturn([]);

        self::assertSame([], (new DalPropertyValuesInUse($connection))->facets('not-hex', ['also-not-hex'], 50));
    }

    /**
     * The live failure, as a test.
     *
     * A value like `114` (chain links) or `32` (spoke holes) is a numeric STRING in the database.
     * Used as a PHP array key while collapsing the translation chain, it silently becomes an INT —
     * and an int reaching `mb_strtolower()` in the grounding pass is a TypeError that degrades the
     * whole turn to "Sorry — I could not finish that just now."
     *
     * Measured 2026-09-15 against a 118,232-product catalogue: every product question failed this
     * way, and the trace named it `mb_strtolower(): Argument #1 must be of type string, int given`.
     * The aggregation this class replaces returned strings, so nothing downstream ever had to
     * defend against it.
     */
    public function testNumericValuesStayStrings(): void
    {
        $language = 'dddddddddddddddddddddddddddddddd';

        $connection = $this->createMock(Connection::class);
        $connection
            ->method('fetchAllAssociative')
            ->willReturn([
                ['group_name' => 'Kettenglieder', 'value_name' => '114', 'language_id' => hex2bin($language)],
                ['group_name' => 'Kettenglieder', 'value_name' => '116', 'language_id' => hex2bin($language)],
            ]);

        $facets = (new DalPropertyValuesInUse($connection))->facets('sc', [$language], 50);
        $links = $facets[0] ?? null;

        self::assertNotNull($links);
        self::assertSame(['114', '116'], $links->values);

        foreach ($links->values as $value) {
            self::assertIsString($value);
        }
    }
}
