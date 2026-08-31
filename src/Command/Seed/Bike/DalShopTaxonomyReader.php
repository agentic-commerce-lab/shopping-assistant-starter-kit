<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Command\Seed\Bike;

use Doctrine\DBAL\Connection;

/**
 * Reads what the shop already has, so {@see BikeSeedPlan} can attach to it instead of duplicating it.
 *
 * **SQL rather than the DAL**, and for once that is the simpler choice rather than the faster one:
 * this needs four flat name-to-id maps and nothing else — no associations, no translations beyond
 * the default language, no entity hydration. Going through four repositories with four criteria to
 * then throw the entities away would be more code saying less.
 *
 * **Names are read in the system default language.** {@see BikeCatalogue} is written in English, and
 * so is this shop's own catalogue; a German translation of "Jerseys" is not what a product's
 * `category` field names. A shop whose default language is not the one the catalogue is written in
 * simply resolves nothing, and {@see BikeSeedPlan} refuses before writing rather than seeding into
 * whatever happened to match.
 */
final readonly class DalShopTaxonomyReader
{
    public function __construct(
        private Connection $connection,
    ) {}

    /**
     * @throws \Doctrine\DBAL\Exception propagated deliberately, matching {@see \Swag\AssistantStarterKit\Command\Seed\SeedRunner}'s
     *     own tax lookup: a taxonomy that cannot be read must fail loudly rather than seed into a shop
     *     it could not see
     */
    public function read(): ShopTaxonomy
    {
        return new ShopTaxonomy(
            $this->pairs('SELECT ct.name, LOWER(HEX(c.id)) id FROM category c
                 JOIN category_translation ct ON ct.category_id = c.id
                 WHERE ct.name IS NOT NULL'),
            $this->pairs('SELECT gt.name, LOWER(HEX(g.id)) id FROM property_group g
                 JOIN property_group_translation gt ON gt.property_group_id = g.id
                 WHERE gt.name IS NOT NULL'),
            $this->options(),
            $this->pairs('SELECT mt.name, LOWER(HEX(m.id)) id FROM product_manufacturer m
                 JOIN product_manufacturer_translation mt ON mt.product_manufacturer_id = m.id
                 WHERE mt.name IS NOT NULL'),
            $this->taxId(),
        );
    }

    /**
     * @return array<string, array<string, string>>
     *
     * @throws \Doctrine\DBAL\Exception propagated, as {@see self::read()} documents
     */
    private function options(): array
    {
        $rows = $this->connection->fetchAllAssociative('SELECT gt.name grp, ot.name val, LOWER(HEX(o.id)) id
             FROM property_group_option o
             JOIN property_group_option_translation ot ON ot.property_group_option_id = o.id
             JOIN property_group_translation gt ON gt.property_group_id = o.property_group_id
             WHERE ot.name IS NOT NULL AND gt.name IS NOT NULL');

        $options = [];
        foreach ($rows as $row) {
            $options[(string) $row['grp']][(string) $row['val']] = (string) $row['id'];
        }

        return $options;
    }

    /**
     * The same `ORDER BY tax_rate DESC, id` the fashion seeder uses, so the two agree on which rate a
     * seeded product carries in a shop that has several.
     *
     * @throws \Doctrine\DBAL\Exception propagated, as {@see self::read()} documents
     */
    private function taxId(): string
    {
        $taxId = $this->connection->fetchOne('SELECT LOWER(HEX(id)) FROM tax ORDER BY tax_rate DESC, id LIMIT 1');

        if (!\is_string($taxId) || $taxId === '') {
            throw new \RuntimeException('No tax rule exists in this shop — cannot price seeded products.');
        }

        return $taxId;
    }

    /**
     * **Last row wins on a duplicate name, and that is deliberate.** A shop can carry two categories
     * called "Accessories"; picking either is arbitrary, and refusing would make the seeder unusable
     * on a real shop for a reason nobody could act on. What must not happen is a silent null, and
     * that cannot: an unresolved name is what {@see BikeSeedPlan} throws on.
     *
     * @return array<string, string>
     *
     * @throws \Doctrine\DBAL\Exception propagated, as {@see self::read()} documents
     */
    private function pairs(string $sql): array
    {
        $pairs = [];

        foreach ($this->connection->fetchAllAssociative($sql) as $row) {
            $pairs[(string) $row['name']] = (string) $row['id'];
        }

        return $pairs;
    }
}
