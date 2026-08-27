<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Commerce\Dto;

/**
 * One node of the shop's category tree, as the model is allowed to see it.
 *
 * **There is no product count here, and that is a decision.** The obvious field was rejected twice
 * over: it is a figure, and a figure in front of the model is the fabrication surface this pipeline
 * exists to close; and it could not mean the same thing in both implementations — the fixture counts
 * sellable units, the DAL would count products — so a merchant's tree and the eval's tree would
 * disagree numerically while both were right. {@see \Swag\AssistantStarterKit\Core\Commerce\MatchCountReader}
 * is where an exact number comes from, and it answers a query rather than describing a node.
 *
 * `hasProducts` is what the decision actually needs: is this branch worth offering at all. `hasChildren`
 * is what lets a caller decide whether descending is worth another read.
 *
 * Like {@see ProductCard}, this is an ALLOWLIST. Never widen it with a passthrough array, and never add
 * a price, a stock level, a count or a URL.
 */
final readonly class CategoryNode
{
    /** @param list<string> $path this node's own name last, its ancestors before it */
    public function __construct(
        public string $id,
        public string $name,
        public array $path,
        public bool $hasProducts,
        public bool $hasChildren,
    ) {}
}
