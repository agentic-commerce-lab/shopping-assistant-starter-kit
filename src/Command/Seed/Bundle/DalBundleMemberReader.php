<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Command\Seed\Bundle;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Uuid\Uuid;
use Swag\AssistantStarterKit\Command\Seed\SeedTax;

/**
 * What the shop has to tell the bundle seeder before it can write anything: the member products'
 * ids, how many variants each has, the tax rule and the categories the bundles are filed under.
 *
 * One class and one round trip per question, read out of the live shop rather than assumed — the
 * same rule {@see \Swag\AssistantStarterKit\Command\SeedBikeCatalogueCommand} states for itself:
 * every id it does not create, it reads first. A renamed category or a deleted product surfaces in
 * the dry run instead of mid-write.
 *
 * **`childCount` travels with the id and is not optional.** It is what
 * {@see SingleVariantMembers} needs, and a bundle written without that check is a bundle that can
 * disappear from the whole shop without an error.
 */
final readonly class DalBundleMemberReader
{
    public function __construct(
        private Connection $connection,
    ) {}

    /**
     * @param list<string> $productNumbers
     *
     * @return array{ids: array<string, string>, childCounts: array<string, int>}
     *
     * @throws \Doctrine\DBAL\Exception propagated deliberately: a shop this cannot be read from is
     *         a shop nothing should be written to, and the command reports the failure as itself
     */
    public function members(array $productNumbers): array
    {
        if ($productNumbers === []) {
            return ['ids' => [], 'childCounts' => []];
        }

        /** @var list<array{number: string, id: string, children: int|string|null}> $rows */
        $rows = $this->connection->fetchAllAssociative(
            'SELECT product_number AS number, LOWER(HEX(id)) AS id, child_count AS children
             FROM product
             WHERE product_number IN (:numbers) AND version_id = :version',
            [
                'numbers' => $productNumbers,
                'version' => Uuid::fromHexToBytes(Defaults::LIVE_VERSION),
            ],
            ['numbers' => ArrayParameterType::STRING],
        );

        $ids = [];
        $childCounts = [];

        foreach ($rows as $row) {
            $ids[$row['number']] = $row['id'];
            $childCounts[$row['number']] = (int) ($row['children'] ?? 0);
        }

        return ['ids' => $ids, 'childCounts' => $childCounts];
    }

    /**
     * The shop's highest tax rate, chosen the same way {@see \Swag\AssistantStarterKit\Command\Seed\Bike\DalShopTaxonomyReader}
     * chooses it, so a bundle is taxed like the catalogue around it.
     *
     * @throws \Doctrine\DBAL\Exception propagated, as {@see self::members()} documents
     */
    public function tax(): SeedTax
    {
        /** @var array{id?: string, rate?: mixed}|false $row */
        $row = $this->connection->fetchAssociative(
            'SELECT LOWER(HEX(id)) AS id, tax_rate AS rate FROM tax ORDER BY tax_rate DESC, id LIMIT 1',
        );

        $taxId = \is_array($row) ? $row['id'] ?? null : null;

        if (!\is_string($taxId) || $taxId === '') {
            throw new \RuntimeException('No tax rule exists in this shop — cannot write a bundle without one.');
        }

        \assert(\is_array($row));

        return new SeedTax($taxId, (float) ($row['rate'] ?? 0.0));
    }

    /**
     * Category ids by name, for the names the bundles are filed under.
     *
     * Last row wins on a duplicate name, for the reason `DalShopTaxonomyReader` gives about its own
     * lookup: a real shop can carry two categories with one name, picking either is arbitrary, and
     * refusing would make the seeder unusable for a reason nobody could act on.
     *
     * @param list<string> $names
     *
     * @return array<string, string>
     *
     * @throws \Doctrine\DBAL\Exception propagated, as {@see self::members()} documents
     */
    public function categories(array $names): array
    {
        if ($names === []) {
            return [];
        }

        /** @var list<array{name: string, id: string}> $rows */
        $rows = $this->connection->fetchAllAssociative(
            'SELECT ct.name AS name, LOWER(HEX(c.id)) AS id
             FROM category c
             JOIN category_translation ct ON ct.category_id = c.id AND ct.category_version_id = c.version_id
             WHERE ct.name IN (:names) AND c.version_id = :version',
            [
                'names' => $names,
                'version' => Uuid::fromHexToBytes(Defaults::LIVE_VERSION),
            ],
            ['names' => ArrayParameterType::STRING],
        );

        $ids = [];

        foreach ($rows as $row) {
            $ids[$row['name']] = $row['id'];
        }

        return $ids;
    }
}
