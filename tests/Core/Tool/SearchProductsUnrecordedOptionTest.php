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
use Swag\AssistantStarterKit\Core\Tool\SearchProductsTool;
use Swag\AssistantStarterKit\Core\Trace\TraceRecorder;

/**
 * The dead end, reported from staging on 2026-09-03.
 *
 * A shopper asked for **purple tyres**. The assistant said there were none and offered to check
 * which other colours were available — and since no tyre in that shop records a colour at all, the
 * follow-up search found nothing and the turn ended on:
 *
 * > "It may be that the shop has none in stock right now, or that the search words didn't match —
 * > but I can't tell which."
 *
 * Honest, and worthless. The shop **can** tell: `properties.Colour` exists (the apparel has it) and
 * not one tyre carries it. That is the fact `VISION.md` sells the whole product on — *"turn 'your AI
 * is bad' into 'your products are missing attribute X'"* — and until now nothing computed it, so the
 * model had to infer a set-level claim from eight per-product option lists while
 * {@see \Swag\AssistantStarterKit\Core\Prompt\SystemPrompt} forbade every absolute phrasing it could
 * have used.
 *
 * Two shapes reach here and only the first one had a note at all:
 *
 * | Asked | Facet | What used to happen |
 * |---|---|---|
 * | `[Colour, Purple]` | exists shop-wide, no tyre has it | filter applied, narrowed to nothing, `UnmatchedOptionRetry` note |
 * | `[Print, Cat]` | no such facet anywhere | filter dropped in `QueryBuilder`, **silently** — the tyres came back as though nothing had been asked |
 *
 * The third test is the one that keeps this honest: a group the products DO record, asked for with a
 * value they do not have, must never be reported as unrecorded. "The shop lists no season for tyres"
 * would be a false statement about a shop whose tyres all list one.
 */
final class SearchProductsUnrecordedOptionTest extends TestCase
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
        $this->catalogue = tempnam(sys_get_temp_dir(), 'unrecorded') . '.json';
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
                        [
                            'id' => 'touringtyre00000000000000000001',
                            'name' => 'Touring Tyre',
                            'description' => 'A reflective tyre for commuting.',
                            'price' => 44.00,
                            'stock' => 7,
                            'url' => '/p/touring-tyre',
                            'categoryPath' => ['Tyres'],
                            'properties' => ['Season' => ['All-season'], 'Terrain' => ['Road']],
                            'variants' => [],
                        ],
                        // The apparel is why `properties.Colour` exists in this shop at all, which is
                        // what makes the purple request an APPLIED filter rather than a dropped one.
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

    /**
     * The reported turn. The shop has a Colour group and the tyres are not in it, so the answer the
     * shopper deserves is "this shop does not record a colour for tyres" — a fact, not a hedge.
     */
    public function testAGroupNoReturnedProductRecordsIsNamed(): void
    {
        $result = $this->tool()(term: 'tyre', options: [['Colour', 'Purple']]);

        self::assertNotSame([], self::ids($result), 'the tyres themselves still have to come back');
        self::assertSame(['Colour'], $result['options_not_recorded'] ?? []);
    }

    /**
     * The silent half. `Print` is in no facet of this catalogue, so `QueryBuilder` drops the clause
     * and the first search never narrows — the model used to receive tyres and no hint that the
     * thing the shopper actually asked for had been ignored.
     */
    public function testAGroupTheCatalogueDoesNotHaveIsNamed(): void
    {
        $result = $this->tool()(term: 'tyre', options: [['Print', 'Cat']]);

        self::assertNotSame([], self::ids($result));
        self::assertSame(['Print'], $result['options_not_recorded'] ?? []);
    }

    /**
     * The guard, and the reason this cannot be a one-line check. Every tyre records a Season; none
     * records `Winter`. "The shop does not record a season for tyres" would be false, and this is
     * the exact class of claim the project exists to prevent — so the group must not be named, and
     * {@see \Swag\AssistantStarterKit\Core\Retrieval\UnmatchedOptionRetry}'s own note stays the one
     * that speaks.
     */
    public function testAGroupTheProductsDoRecordIsNotNamed(): void
    {
        $result = $this->tool()(term: 'tyre', options: [['Season', 'Winter']]);

        self::assertNotSame([], self::ids($result));
        self::assertArrayNotHasKey('options_not_recorded', $result);
    }

    /** An ordinary search asks about no option, so there is nothing to disclose. */
    public function testASearchWithNoOptionsDisclosesNothing(): void
    {
        $result = $this->tool()(term: 'tyre');

        self::assertArrayNotHasKey('options_not_recorded', $result);
    }

    /**
     * The note has to carry the permission with the fact — the model is otherwise forbidden every
     * absolute phrasing by the system prompt, which is exactly how the reported turn dead-ended —
     * and it must not model a sentence about the SHOP while doing it.
     *
     * That second half is a safety regression the first wording caused, measured on 2026-09-03 by
     * re-running the eval suite against the committed branch. `no_match_not_absence` had been green
     * on `df2354d` and came back at 2/3 on the one assertion this project exists for:
     *
     * ```
     * ✗ no_absence_claim_in_prose  2/3
     *     run 1: prose claimed the shop does not sell something: "The shop does not carry"
     * ```
     *
     * The note opened with *"This shop does not record %s …"*; the reply came back *"The shop does
     * not carry …"* — same stem, different verb. Handing the model a licensed sentence beginning
     * "This shop does not" while the paragraph above forbids "we don't carry" asks it to tell the
     * two apart by the verb, and it did not. So the subject moved off the shop entirely: "No product
     * below records Colour" is the same fact and cannot be re-pointed at the catalogue.
     */
    public function testTheNoteSaysTheClaimMayBeMade(): void
    {
        $result = $this->tool()(term: 'tyre', options: [['Colour', 'Purple']]);

        $note = (string) ($result['note'] ?? '');
        self::assertStringContainsString('Colour', $note);
        self::assertStringContainsString('No product below records', $note);

        // And never as a sentence about the shop — see the docblock above for the safety regression
        // the first wording caused.
        self::assertStringNotContainsString('This shop does not record Colour', $note);
        self::assertStringContainsString('Never make it a sentence about the shop', $note);
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
