<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Grounding;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Agent\AssistantAgentFactory;
use Swag\AssistantStarterKit\Core\Commerce\Dto\CatalogScope;
use Swag\AssistantStarterKit\Core\Commerce\FixtureCommerceGateway;
use Swag\AssistantStarterKit\Core\Grounding\DisclosedOptions;
use Swag\AssistantStarterKit\Core\Grounding\FactRenderer;
use Swag\AssistantStarterKit\Core\Grounding\VariantResolver;
use Swag\AssistantStarterKit\Core\Llm\LlmSettings;
use Swag\AssistantStarterKit\Core\Policy\AssistantConfig;
use Swag\AssistantStarterKit\Core\Policy\BlocklistFilter;
use Swag\AssistantStarterKit\Core\Retrieval\FacetProbe;
use Swag\AssistantStarterKit\Core\Retrieval\QueryBuilder;
use Swag\AssistantStarterKit\Core\Tool\SearchProductsTool;
use Swag\AssistantStarterKit\Core\Trace\TraceRecorder;
use Swag\AssistantStarterKit\Tests\Support\UsesCatalogFixture;
use Symfony\Component\HttpClient\MockHttpClient;

/**
 * The read side of the fourth exemption source, and — more importantly — proof that something
 * actually writes it.
 *
 * A reader with no recorder is the failure mode this project has already shipped once: `logTraces`
 * was a no-op in production for weeks because the pipe existed and nothing fed it. So the cases below
 * assert both ends. `PropertyClaimsMeasuredAgainstDisclosedTest` covers what the values then do to a
 * warning; this covers whether they arrive at all.
 */
final class DisclosedOptionsTest extends TestCase
{
    use UsesCatalogFixture;

    public function testTheValuesOfEveryDisclosureInTheRunAreReturned(): void
    {
        // Every disclosure, not the last: one recorder spans a conversation, and a size the shop
        // stated in turn one is not an invention when restated in turn two. Same rule
        // `GivenDescriptions` and `RetrievedPassages` apply, for the same reason.
        $trace = new TraceRecorder();
        $trace->record(DisclosedOptions::STAGE, ['source' => 'viewing', 'options' => ['S', 'M']]);
        $trace->record('retrieve', ['hits' => 3]);
        $trace->record(DisclosedOptions::STAGE, ['source' => 'families', 'options' => ['Blue']]);

        self::assertSame(['S', 'M', 'Blue'], DisclosedOptions::from($trace));
    }

    public function testTheSameValueDisclosedTwiceIsReturnedOnce(): void
    {
        // The two recorders overlap by design — a viewed product's family is often also the family a
        // search truncates — and a duplicate would only bloat the backed set it feeds.
        $trace = new TraceRecorder();
        $trace->record(DisclosedOptions::STAGE, ['options' => ['M']]);
        $trace->record(DisclosedOptions::STAGE, ['options' => ['M', 'L']]);

        self::assertSame(['M', 'L'], DisclosedOptions::from($trace));
    }

    public function testTwoFamiliesSharingAGroupKeepBothTheirValues(): void
    {
        // The trap `valuesOf()` exists for, and it was written the wrong way here once before being
        // caught: `array_merge()` on group-keyed maps OVERWRITES, so this would have disclosed only
        // `L` and `XL` — and the audit would then have flagged the model for naming `S` and `M`,
        // which is this class's own defect one level down.
        $values = DisclosedOptions::valuesOf([
            ['Size' => ['S', 'M'], 'Colour' => ['Blue']],
            ['Size' => ['L', 'XL']],
        ]);

        self::assertSame(['S', 'M', 'Blue', 'L', 'XL'], $values);
    }

    public function testATraceWithNoDisclosureYieldsNothing(): void
    {
        $trace = new TraceRecorder();
        $trace->record('retrieve', ['hits' => 0]);

        self::assertSame([], DisclosedOptions::from($trace));
    }

    public function testAMalformedPayloadContributesNothingRatherThanThrowing(): void
    {
        // A payload is JSON round-tripped through the database on the read side of a persisted trace,
        // so its shape cannot be assumed from the writer's types. Missing a disclosure costs one
        // unnecessary warning; throwing here costs the shopper their whole reply.
        $trace = new TraceRecorder();
        $trace->record(DisclosedOptions::STAGE, ['options' => 'Size: M']);
        $trace->record(DisclosedOptions::STAGE, ['options' => [null, 42, '', 'M']]);
        $trace->record(DisclosedOptions::STAGE, ['nothing' => 'useful']);

        self::assertSame(['M'], DisclosedOptions::from($trace));
    }

    /**
     * The recorder that matters most, because it fires on every search that withholds a variant.
     *
     * `Gravel Tyre` has four variants in the fixture and they are one family, so a limit of 2
     * truncates it and the `families` block discloses the option values of what was withheld — which
     * is the whole reason that block exists.
     */
    public function testSearchProductsRecordsTheOptionsItsFamiliesBlockDiscloses(): void
    {
        $trace = new TraceRecorder();
        $result = $this->searchTool($trace)(term: 'Gravel Tyre', limit: 2);

        self::assertArrayHasKey('families', $result, 'the fixture must still truncate this family');

        $disclosed = DisclosedOptions::from($trace);
        self::assertContains('Tan', $disclosed);
        self::assertContains('650x47', $disclosed);
    }

    public function testASearchThatWithholdsNothingRecordsNoDisclosure(): void
    {
        // No `families` key, no disclosure. An empty recording would put a stage in every trace a
        // merchant reads, saying nothing.
        $trace = new TraceRecorder();
        $result = $this->searchTool($trace)(term: 'bottle', limit: 8);

        self::assertArrayNotHasKey('families', $result);
        self::assertSame([], DisclosedOptions::from($trace));
    }

    /**
     * The second recorder. `ViewingContext` names the open product's whole family in the prompt, so
     * those values are disclosed before any tool runs — and a turn that answers from the prompt alone
     * (which is the round trip that feature exists to save) retrieves no card at all, so the
     * disclosure is the ONLY thing that can back the answer.
     */
    public function testTheViewingLinesFamilyClauseIsRecordedToo(): void
    {
        $gateway = FixtureCommerceGateway::fromFile(self::catalogFixturePath());
        $card = $gateway->product('fx-026-blue-l', new CatalogScope());
        self::assertNotNull($card);

        $bundle = AssistantAgentFactory::withCoreToolsOnly(self::http())->create(
            $gateway,
            new AssistantConfig(),
            cartAvailable: false,
            llm: new LlmSettings('https://example.invalid', 'test-key', 'gpt-x'),
            viewing: $card,
        );

        // Blue/L is open; Black and M belong to siblings the prompt now names.
        $disclosed = DisclosedOptions::from($bundle->trace);
        self::assertContains('Black', $disclosed);
        self::assertContains('M', $disclosed);
    }

    private static function http(): MockHttpClient
    {
        return new MockHttpClient(static function (): never {
            throw new \RuntimeException('The platform must not be called by this test.');
        });
    }

    private function searchTool(TraceRecorder $trace): SearchProductsTool
    {
        $gateway = FixtureCommerceGateway::fromFile(__DIR__ . '/../../Fixtures/catalog.json');

        return new SearchProductsTool(
            $gateway,
            new FacetProbe($gateway, $trace),
            new QueryBuilder(),
            new VariantResolver($gateway, $trace),
            new BlocklistFilter(),
            new FactRenderer($trace),
            $trace,
            new AssistantConfig(),
        );
    }
}
