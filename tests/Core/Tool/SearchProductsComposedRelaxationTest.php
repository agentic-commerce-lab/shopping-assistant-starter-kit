<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Tool;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Commerce\FixtureCommerceGateway;
use Swag\AssistantStarterKit\Core\Grounding\FactRenderer;
use Swag\AssistantStarterKit\Core\Grounding\VariantResolver;
use Swag\AssistantStarterKit\Core\Policy\AssistantConfig;
use Swag\AssistantStarterKit\Core\Policy\BlocklistFilter;
use Swag\AssistantStarterKit\Core\Retrieval\FacetProbe;
use Swag\AssistantStarterKit\Core\Retrieval\QueryBuilder;
use Swag\AssistantStarterKit\Core\Retrieval\RelaxedTermRetry;
use Swag\AssistantStarterKit\Core\Retrieval\UnmatchedOptionRetry;
use Swag\AssistantStarterKit\Core\Tool\SearchProductsTool;
use Swag\AssistantStarterKit\Core\Trace\TraceRecorder;

/**
 * Two relaxations that each work alone and never ran together.
 *
 * ## The measured failure
 *
 * Found on the staging shop on 2026-09-03 by running the reported turn through
 * `swag:assistant:probe --ask="i want purple tyres"` and reading the trace. This is the dead end
 * Robin reported, and it is one layer below where {@see UnrecordedOptions} sits:
 *
 * ```
 * #8  query.build              filtersApplied: ["properties.Colour"]   searchTerm: tyres
 * #9  retrieve                 hits: 0
 * #10 retrieve.without_options droppedFields: ["properties.Colour"]    hits: 0
 * #11 retrieve.relaxTerm       term: tyres  relaxedTerm: tyre          hits: 0
 * #18 turn.end                 outcome: no_result
 * ```
 *
 * The shop sells four tyres. Measured against its own catalogue in the same session:
 *
 * ```
 * probe --search="tyres"  -> 0        probe --search="tyre" -> 4        probe --search="purple" -> 0
 * ```
 *
 * So each retry failed for a different reason and neither reason was its own. `without_options`
 * dropped the colour and kept the plural, which matches nothing. `relaxTerm` fixed the plural and
 * kept the colour — deliberately, since it "relaxes ONE thing" — which matches nothing either.
 * Reaching the tyres needs the relaxed term AND no option filter, and applying them one at a time
 * never produces that pair.
 *
 * ## Why that is exactly the argument already written down
 *
 * {@see \Swag\AssistantStarterKit\Core\Retrieval\RetrievalPass}'s own docblock makes this case for
 * the shopper's category: *"Neither is an ordering problem. It is two passes."* Giving the category
 * up last failed the `page_context_not_a_cage` eval because "gloves" in Jerseys needs the relaxed
 * term AND no category. This is the same shape with the option filter in the category's place, and
 * the same answer.
 *
 * ## What it costs, and why the price is right
 *
 * A fourth gateway read, and only on a search that has already returned nothing three times — so it
 * cannot slow down a search that works. The alternative is what staging did: tell a shopper standing
 * in front of four tyres that the search found nothing.
 */
final class SearchProductsComposedRelaxationTest extends TestCase
{
    private string $catalogue = '';

    private TraceRecorder $trace;

    /** @param non-empty-string $name */
    public function __construct(string $name)
    {
        parent::__construct($name);

        $this->trace = new TraceRecorder();
    }

    protected function setUp(): void
    {
        $this->catalogue = tempnam(sys_get_temp_dir(), 'composed') . '.json';
        file_put_contents(
            $this->catalogue,
            json_encode(
                [
                    'products' => [
                        [
                            'id' => 'roadtyre28c000000000000000000001',
                            'name' => 'Road Tyre 28c',
                            'description' => 'A fast-rolling tyre for tarmac.',
                            'price' => 38.00,
                            'stock' => 12,
                            'url' => '/p/road-tyre-28c',
                            'categoryPath' => ['Tyres'],
                            'properties' => ['Season' => ['Summer'], 'Terrain' => ['Road']],
                            'variants' => [],
                        ],
                        // Why `properties.Colour` exists in this shop at all, and therefore why the
                        // shopper's colour is APPLIED rather than dropped.
                        [
                            'id' => 'clubjersey0000000000000000000001',
                            'name' => 'Club Jersey',
                            'description' => 'A lightweight club jersey.',
                            'price' => 69.00,
                            'stock' => 4,
                            'url' => '/p/club-jersey',
                            'categoryPath' => ['Apparel'],
                            'properties' => ['Colour' => ['Blue']],
                            'variants' => [],
                        ],
                    ],
                ],
                \JSON_THROW_ON_ERROR,
            ),
        );
    }

    protected function tearDown(): void
    {
        if ($this->catalogue !== '' && is_file($this->catalogue)) {
            unlink($this->catalogue);
        }
    }

    /** The plural alone is already beyond the first two attempts, and the shop has the product. */
    public function testThePluralAndTheOptionAreRelaxedTogether(): void
    {
        $result = $this->tool()(term: 'tyres', options: [['Colour', 'Purple']]);

        self::assertSame(['roadtyre28c000000000000000000001'], self::ids($result));
    }

    /**
     * And once the tyre is in hand, the answer the shopper actually deserves follows: the shop
     * records no colour for it. That is the whole point of getting here rather than to `no_result`.
     */
    public function testTheAnswerIsThenTheUnrecordedAttribute(): void
    {
        $result = $this->tool()(term: 'tyres', options: [['Colour', 'Purple']]);

        self::assertSame(['Colour'], $result['options_not_recorded'] ?? null);
    }

    /** Both diagnoses apply to this result, so the model is told both. */
    public function testBothRelaxationsAreDisclosed(): void
    {
        $result = $this->tool()(term: 'tyres', options: [['Colour', 'Purple']]);

        $note = $result['note'] ?? '';
        self::assertIsString($note);
        self::assertStringContainsString(UnmatchedOptionRetry::NOTE, $note);
        self::assertStringContainsString(RelaxedTermRetry::NOTE, $note);
    }

    /** A fourth read that nobody can see is a fourth read nobody can debug. */
    public function testTheComposedAttemptIsRecordedInTheTrace(): void
    {
        $this->tool()(term: 'tyres', options: [['Colour', 'Purple']]);

        $stages = array_map(static fn(object $event): string => (string) $event->stage, $this->trace->events());

        self::assertContains('retrieve.relaxTerm_without_options', $stages);
    }

    /**
     * The guard: a search the first three attempts can answer must not pay for a fourth. The
     * singular reaches the tyre with only the option filter dropped, so nothing composed runs.
     */
    public function testASearchTheEarlierAttemptsAnswerDoesNotComposeAnything(): void
    {
        $result = $this->tool()(term: 'tyre', options: [['Colour', 'Purple']]);

        self::assertSame(['roadtyre28c000000000000000000001'], self::ids($result));

        $stages = array_map(static fn(object $event): string => (string) $event->stage, $this->trace->events());

        self::assertNotContains('retrieve.relaxTerm_without_options', $stages);
    }

    /**
     * @param array<string, mixed> $result
     *
     * @return list<string>
     */
    private static function ids(array $result): array
    {
        /** @var list<array{id: string}> $products */
        $products = $result['products'] ?? [];

        return array_map(static fn(array $product): string => $product['id'], $products);
    }

    private function tool(): SearchProductsTool
    {
        $gateway = FixtureCommerceGateway::fromFile($this->catalogue);
        $this->trace = new TraceRecorder();

        return new SearchProductsTool(
            $gateway,
            new FacetProbe($gateway, $this->trace),
            new QueryBuilder(),
            new VariantResolver($gateway, $this->trace),
            new BlocklistFilter(),
            new FactRenderer($this->trace),
            $this->trace,
            new AssistantConfig(),
        );
    }
}
