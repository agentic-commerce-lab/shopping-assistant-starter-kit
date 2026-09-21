<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Commerce\Dal;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Swag\AssistantStarterKit\Core\Commerce\Dto\CatalogScope;
use Swag\AssistantStarterKit\Core\Commerce\Dto\Facet;
use Swag\AssistantStarterKit\Core\Commerce\Dto\FacetType;

/**
 * Which property and option values a catalogue uses, asked the way round an index can answer.
 *
 * ## The measurement this exists for
 *
 * {@see DalCommerceGateway::facets()} asks for a terms aggregation over `properties.group.name` and
 * `properties.name`, which makes MySQL walk every row of `product_property` and group it. That is
 * work proportional to the CATALOGUE, and the assistant pays it on every cache miss — every five
 * minutes, per node.
 *
 * Measured on a seeded shop, 2026-09-15, at four sizes:
 *
 * ```
 *  19.632 Produkte →  454 ms    23,1 µs/Produkt
 *  43.632 Produkte → 1062 ms    24,3 µs/Produkt
 *  68.632 Produkte → 1624 ms    23,7 µs/Produkt
 * 118.632 Produkte → 3159 ms    26,6 µs/Produkt
 * ```
 *
 * A straight line, and a shopper waits on it. At 1.8M products that is around 45 seconds before the
 * assistant has read the question.
 *
 * ## Why asking per value is the fix
 *
 * The question is *"which values does this catalogue use"*, answered by reading every product.
 * Turned around — *"for each value, is it used at all?"* — every answer stops at the first matching
 * row, and the work becomes proportional to the number of VALUES. A shop with two million products
 * does not have more colours than a shop with twenty thousand.
 *
 * Measured on the same catalogue, same joins, 118.632 products:
 *
 * ```
 * terms aggregation over all products   1767 ms
 * one EXISTS per value                   176 ms     10x
 * ```
 *
 * Two shapes that looked better are not: a `UNION` of the two id sets took 3.8 s, and running the
 * property and option halves as separate statements took 217 ms against 176 ms for the `OR` below.
 *
 * **The sets are identical, not merely the same size.** Both return the same 5,626 option ids, with
 * zero difference in either direction — checked in both directions, twice, including variant
 * options.
 *
 * ## Only when the assistant may see the whole catalogue
 *
 * {@see CatalogScope} can block products and whole category branches, and a value surviving only on
 * a blocked product must never reach the vocabulary. Mirroring that scope into the SQL here would
 * be a second copy of {@see DalCriteriaBuilder::applyScope()} — and a second copy of a policy
 * boundary is the drift `BlocklistFilter` exists to catch after the fact.
 *
 * So this answers only for an unrestricted scope ({@see self::canAnswer()}). A shop with a
 * blocklist keeps the aggregation, with its cost and its exactness. The fast path is the one whose
 * equivalence was measured, and nothing else uses it.
 */
final readonly class DalPropertyValuesInUse
{
    public function __construct(
        private Connection $connection,
    ) {}

    /**
     * True when no scope narrowing applies, so `active` and sales-channel visibility are the whole
     * filter — the exact conditions the equivalence above was measured under.
     *
     * `minDescriptionWords` is deliberately not consulted: it filters which products may be SHOWN,
     * never which words the catalogue knows, and {@see DalCriteriaBuilder} does not apply it to the
     * facet criteria either.
     */
    public static function canAnswer(CatalogScope $scope): bool
    {
        // One expression over all four lists rather than four ANDed comparisons: the question is
        // "does any narrowing apply at all", and spelling it as a chain cost this class more
        // branches than its complexity budget allows once product groups were added.
        return (
            array_merge(
                $scope->includeCategoryIds,
                $scope->blockedProductIds,
                $scope->blockedCategoryIds,
                $scope->blockedStreamIds,
            ) === []
        );
    }

    /**
     * One `properties.<Group>` Terms facet per group that has at least one value in use.
     *
     * Property values and variant option values are folded into one namespace, exactly as
     * {@see DalFacetReader} folds the two aggregations it replaces: a shopper saying "blue" cannot
     * know whether this shop models colour as a property or as a variant axis.
     *
     * @param list<string> $languageIds hex ids, most specific first — a name is taken from the
     *                                  first language that has one, which is how the DAL resolves
     *                                  a translation chain
     *
     * @return list<Facet>
     *
     * @throws \Doctrine\DBAL\Exception a failing read is not swallowed here: the aggregation this
     *                                  replaces would fail the same way, and FacetProbe's caller
     *                                  already decides what a broken catalogue read means for a turn
     */
    public function facets(string $salesChannelId, array $languageIds, int $valueLimit): array
    {
        if ($languageIds === []) {
            return [];
        }

        $rows = $this->connection->fetchAllAssociative(
            'SELECT gt.name AS group_name, ot.name AS value_name, gt.language_id AS language_id
               FROM property_group_option o
               JOIN property_group_translation gt
                 ON gt.property_group_id = o.property_group_id
                AND gt.language_id IN (:languages)
               JOIN property_group_option_translation ot
                 ON ot.property_group_option_id = o.id
                AND ot.language_id = gt.language_id
              WHERE EXISTS (
                        SELECT 1 FROM product_property pp
                          JOIN product p
                            ON p.id = pp.product_id AND p.version_id = pp.product_version_id
                          LEFT JOIN product parent
                            ON parent.id = p.parent_id AND parent.version_id = p.version_id
                          JOIN product_visibility v
                            ON v.product_id = COALESCE(p.parent_id, p.id)
                           AND v.product_version_id = p.version_id
                         WHERE pp.property_group_option_id = o.id
                           AND COALESCE(p.active, parent.active) = 1
                           AND v.sales_channel_id = :salesChannel
                    )
                 OR EXISTS (
                        SELECT 1 FROM product_option po
                          JOIN product p
                            ON p.id = po.product_id AND p.version_id = po.product_version_id
                          LEFT JOIN product parent
                            ON parent.id = p.parent_id AND parent.version_id = p.version_id
                          JOIN product_visibility v
                            ON v.product_id = COALESCE(p.parent_id, p.id)
                           AND v.product_version_id = p.version_id
                         WHERE po.property_group_option_id = o.id
                           AND COALESCE(p.active, parent.active) = 1
                           AND v.sales_channel_id = :salesChannel
                    )',
            [
                'languages' => array_map(self::bytes(...), $languageIds),
                'salesChannel' => self::bytes($salesChannelId),
            ],
            ['languages' => ArrayParameterType::BINARY],
        );

        return $this->group($rows, $languageIds, $valueLimit);
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @param list<string>               $languageIds
     *
     * @return list<Facet>
     */
    private function group(array $rows, array $languageIds, int $valueLimit): array
    {
        $rank = array_flip(array_map(strtolower(...), $languageIds));
        $best = [];

        foreach ($rows as $row) {
            $group = (string) $row['group_name'];
            $value = (string) $row['value_name'];
            $position = $rank[strtolower(bin2hex((string) $row['language_id']))] ?? \PHP_INT_MAX;

            // The translation chain, resolved here rather than in SQL: one value can carry a row per
            // language, and the most specific language the context asked for wins.
            if (!isset($best[$group][$value]) || $position < $best[$group][$value]) {
                $best[$group][$value] = $position;
            }
        }

        $facets = [];

        foreach ($best as $group => $values) {
            // **Back to strings, and this is not defensive noise.** A value like `114` (chain links)
            // or `32` (spoke holes) is a numeric STRING in the database and becomes an INT the
            // moment it is used as a PHP array key above. Handed on as an int it reaches
            // `mb_strtolower()` in the grounding pass, which is a TypeError — the whole turn
            // degrades to "could not finish that". Found by the first realistic catalogue this ran
            // against, within minutes, on `Kettenglieder 114`.
            $names = array_map(strval(...), array_keys($values));
            sort($names);

            $facets[] = new Facet(
                field: DalFacetReader::GROUP_PREFIX . $group,
                type: FacetType::Terms,
                values: array_values(\array_slice($names, 0, $valueLimit)),
            );
        }

        return $facets;
    }

    private static function bytes(string $hexId): string
    {
        // A malformed id cannot match anything, and must not throw: the probe runs on every turn,
        // and 16 zero bytes is a valid binary(16) that no row carries. Checked rather than
        // suppressed — hex2bin warns on odd or non-hex input, and a silenced warning is a defect
        // nobody sees.
        if (\strlen($hexId) !== 32 || !ctype_xdigit($hexId)) {
            return str_repeat("\0", 16);
        }

        return (string) hex2bin($hexId);
    }
}
