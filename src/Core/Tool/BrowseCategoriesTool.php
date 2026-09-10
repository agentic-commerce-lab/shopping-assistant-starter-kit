<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Tool;

use Swag\AssistantStarterKit\Core\Commerce\CategoryTreeReader;
use Swag\AssistantStarterKit\Core\Commerce\Dto\CatalogScope;
use Swag\AssistantStarterKit\Core\Grounding\FactRenderer;
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
 * ## Two triggers, and the second one was measured after the first shipped
 *
 * The obvious one is a question about the range. The second is a product search that came back with
 * the wrong KIND of thing, and it is here because of what the model did when it had the tool but no
 * instruction to chain it. Measured on staging 2026-09-09, *"I want to buy a bike"*:
 *
 * > The search found no bikes, only a **Bike Wash 1L** in stock. Would you like me to check what
 * > kinds of products the shop *does* carry for cycling?
 *
 * It knew the tool was there and asked permission — so the shopper still got a bottle of cleaner and
 * a question instead of an answer. *"Do you sell bikes?"* was already fixed, because that reads as a
 * question about the range; *"I want to buy a bike"* reads as a product request and goes to the
 * search. Five of the corpus's eight Bike-Wash conversations used the first phrasing and two or three
 * the second.
 *
 * So the description says: do not offer, do not wait, and do not name the mismatched product. An
 * instruction rather than a "wrong kind of product" detector, which this project twice declined to
 * guess at — the candidate rule, that the term names no category, fails on *waterproof* and *gloves*.
 *
 * ## Why the guidance is here and not in the system prompt
 *
 * Capability control is toolbox construction, not a prompt instruction (D6), and the corollary holds
 * in reverse: an instruction to call a tool that may not exist is worse than none. This tool is
 * constructed only when the gateway can read a category tree, so *when to use it* belongs in the
 * description that appears exactly then — the same place `search_products` documents its own use.
 * It also keeps the system prompt out of a growth it has no need for.
 *
 * ## It licenses no absence claim at all, and the first version got that wrong
 *
 * The first note here offered the model a sentence: *"you may say the shop has no department for
 * it"*. It reads defensible — the tree is the shop's own statement of its departments — and it broke
 * a safety assertion the same day. `no_match_not_absence` went to **0 of 3 on both archetypes**,
 * against a documented band of 2/3–3/3, with the model writing *"The shop has no…"* in six runs out
 * of six.
 *
 * {@see \Swag\AssistantStarterKit\Eval\Assertion\NoAbsenceClaimInProse} matches on the SUBJECT —
 * `the shop has no`, `we do not carry` — and deliberately cannot tell "no department for bikes" from
 * "no bikes". That is the right design: a shopper reads the gist, one of those sentences is a word
 * away from the other, and a department tree is not an assortment. A bike filed under Components is
 * exactly the case `SearchProductsTool::NO_MATCH_NOTE` exists for.
 *
 * So this tool states what the shop **has** and never what it lacks. A shopper reading a department
 * list with no bikes in it draws the conclusion themselves, and the shop has made no claim it cannot
 * support. That is one degree less direct and it is the trade the safety rule requires.
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
    . 'If nothing in the reply is a department for what they asked about, name the departments the '
    . 'shop DOES have and ask which of them fits. Write no sentence about what the shop lacks — not '
    . '"the shop has no", not "we do not have", not "there is no department for": this list shows '
    . 'what exists, and the shopper can see for themselves what is not in it. '
    . 'Call it WITHOUT ASKING FIRST in one more case: a product search came back, but nothing in it '
    . 'is the kind of thing the shopper asked for — they wanted a bike and the search matched a bike '
    . 'cleaner because both names carry the word. Do not offer to check and wait for a yes, and do '
    . 'not name the mismatched product: call this, then answer with the departments the shop has. '
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
            . 'If none of them is where what the shopper asked about would live, name the ones it does '
            . 'have and ask which fits. Do NOT write a sentence about what the shop lacks — not about a '
            . 'department and not about a product. This list says what exists; a product can still be '
            . 'filed somewhere unexpected, so its absence from the list settles nothing.';

    public const TRUNCATED_NOTE = '(shortened — the shop has more departments than these, so do not describe this as its full range)';

    public function __construct(
        private readonly CategoryTreeReader $categories,
        private readonly TraceRecorder $trace,
        private readonly FactRenderer $renderer,
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

        // **An answer with no products in it must not inherit the last search's cards.** Measured on
        // staging after this tool shipped: *"I want to buy a bike"* produced the right prose — the
        // shop's real departments, no cleaner named — with a Bike Wash 1L card underneath it, because
        // the model searched first and `GroundingOutputProcessor` falls back to the last retrieved
        // batch when the prose names nothing. An empty registration is the documented honest default
        // for "the last call returned nothing", and it is what {@see \Swag\AssistantStarterKit\Core\Grounding\PreGrounding}
        // already does one step earlier for the same reason.
        //
        // It replaces the last batch, never the authoritative index — so a reply that goes on to name
        // a product the turn really did retrieve still renders its card.
        $this->renderer->registerRetrieved([]);

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
