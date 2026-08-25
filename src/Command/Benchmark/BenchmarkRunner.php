<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Command\Benchmark;

use Swag\AssistantStarterKit\Core\Commerce\Dto\CatalogScope;
use Swag\AssistantStarterKit\Core\Commerce\Dto\Facet;
use Swag\AssistantStarterKit\Core\Commerce\Dto\FacetType;
use Swag\AssistantStarterKit\Core\Commerce\Dto\ProductQuery;
use Swag\AssistantStarterKit\Core\Policy\AssistantConfig;
use Swag\AssistantStarterKit\Core\Prompt\CatalogVocabulary;
use Swag\AssistantStarterKit\Core\Prompt\SystemPrompt;
use Swag\AssistantStarterKit\Core\Retrieval\FacetProbe;
use Swag\AssistantStarterKit\Core\Trace\TraceRecorder;

/**
 * Measures what the assistant costs against whatever catalogue the gateway is pointed at.
 *
 * **No model, deliberately.** Every number the spec asks for — facet-probe milliseconds, search
 * milliseconds, the vocabulary block's counts, prompt characters, retrieve hits, the cards endpoint's
 * lookups — is produced by code below the model, so a model call would add its own latency and its
 * own nondeterminism to a measurement about the shop. Leaving it out makes the command free to run,
 * repeatable, and honest about what it measures. The model's own cost is a separate question and the
 * eval suite already spends real money answering it.
 *
 * **Above the gateway seam only.** This class never names a Shopware type, which is what lets its
 * test run against {@see \Swag\AssistantStarterKit\Core\Commerce\FixtureCommerceGateway} with no
 * database while production hands it the DAL gateway.
 *
 * **Read-only.** Nothing here calls `addToCart()` or `cart()`, and
 * `BenchmarkRunnerTest::testItNeverWritesToTheShop` is what keeps it that way.
 */
final class BenchmarkRunner
{
    /**
     * How wide a candidate window each measured search asks for.
     *
     * `SearchProductsTool` computes its own between `MIN_CANDIDATES` (20) and `MAX_CANDIDATES` (50);
     * 50 is the widest it ever asks the gateway for, so timing that is timing the worst case rather
     * than an average of window sizes the tool might have chosen.
     */
    private const CANDIDATE_LIMIT = 50;

    /**
     * What the model would actually be shown.
     *
     * `SearchProductsTool::DEFAULT_LIMIT` is private, so this is a deliberate copy rather than a
     * reference — and it is reported beside the hit count because phase A found the tool's reply
     * carries `total => count($returned)`, so the shopper is never told the difference between the
     * two. Keep in step if that default moves.
     */
    private const DEFAULT_SHORTLIST = 5;

    public function __construct(
        private readonly CountingGateway $gateway,
    ) {}

    /**
     * @param list<string> $terms
     * @param list<string> $cardIds
     */
    public function run(
        string $shopLabel,
        AssistantConfig $config,
        array $terms,
        int $repetitions,
        array $cardIds,
    ): BenchmarkReport {
        return new BenchmarkReport(
            shopLabel: $shopLabel,
            vocabulary: $this->vocabulary($config),
            facetProbe: $this->facetProbe(),
            queries: array_map(fn(string $term): QueryMeasurement => $this->query($term, $repetitions), $terms),
            cards: $this->cards($cardIds),
        );
    }

    private function vocabulary(AssistantConfig $config): VocabularyMeasurement
    {
        $facets = $this->gateway->facets(new CatalogScope());
        $stats = CatalogVocabulary::renderWithStats($facets);

        // Terms facets with values only — exactly what CatalogVocabulary::termsFields() keeps.
        // Counting range facets here would inflate "available" with fields the block never renders by
        // design (a range facet's min/max are numbers, and putting a number in front of the model is
        // the fabrication surface FactRenderer exists to prevent), making the gap look worse than it
        // is.
        $available = array_filter(
            $facets->facets,
            static fn(Facet $facet): bool => FacetType::Terms === $facet->type && [] !== $facet->values,
        );

        $availableValues = 0;
        foreach ($available as $facet) {
            $availableValues += \count($facet->values);
        }

        return new VocabularyMeasurement(
            available: new VocabularyCounts(\count($available), $availableValues),
            sent: new VocabularyCounts($stats['fieldCount'], $stats['valueCount']),
            truncated: $stats['truncated'],
            size: new PromptSize(
                vocabularyChars: \strlen($stats['text']),
                promptChars: \strlen(SystemPrompt::build($config, $stats['text'])),
            ),
        );
    }

    /**
     * Cold then warm, because {@see FacetProbe} keeps a per-instance cache and the two numbers
     * describe the first turn of a conversation and every later one.
     */
    private function facetProbe(): FacetProbeMeasurement
    {
        $probe = new FacetProbe($this->gateway, new TraceRecorder());
        $scope = new CatalogScope();

        $startedLive = self::nowNs();
        $probe->probe($scope);
        $live = self::elapsedMs($startedLive);

        $startedCached = self::nowNs();
        $probe->probe($scope);

        return new FacetProbeMeasurement(liveMs: $live, cachedMs: self::elapsedMs($startedCached));
    }

    private function query(string $term, int $repetitions): QueryMeasurement
    {
        $timings = new Timings();
        $scope = new CatalogScope();
        $hits = 0;

        for ($i = 0; $i < $repetitions; ++$i) {
            $startedAt = self::nowNs();
            $cards = $this->gateway->search(new ProductQuery(term: $term, limit: self::CANDIDATE_LIMIT), $scope);
            $timings->add(self::elapsedMs($startedAt));

            $hits = \count($cards);
        }

        return new QueryMeasurement(
            $term,
            $timings,
            retrieveHits: $hits,
            narrowedTo: min($hits, self::DEFAULT_SHORTLIST),
        );
    }

    /** @param list<string> $ids */
    private function cards(array $ids): CardEndpointMeasurement
    {
        $before = $this->gateway->callsTo('product');
        $scope = new CatalogScope();

        $startedAt = self::nowNs();

        foreach ($ids as $id) {
            // Exactly what AssistantCardController does: one lookup per id, no batching.
            $this->gateway->product($id, $scope);
        }

        return new CardEndpointMeasurement(
            ids: \count($ids),
            lookups: $this->gateway->callsTo('product') - $before,
            totalMs: self::elapsedMs($startedAt),
        );
    }

    private static function elapsedMs(int $startedAt): float
    {
        return (float) (self::nowNs() - $startedAt) / 1_000_000.0;
    }

    /**
     * Nanoseconds from a monotonic clock, so an interval cannot come out negative because someone
     * adjusted the wall clock mid-run.
     *
     * Cast because PHP's own signature for `hrtime(true)` is `int|float|false` — float only where an
     * int would overflow, which needs an uptime of ~292 years, and `false` only if the platform has
     * no monotonic clock at all. Narrowing it here once beats four call sites the analyzer cannot
     * prove.
     */
    private static function nowNs(): int
    {
        return (int) hrtime(true);
    }
}
