<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Tool;

use Swag\AssistantStarterKit\Core\Commerce\CommerceGatewayInterface;
use Swag\AssistantStarterKit\Core\Commerce\Dto\CatalogScope;
use Swag\AssistantStarterKit\Core\Commerce\Dto\ProductCard;
use Swag\AssistantStarterKit\Core\Commerce\Dto\ProductQuery;
use Swag\AssistantStarterKit\Core\Commerce\MatchCountReader;
use Swag\AssistantStarterKit\Core\Grounding\DisclosedOptions;
use Swag\AssistantStarterKit\Core\Grounding\FactRenderer;
use Swag\AssistantStarterKit\Core\Grounding\RedundantParentFilter;
use Swag\AssistantStarterKit\Core\Grounding\VariantResolver;
use Swag\AssistantStarterKit\Core\Policy\AssistantConfig;
use Swag\AssistantStarterKit\Core\Policy\BlocklistFilter;
use Swag\AssistantStarterKit\Core\Retrieval\CandidateInterleave;
use Swag\AssistantStarterKit\Core\Retrieval\ExactMatchCount;
use Swag\AssistantStarterKit\Core\Retrieval\FacetProbe;
use Swag\AssistantStarterKit\Core\Retrieval\IntentCandidates;
use Swag\AssistantStarterKit\Core\Retrieval\IntentRetrieval;
use Swag\AssistantStarterKit\Core\Retrieval\MergedCandidates;
use Swag\AssistantStarterKit\Core\Retrieval\QueryBuilder;
use Swag\AssistantStarterKit\Core\Retrieval\QueryBuildResult;
use Swag\AssistantStarterKit\Core\Retrieval\RetrievalPass;
use Swag\AssistantStarterKit\Core\Retrieval\ShopperIntent;
use Swag\AssistantStarterKit\Core\Retrieval\TermContribution;
use Swag\AssistantStarterKit\Core\Trace\TraceRecorder;
use Symfony\AI\Agent\Toolbox\Attribute\AsTool;

/**
 * Read-only catalogue search exposed to the model as a Symfony AI tool.
 *
 * This method only orchestrates the injected services — it never reimplements
 * facet probing, query building, variant resolution or blocklist filtering
 * itself, which is what lets a third-party tool built the same way inherit the
 * same grounding guarantees. It returns product ids only: cards are registered
 * with {@see FactRenderer} for the controller to render after the run, never
 * serialised into the tool result the model sees.
 */
#[AsTool(
    name: 'search_products',
    description: 'Search this shop\'s catalogue. Price limits are honoured exactly. '
    . 'ALWAYS pass every option value the shopper named — colour, size and so on — in this '
    . 'same call via "options". Doing so resolves the exact variant in one step and returns '
    . 'its own stock and price; leaving them out returns the whole product family instead, '
    . 'and identifying the right member afterwards wastes the turn\'s tool-call budget. '
    . 'Each "options" entry is a [group, option] pair in that order, for example '
    . '"options": [["Colour", "Blue"], ["Size", "M"]]. A bare option value on its own — '
    . '"options": ["Blue", "M"] — also works when you do not know its group. '
    . 'Option group names, when given, must match this catalogue\'s own spelling '
    . 'exactly (for example "Colour", not "colour") — a group name that does not '
    . 'match a group this catalogue actually has is dropped rather than guessed at. '
    . 'Returns each product\'s id, name and option values, so you can tell them apart. '
    . 'It returns NO prices, stock or availability: the shop renders those. '
    . 'Never state a figure yourself. '
    . 'The shop shows the products from your MOST RECENT search to the shopper, as a short row '
    . 'of cards. So search for the thing you are actually answering about last, and ask for the '
    . 'few products that answer it rather than the maximum. '
    . 'If your answer covers more than one KIND of product — an occasion needing either a dress or '
    . 'a suit, say — do NOT search twice: pass both words in "terms" in this one call, and the '
    . 'shop will show some of each. Searching twice shows the shopper only the second one, so '
    . 'anything you named from the first search would be described but not shown. '
    . 'The reply also tells you how many products MATCHED, which is often far more than the few it '
    . 'returns. If it matched only a handful, show them and do not ask anything — a question buys '
    . 'nothing. If it matched many more than you are showing, do not present those few as if they '
    . 'were the whole answer: say plainly that there are many and ask ONE question that would narrow '
    . 'them (dress code, season, budget, size), or name what you are showing as a few examples. '
    . 'Always show products alongside such a question — never reply with a question and no products, '
    . 'and never ask a narrowing question twice in one conversation.',
)]
final class SearchProductsTool
{
    /**
     * How much wider than the model's `limit` the retrieval window is, and its bounds.
     *
     * Retrieval, ranking and truncation used to happen together inside the gateway, so a
     * narrow `limit` decided the answer before variant resolution ever ran. Now the gateway
     * retrieves this wider window, resolution and the blocklist run over all of it, and the
     * result is narrowed to the model's own `limit` afterwards — the ordering
     * ARCHITECTURE.md's lifecycle table always claimed.
     *
     * The floor matters more than the multiplier: a family with several variants must fit
     * inside the window whole, or ranking's in-stock bias can still hide the sold-out unit.
     * The ceiling bounds retrieval cost, since these are id-only reads.
     */
    private const CANDIDATE_MULTIPLIER = 4;

    private const MIN_CANDIDATES = 20;

    private const MAX_CANDIDATES = 50;

    /**
     * The most products one search may return, and therefore the most cards a turn can render.
     *
     * **8, down from 20 on 2026-08-21.** "What do you sell?" came back with twenty cards in a
     * horizontally scrolling row — which is not an answer, it is the catalogue handed over sideways,
     * and `card.js` calls its own row "a shortlist". A shopper comparing twenty things is a shopper
     * who has been given the work back.
     *
     * It is a rejection rather than a coercion, like every other bound the tools enforce: a model
     * asking for more is asking for something the shop will not present, and telling it so is more
     * useful than silently giving it less.
     */
    private const MAX_LIMIT = 8;

    /**
     * What a search returns when the model does not say.
     *
     * Below {@see self::MAX_LIMIT}, not equal to it: a default that sits on the ceiling makes the
     * ceiling the normal case, and the normal case should be a shortlist somebody can actually read.
     * The model can still ask for more, up to the bound.
     */
    private const DEFAULT_LIMIT = 5;

    /**
     * What the model is told when nothing matched, and it is deliberately not "the shop does not
     * sell this."
     *
     * A search that matched nothing has established one thing — these words found no products — and
     * the model used to read it as proof of absence: asked for "gloves", it answered "this shop
     * doesn't carry gloves" about a shop that sells the Commuter Glove. Absence of evidence stated
     * as evidence of absence is the same class of claim D4 exists to prevent.
     *
     * A constant so a test can assert the intent without pinning the prose, the same way
     * {@see UnmatchedOptionRetry::NOTE} and {@see RelaxedTermRetry::NOTE} are.
     */
    public const NO_MATCH_NOTE =
        'This search matched nothing. That means these words found no '
            . 'products, NOT that the shop has none of this kind — say the search came up empty and '
            . 'offer to try different words. Do not tell the shopper the shop does not sell it.';

    // @mago-expect lint:excessive-parameter-list
    // The first eight parameters are one collaborator each, orchestrated here without being
    // reimplemented; the brief dictates that list, and the mandated tests construct it
    // positionally, so it cannot shrink without either duplicating a collaborator's logic here or
    // breaking those tests. The ninth is not a collaborator but per-request state — where the
    // shopper is standing — and it goes last so those positional constructions keep meaning what
    // they meant.
    public function __construct(
        private readonly CommerceGatewayInterface $gateway,
        private readonly FacetProbe $facetProbe,
        private readonly QueryBuilder $queryBuilder,
        private readonly VariantResolver $variantResolver,
        private readonly BlocklistFilter $blocklist,
        private readonly FactRenderer $renderer,
        private readonly TraceRecorder $trace,
        private readonly AssistantConfig $config,
        /**
         * The category the shopper is browsing, or null. A default constraint on this turn's
         * searches, not a bound the model chose — so it is retried away rather than enforced when
         * it costs the shopper an answer (P9).
         */
        private readonly ?string $browsingCategoryId = null,
    ) {}

    /**
     * @param ?string $term    Free-text search term, e.g. "water bottle".
     * @param ?array<array-key, string> $terms Up to 3 search terms for ONE search — three in TOTAL, counting "term" if you pass that too — when your answer covers more than one kind of product (for example ["occasion dress", "occasion suit"]). Their results are interleaved, so the limit is shared between them rather than spent on the first. Use this instead of searching twice: the shop shows only your most recent search, so a second search silently replaces the first.
     * @param ?float  $priceMax Maximum price, inclusive, in the shop's currency.
     * @param ?float  $priceMin Minimum price, inclusive, in the shop's currency.
     * @param ?string $brand   Brand name to filter by.
     * @param ?array<array-key, array<array-key, string>|string> $options Option selections narrowing to one variant, each a [group, option] pair such as [["Colour", "Blue"], ["Size", "M"]]. Group names use this catalogue's own spelling; a bare option value on its own also works.
     * @param int $limit Maximum number of products to return (1-8, default 5). The shop renders these as a shortlist of cards, so ask for the few that answer the question rather than the maximum.
     *
     * @return array{
     *     products: list<array{id: string, name: string, options: array<string, string>, properties: array<string, list<string>>, soldOut?: true, available?: true, reasons?: list<string>}>,
     *     total: int,
     *     matched: int,
     *     more: bool,
     *     families?: list<array{
     *         name: string,
     *         shown: int,
     *         variants: int,
     *         options: array<string, list<string>>,
     *         options_truncated?: bool,
     *     }>,
     *     terms_without_results?: list<string>,
     *     shop_sells?: list<string>,
     *     shop_sells_note?: string,
     *     note?: string,
     * }
     */
    // @mago-expect lint:excessive-parameter-list
    // #[AsTool] derives the model-facing JSON Schema from this exact signature by reflection
    // (see the class docblock); collapsing these into a DTO would either change the schema the
    // model sees or break the mandated test, which calls this method with these named scalar
    // arguments directly.
    public function __invoke(
        ?string $term = null,
        ?float $priceMax = null,
        ?float $priceMin = null,
        ?string $brand = null,
        ?array $options = null,
        ?array $terms = null,
        int $limit = self::DEFAULT_LIMIT,
    ): array {
        $searchTerms = SearchTermList::of($term, $terms, 'terms');
        $brand = Guard::boundedString($brand, 120, 'brand');
        $requestedLimit = Guard::boundedInt($limit, 1, self::MAX_LIMIT, 'limit');

        // The model's `limit` is honoured exactly, and the ceiling stays a rejection: 20
        // bounds context size and cost, and a model asking for more is asking for something
        // it may not have.
        //
        // What used to be coerced here was the FLOOR, because retrieval and truncation were
        // the same step: a limit of 1 made the answer a function of retrieval ranking rather
        // than of the shopper's question, and nothing downstream could repair it —
        // VariantResolver cannot disambiguate a set of one, and the blocklist only removes.
        // Ranking's in-stock bias sorts a sold-out unit LAST, so "do you have the blue jersey
        // in M?" with limit 1 returned the blue L that happens to be in stock, and the
        // grounding pipeline then rendered a real price for the variant nobody asked about.
        //
        // The two concerns are now separate rather than traded off: retrieval reads the wider
        // candidate window below, and narrowing to the model's own limit happens after
        // resolution and the blocklist have run over all of it. So `limit` no longer needs
        // coercing, and the shopper's bound is no longer silently ignored.
        $candidateLimit = min(self::MAX_CANDIDATES, max(
            $requestedLimit * self::CANDIDATE_MULTIPLIER,
            self::MIN_CANDIDATES,
        ));
        $selections = VariantSelectionGuard::fromRaw($options, 'options');

        $scope = $this->config->scope;
        $facets = $this->facetProbe->probe($scope);

        $retrieval = new IntentRetrieval($this->gateway, $this->queryBuilder, $this->trace, $this->browsingCategoryId);

        // One pass per term, and one pass with no term at all when the shopper only gave a price or an
        // option — `[null]` rather than `[]`, so "anything under 40" still searches.
        $candidates = [];

        foreach ($searchTerms === [] ? [null] : $searchTerms as $searchTerm) {
            $candidates[] = $retrieval->run(
                new ShopperIntent(
                    term: $searchTerm,
                    priceMax: $priceMax,
                    priceMin: $priceMin,
                    brand: $brand,
                    selections: $selections,
                ),
                $facets,
                $requestedLimit,
                $candidateLimit,
                $scope,
            );
        }

        // Interleaved, never concatenated: a family found by the first term would otherwise fill the
        // limit before the second term was reached, which is the very row shape this argument exists to
        // fix. See CandidateInterleave.
        $cards = CandidateInterleave::of(
            array_map(static fn(IntentCandidates $one): array => $one->cards, $candidates),
            self::MAX_CANDIDATES,
        );

        // The first pass's, deliberately: every intent above carries the SAME options, price and brand
        // — only the term varies — so every buildResult resolved the same selections against the same
        // catalogue spelling.
        $buildResult = $candidates[0]->buildResult;
        $optionNote = MergedCandidates::firstNote($candidates);
        $windowSaturated = MergedCandidates::anySaturated($candidates);

        // Canonical selections, not $intent->selections: QueryBuilder already resolved
        // each one against the catalog's own spelling — see
        // VariantSelectionFilterResolver's docblock (Finding I1). Handing VariantResolver
        // the raw, model-cased selections instead would make gateway->resolveVariant()'s
        // case-sensitive matching fail exactly when the model's casing differs from the
        // catalog's — the retrieval filter above would already have narrowed correctly
        // while variant resolution silently did not.
        $cards = $this->variantResolver->resolve($cards, $buildResult->canonicalSelections, $scope);

        $filtered = $this->blocklist->apply($cards, $scope);
        $this->trace->record('blocklist.filter', [
            'stage' => 'post',
            'removedIds' => $filtered['removed'],
        ]);

        // **After the blocklist, deliberately, not before it.** The blocklist must see everything
        // retrieval and resolution produced, because `BlocklistSurvivors` diffs `retrieve`'s
        // retained ids against this stage's removals: a card taken out upstream would still count
        // as retained, never appear as removed, and so read as a survivor that leaked. A redundancy
        // narrowing must not be able to forge that.
        //
        // Shopware's search returns a family parent alongside its children, so "what bib shorts do
        // you sell?" came back as Black/L, Black/M and then the parent again — an unbuyable
        // aggregate and its disclosure, beside the two rows that had already answered the question.
        // See RedundantParentFilter for why only a superseded parent goes.
        $survivors = RedundantParentFilter::apply($filtered['cards']);
        $supersededParents = \count($filtered['cards']) - \count($survivors);

        // Narrowing happens HERE, not in retrieval. Everything above needed the full
        // candidate window to be correct — VariantResolver cannot disambiguate a set of
        // one — and nothing below can recover a unit that retrieval already dropped.
        //
        // FamilyDiversifier, not a plain array_slice: relevance ranking clusters same-family
        // variants adjacently (they share a name/description), so a plain prefix here could
        // be one product's whole size run rather than a spread of styles. See its own
        // docblock, and CandidateInterleave's — this is the reordering step that class's
        // docblock now names explicitly.
        $returned = FamilyDiversifier::of($survivors, $requestedLimit);

        $matchReasons = $this->config->enableMatchReasons
            ? MatchReasons::of($returned, MergedCandidates::byTerm($candidates), \count($survivors))
            : [];

        if ($matchReasons !== [] && array_filter($matchReasons) !== []) {
            $this->trace->record('retrieve.match_reasons', ['reasons' => $matchReasons]);
        }

        // Recorded rather than silent: a bounded result that nobody wrote down reads as
        // complete coverage. This is its own stage because `retrieve` keeps meaning "what
        // retrieval returned" — BlocklistSurvivors diffs that set against the blocklist's
        // removals, and narrowing must not quietly shrink what that check sees.
        $this->trace->record('retrieve.narrow', [
            'candidateLimit' => $candidateLimit,
            'returnLimit' => $requestedLimit,
            'survivors' => \count($survivors),
            'supersededParents' => $supersededParents,
            'truncated' => \count($survivors) - \count($returned),
            'returnedIds' => array_map(static fn($card) => $card->id, $returned),
        ]);

        // The narrowed set, never $survivors: FactRenderer treats the last registered set
        // as the authority on what the model saw, so registering cards the model never
        // received would widen what counts as "not invented" and reopen R47's gap.
        $this->renderer->registerRetrieved($returned);

        $result = [
            // id + name + options, never a figure — see ToolProductSummary for why bare ids made
            // variant identification cost one tool call per candidate.
            'products' => ToolProductSummary::of($returned, $matchReasons),
            // `total` deliberately keeps meaning "how many are in products" (T3). The model has
            // learned it; redefining a number in place is how something else quietly breaks.
            'total' => \count($returned),
            // What the search actually found, which is the number `total` was being read as. Excludes
            // superseded parents: RedundantParentFilter removed them because their own variants are
            // present, and counting one back in would report a product twice.
            'matched' => \count($survivors),
            // `matched` is a floor, not a census, whenever the candidate window filled up (T4) — UNLESS
            // the gateway can count, in which case it is exact and `more` means what it says. See
            // MatchCountReader for why the difference matters: at the 50-unit window the assistant
            // could not tell "all six occasion dresses" from "four of three hundred".
            'more' => $windowSaturated,
        ];

        $exact = ExactMatchCount::of($this->gateway, $candidates, $scope);

        if ($exact !== null) {
            // `more` keeps meaning what T4 gave it — *`matched` is a floor rather than a census* — so
            // an exact count makes it false rather than "there are more than I showed". That second
            // fact is already in the reply: `matched` against `total`. Redefining a field the model has
            // learned is how something else quietly breaks (the same argument T3 makes for `total`).
            $result['matched'] = $exact;
            $result['more'] = false;
        }

        // Only when there is something to disclose. A family returned whole is already fully
        // described by `products`, and an empty array is context the model pays to read.
        $families = TruncatedFamilies::of(
            $survivors,
            $returned,
            WholeFamilyResolver::resolve($this->gateway, $survivors, $returned, $scope),
        );

        if ($families !== []) {
            $result['families'] = $families;

            // Recorded so the property audit knows these came from the shop: this block exists to let
            // the model name an option no returned card carries, and the audit flagged that until now.
            $this->trace->record(DisclosedOptions::STAGE, [
                'source' => 'families',
                'options' => DisclosedOptions::valuesOf(array_column($families, 'options')),
            ]);
        }

        // Which of the model's own terms put nothing DISTINCT on screen. Undisclosed, a model that
        // searched ["Occasion Dresses", "Occasion Suits"] writes "here are dresses and suits" while
        // only dresses render — the exact prose/cards mismatch `terms` was added to remove, arriving
        // through a different door. Measured 2026-08-27; see TermContribution.
        $barren = TermContribution::termsWithoutResults(MergedCandidates::byTerm($candidates), $returned);

        if ($barren !== []) {
            $result['terms_without_results'] = $barren;
        }

        if ($returned === []) {
            // The shop's own departments, so an empty result can point the shopper somewhere real
            // instead of asking them to guess better words. Measured on the local shop: "what to wear to
            // a wedding" against a cycling catalogue produced "could you share more details… so I can
            // try different search terms", which cannot succeed. See NoMatchOrientation.
            $result = [...$result, ...NoMatchOrientation::replyFor($this->gateway, $scope, self::NO_MATCH_NOTE)];
        } elseif ($optionNote !== null) {
            $result['note'] = $optionNote;
        }

        return $result;
    }
}
