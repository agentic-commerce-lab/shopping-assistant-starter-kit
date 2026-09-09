<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Tool;

use Swag\AssistantStarterKit\Core\Commerce\CategoryTreeReader;
use Swag\AssistantStarterKit\Core\Commerce\Dto\CatalogScope;
use Swag\AssistantStarterKit\Core\Trace\TraceRecorder;
use Symfony\AI\Agent\Toolbox\Attribute\AsTool;

/**
 * What the shop sells, read from its own category tree.
 *
 * ## The question this exists for
 *
 * *"Do you sell bikes?"* — asked in **eight** of the 34 conversations exported 2026-09-09, and
 * answered in all eight with a bottle of cleaning fluid. The mechanism was never a ranking bug:
 * `bike` is a token in *Bike Wash 1L*, so the search matched, and a non-empty result skips the
 * orientation {@see NoMatchOrientation} attaches to an empty one. Then *"What do you sell?"* was
 * asked five times and answered five times with **no tool call at all** — a category list assembled
 * from the facet vocabulary in the prompt, which named saddles in one conversation while a search
 * for "Sattel" found nothing in another.
 *
 * Both are the same missing thing: an assortment question is not a product search, and there was no
 * tool that answered one. Asked twice, the invented answer even differed — "bike care products" in
 * one run, "bags and storage" in the next — because there was nothing for it to be consistent with.
 *
 * ## Why the guidance is here and not in the system prompt
 *
 * Capability control is toolbox construction, not a prompt instruction (D6), and the corollary holds
 * in reverse: an instruction to call a tool that may not exist is worse than none. This tool is
 * constructed only when the gateway can read a category tree, so *when to use it* belongs in the
 * description that appears exactly then — the same place `search_products` documents its own use.
 * It also keeps the system prompt out of a growth it has no need for.
 *
 * ## What it may and may not license
 *
 * The tree is the shop's own statement of its departments, so the absence of one is a fact and not
 * an inference. But it is a fact about **departments**, and this class is careful about the
 * difference: *"there is no department for complete bikes"* is supported, *"the shop does not sell
 * bikes"* is not — a product can be filed somewhere unexpected, and
 * {@see SearchProductsTool::NO_MATCH_NOTE} owns that prohibition for the same reason.
 *
 * ## No counts, and no ids
 *
 * {@see \Swag\AssistantStarterKit\Core\Commerce\Dto\CategoryNode} refuses a product count by design
 * — a figure in front of the model is the fabrication surface this pipeline exists to close — and
 * this reply carries names only. The node ids stay server-side too: an id in the reply is something
 * the model could quote at a shopper, and the prompt forbids showing internals.
 *
 * Empty departments are dropped, for {@see NoMatchOrientation}'s reason: offering someone an empty
 * aisle is the disappointment twice.
 */
#[AsTool(
    name: 'browse_categories',
    description: 'List the departments and sections this shop actually has, read from its own '
    . 'category tree. Call this for any question about the RANGE rather than about a product: '
    . '"what do you sell", "do you have bikes", "do you carry winter clothing", "what kind of shop '
    . 'is this". Never answer such a question from memory or from a product search — a search '
    . 'matches words in product names, so asking it whether a KIND of thing exists returns whatever '
    . 'happens to share a word. '
    . 'If nothing in the reply is a department for what they asked about, you may say the shop has '
    . 'no department for it and name what it does have instead. Say it about the DEPARTMENTS and not '
    . 'about the shop: never "we do not sell that". '
    . 'These are department names, not products — never present one as something the shopper can buy, '
    . 'and search for products once they have chosen a direction.',
)]
final class BrowseCategoriesTool
{
    /**
     * How many departments come back, and how many sections inside each.
     *
     * Twelve and six are readability bounds rather than cost ones, matching
     * {@see NoMatchOrientation::MAX_DEPARTMENTS}: a reply longer than that stops being an
     * orientation and becomes the catalogue handed over sideways, which `card.js` already refuses to
     * do with products.
     */
    private const MAX_DEPARTMENTS = 12;

    private const MAX_SECTIONS = 6;

    public const NOTE =
        'These are the departments this shop has, from its own category tree — not a search result. '
            . 'If none of them is where what the shopper asked about would live, you may say the shop '
            . 'has no department for it. Never say the shop does not sell it: a product can be filed '
            . 'somewhere unexpected, and this list does not rule that out.';

    public const TRUNCATED_NOTE = '(shortened — the shop has more departments than these, so do not describe this as its full range)';

    public function __construct(
        private readonly CategoryTreeReader $categories,
        private readonly TraceRecorder $trace,
        private readonly CatalogScope $scope = new CatalogScope(),
    ) {}

    /**
     * @return array{departments: list<array{name: string, sections?: list<string>}>, note: string, shortened?: string}
     */
    public function __invoke(): array
    {
        $departments = [];
        $stocked = $this->stocked(null);

        foreach (\array_slice($stocked, offset: 0, length: self::MAX_DEPARTMENTS) as $node) {
            $department = ['name' => $node->name];
            $sections = $node->hasChildren ? $this->sectionNames($node->id) : [];

            if ($sections !== []) {
                $department['sections'] = $sections;
            }

            $departments[] = $department;
        }

        $this->trace->record('categories.browsed', [
            'departments' => \count($departments),
            'truncated' => \count($stocked) > self::MAX_DEPARTMENTS,
        ]);

        $reply = ['departments' => $departments, 'note' => self::NOTE];

        if (\count($stocked) > self::MAX_DEPARTMENTS) {
            $reply['shortened'] = self::TRUNCATED_NOTE;
        }

        return $reply;
    }

    /**
     * @return list<string>
     */
    private function sectionNames(string $parentId): array
    {
        $names = array_map(
            static fn(object $node): string => (string) ($node->name ?? ''),
            \array_slice($this->stocked($parentId), offset: 0, length: self::MAX_SECTIONS),
        );

        return array_values(array_filter($names, static fn(string $name): bool => $name !== ''));
    }

    /**
     * The nodes under `$parentId` that actually hold products.
     *
     * @return list<\Swag\AssistantStarterKit\Core\Commerce\Dto\CategoryNode>
     */
    private function stocked(?string $parentId): array
    {
        $stocked = [];

        foreach ($this->categories->categories($parentId, $this->scope) as $node) {
            if ($node->hasProducts) {
                $stocked[] = $node;
            }
        }

        return $stocked;
    }
}
