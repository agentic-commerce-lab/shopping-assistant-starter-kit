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
     * The `Women`/`Men`/`Kids` top-level nodes, each carrying every garment type as a child.
     * @param array<string, string> $idsByPath
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
     * The cut leaves under one garment type (`Maxi Dresses`, `Midi Dresses`, ...), reusing
     * {@see FashionSeedTaxonomy::garmentLeaf()}'s own index arithmetic so this tree's leaf order
     * matches the fixture's.
     * @param array<string, string> $idsByPath
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
            $name = end($leaf['path']);
            \assert($name !== false, description: 'garmentLeaf() always returns a non-empty path');
            $leaves[] = self::leaf($idsByPath, $path, $typeId, $name);
        }

        return $leaves;
    }

    /**
     * The single extra leaf {@see self::TRAP_LEAF_NAMES} grafts onto one garment type, or nothing
     * for the other garment types that carry no trap.
     * @param array<string, string> $idsByPath
     * @return list<array<string, mixed>>
     */
    private static function trapLeaves(string $department, string $type, string $typeId, array &$idsByPath): array
    {
        $name = self::TRAP_LEAF_NAMES[$department . '/' . $type] ?? null;

        if ($name === null) {
            return [];
        }

        $path = $department . '/' . $type . '/' . $name;

        return [self::leaf($idsByPath, $path, $typeId, $name)];
    }

    /**
     * A `Brand`/`Season`/`Occasion` top-level node, one flat child per named value.
     * @param list<string>           $values
     * @param array<string, string>  $idsByPath
     * @return array<string, mixed>
     */
    private static function sideBranch(string $rootId, string $branch, array $values, array &$idsByPath): array
    {
        $branchId = self::register($idsByPath, $branch);
        $children = [];

        foreach ($values as $value) {
            $path = $branch . '/' . $value;
            $children[] = self::leaf($idsByPath, $path, $branchId, $value);
        }

        return ['id' => $branchId, 'parentId' => $rootId, 'name' => $branch, 'active' => true, 'children' => $children];
    }

    /**
     * The one hand-authored branch this tree carries outside the taxonomy fixtures — no seeded
     * product lives here, it exists only so the shop has a non-fashion corner to fall back on.
     * @param array<string, string> $idsByPath
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
            'children' => [self::leaf($idsByPath, $leafPath, $branchId, 'Keepsakes')],
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

    /**
     * The one write payload shape every leaf node in this tree shares — {@see self::cutLeaves()},
     * {@see self::trapLeaves()}, {@see self::sideBranch()} and {@see self::giftsAndNovelty()} each
     * built this same four-key array by hand until this helper replaced them.
     * @param array<string, string> $idsByPath
     * @return array<string, mixed>
     */
    private static function leaf(array &$idsByPath, string $path, string $parentId, string $name): array
    {
        return [
            'id' => self::register($idsByPath, $path),
            'parentId' => $parentId,
            'name' => $name,
            'active' => true,
        ];
    }
}
