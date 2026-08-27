<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Command\Seed;

/**
 * Builds the fashion category tree as nested payload arrays plus a path-to-id lookup, entirely in
 * memory — no Shopware dependency, so this is unit tested directly rather than against a container.
 *
 * Nested rather than flat: Shopware's `EntityRepository::create()` accepts a self-referencing
 * `children` key (the same shape
 * `vendor/shopware/core/Framework/Demodata/Generator/CategoryGenerator.php` writes), so the whole
 * 1,031-node tree is one write in {@see SeedWriter}, not a batched, parent-before-child sequence.
 *
 * Trap leaves (`Occasion Dresses`, `Occasion Suits`, `Yoga`) are appended onto garment-type nodes this
 * class already builds — `FashionSeedTraps` supplies the category **path**, this class is the only
 * place that turns any category path into an id, which is what keeps `idsByPath` the single source of
 * truth `ProductPlan` reads from.
 */
final class CategoryTreePlan
{
    private const NAMESPACE = 'category';

    /**
     * The three trap-only leaf names, hand-matched against the three known trap branches
     * (`Women/Occasion & Party`, `Men/Suits & Tailoring`, `Women/Activewear`) rather than derived from
     * `FashionSeedTraps::all()` — `FashionSeedTraps` supplies the category paths its trap products live
     * under, but {@see self::trapLeaves()} is what actually grafts those three named leaves onto the
     * garment tree, so a future trap needs its `"department/type"` pair added to this map by hand.
     */
    private const TRAP_LEAF_NAMES = [
        'Women/Occasion & Party' => 'Occasion Dresses',
        'Men/Suits & Tailoring' => 'Occasion Suits',
        'Women/Activewear' => 'Yoga',
    ];

    private function __construct() {}

    /**
     * @return array{tree: list<array<string, mixed>>, idsByPath: array<string, string>}
     */
    public static function build(string $navigationRootId): array
    {
        $idsByPath = [];
        $tree = [
            ...self::departments($navigationRootId, $idsByPath),
            self::sideBranch($navigationRootId, 'Brand', FashionSeedTaxonomy::BRANDS, $idsByPath),
            self::sideBranch($navigationRootId, 'Season', FashionSeedTaxonomy::SEASONS, $idsByPath),
            self::sideBranch($navigationRootId, 'Occasion', FashionSeedTaxonomy::OCCASIONS, $idsByPath),
            self::giftsAndNovelty($navigationRootId, $idsByPath),
        ];

        return ['tree' => $tree, 'idsByPath' => $idsByPath];
    }

    /**
     * @param array<string, string> $idsByPath
     *
     * @return list<array<string, mixed>>
     */
    private static function departments(string $rootId, array &$idsByPath): array
    {
        $departments = [];

        foreach (FashionSeedTaxonomy::DEPARTMENTS as $department) {
            $departmentId = self::register($idsByPath, $department);
            $types = [];

            foreach (FashionSeedTaxonomy::GARMENT_TYPES as $typeIndex => $type) {
                $typePath = $department . '/' . $type;
                $typeId = self::register($idsByPath, $typePath);
                $types[] = [
                    'id' => $typeId,
                    'parentId' => $departmentId,
                    'name' => $type,
                    'active' => true,
                    'children' => [
                        ...self::cutLeaves($department, $typeIndex, $typeId, $idsByPath),
                        ...self::trapLeaves($department, $type, $typeId, $idsByPath),
                    ],
                ];
            }

            $departments[] = [
                'id' => $departmentId,
                'parentId' => $rootId,
                'name' => $department,
                'active' => true,
                'children' => $types,
            ];
        }

        return $departments;
    }

    /**
     * @param array<string, string> $idsByPath
     *
     * @return list<array<string, mixed>>
     */
    private static function cutLeaves(string $department, int $typeIndex, string $typeId, array &$idsByPath): array
    {
        $leaves = [];
        $perDepartment = \count(FashionSeedTaxonomy::GARMENT_TYPES) * \count(FashionSeedTaxonomy::CUTS);
        $departmentIndex = array_search($department, FashionSeedTaxonomy::DEPARTMENTS, strict: true);
        \assert(\is_int($departmentIndex), description: 'department must be one of FashionSeedTaxonomy::DEPARTMENTS');
        $base = ($departmentIndex * $perDepartment) + ($typeIndex * \count(FashionSeedTaxonomy::CUTS));

        for ($cutOffset = 0; $cutOffset < \count(FashionSeedTaxonomy::CUTS); ++$cutOffset) {
            $leaf = FashionSeedTaxonomy::garmentLeaf($base + $cutOffset);
            $path = implode('/', $leaf['path']);
            $leaves[] = [
                'id' => self::register($idsByPath, $path),
                'parentId' => $typeId,
                'name' => end($leaf['path']),
                'active' => true,
            ];
        }

        return $leaves;
    }

    /**
     * @param array<string, string> $idsByPath
     *
     * @return list<array<string, mixed>>
     */
    private static function trapLeaves(string $department, string $type, string $typeId, array &$idsByPath): array
    {
        $name = self::TRAP_LEAF_NAMES[$department . '/' . $type] ?? null;

        if ($name === null) {
            return [];
        }

        $path = $department . '/' . $type . '/' . $name;

        return [[
            'id' => self::register($idsByPath, $path),
            'parentId' => $typeId,
            'name' => $name,
            'active' => true,
        ]];
    }

    /**
     * @param list<string>           $values
     * @param array<string, string>  $idsByPath
     *
     * @return array<string, mixed>
     */
    private static function sideBranch(string $rootId, string $branch, array $values, array &$idsByPath): array
    {
        $branchId = self::register($idsByPath, $branch);
        $children = [];

        foreach ($values as $value) {
            $path = $branch . '/' . $value;
            $children[] = [
                'id' => self::register($idsByPath, $path),
                'parentId' => $branchId,
                'name' => $value,
                'active' => true,
            ];
        }

        return ['id' => $branchId, 'parentId' => $rootId, 'name' => $branch, 'active' => true, 'children' => $children];
    }

    /**
     * @param array<string, string> $idsByPath
     *
     * @return array<string, mixed>
     */
    private static function giftsAndNovelty(string $rootId, array &$idsByPath): array
    {
        $branchId = self::register($idsByPath, 'Gifts & Novelty');
        $leafPath = 'Gifts & Novelty/Keepsakes';

        return [
            'id' => $branchId,
            'parentId' => $rootId,
            'name' => 'Gifts & Novelty',
            'active' => true,
            'children' => [[
                'id' => self::register($idsByPath, $leafPath),
                'parentId' => $branchId,
                'name' => 'Keepsakes',
                'active' => true,
            ]],
        ];
    }

    /**
     * @param array<string, string> $idsByPath
     */
    private static function register(array &$idsByPath, string $path): string
    {
        $id = SeedId::forPath(self::NAMESPACE, $path);
        $idsByPath[$path] = $id;

        return $id;
    }
}
