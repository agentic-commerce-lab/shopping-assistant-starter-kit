<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Controller;

use Swag\AssistantStarterKit\Core\Trace\Retention\TraceRetentionSettings;

/**
 * The structural half of what can be checked about the dashboard without a browser: that the page
 * is reachable, that it renders findings and charts, that a finding whose conversation is gone
 * renders without a dead link, that every stored run is reachable through the picker, and that the
 * technical section is genuinely its own section.
 *
 * The wording — snippet keys and labels — is {@see InsightsDashboardLabelsTest}. Neither class can
 * tell you how the page LOOKS; see {@see InsightsModuleTestCase} for why, and for why that is
 * stated instead of glossed over.
 */
final class InsightsDashboardAssetsTest extends InsightsModuleTestCase
{
    /**
     * Every reason the findings list can be empty, and no two of them the same sentence.
     *
     * The plan named two — "the feature is off" and "last night had nothing to report" — and the
     * clock and retention added the others. `noRuns` is a shop whose nightly task has not fired
     * yet; `pruned` is the normal end state of every old run, whose quotes were deleted with the
     * conversations they came from while the counts stayed (D23). Folding any pair of these
     * together tells a merchant something false about their shop, and the worst of the four is
     * `pruned` collapsing into `nothingFound`: it reports a quiet night on evidence that was
     * deleted.
     *
     * One test over the whole set rather than one per pair, because the set is what matters and
     * because mago rejects this class outright past a handful of methods.
     */
    public function testEveryEmptyStateIsItsOwnSentence(): void
    {
        $en = self::snippets('en-GB');

        $sentences = [];

        foreach (['disabled', 'noRuns', 'nothingFound', 'pruned'] as $state) {
            $sentence = self::leaf($en, 'swag-assistant-insights.empty.' . $state);

            self::assertNotSame('', $sentence, sprintf('empty state "%s" has no sentence', $state));

            $sentences[$state] = $sentence;
        }

        self::assertSame(
            array_keys($sentences),
            array_keys(array_unique($sentences)),
            'two empty states share a sentence, so a merchant cannot tell them apart',
        );
    }

    public function testThePickerOffersEveryStoredRunByItsDate(): void
    {
        // The page used to ask only for the newest run's findings, so every night made the
        // previous night's prose unreachable while its counts stayed on the charts.
        //
        // `runLabel`, never the id and never the full window: "what happened on the 3rd" is the
        // question and 32 characters of hex answer nothing, while the whole range overflowed
        // `sw-single-select` in a browser. Pinned here so a rename cannot quietly put either back.
        $page = self::read(self::PAGE . '/index.js');

        self::assertStringContainsString('runOptions', self::twig());
        // `@update:value`, pinned: `sw-single-select` emits no `change` event, so binding the
        // handler to `@change` made the picker a control that opened, ticked an option and did
        // nothing. It shipped that way until someone clicked it.
        self::assertStringContainsString('@update:value="onRunChange"', self::twig());
        self::assertMatchesRegularExpression('/label:\s*this\.runLabel\(run\)/', $page);
        self::assertStringContainsString("Criteria.equals('runId', this.selectedRun.id)", $page);
    }

    public function testTheRetentionFallbackMatchesTheValueTheShopActuallyPrunesOn(): void
    {
        // `runs.js` mirrors this number to decide "retention deleted the quotes" from "the judge
        // found nothing". The config API returns nothing for a value never written, so reading the
        // absent key as 0 would put the cutoff at today and report last night's findings as pruned.
        // Asserted against the real constant, because a drift here is silent and wrong in the
        // direction that hides evidence.
        $runs = self::read(self::PAGE . '/runs.js');

        self::assertStringContainsString(
            'RETENTION_FALLBACK_DAYS = ' . TraceRetentionSettings::DEFAULT_DAYS . ';',
            $runs,
        );
        self::assertStringContainsString("state === 'pruned'", self::twig());
    }

    public function testThePageRendersTheFindingsAndTheCharts(): void
    {
        $twig = self::twig();

        self::assertStringContainsString('sw-chart', $twig);
        self::assertStringContainsString('finding', $twig);
    }

    public function testAFindingWithoutAConversationRendersWithoutALink(): void
    {
        // A pruned conversation leaves its finding behind with a null `conversationId` (see
        // `InsightFindingDefinition`), and `router-link` would happily render an anchor to
        // `.../detail/undefined`. A dead link is worse than no link: it reads as "the evidence is
        // one click away" and lands on a 404.
        $twig = self::twig();

        self::assertMatchesRegularExpression('/v-if="finding\.conversationId"/', $twig);
        self::assertStringContainsString('v-else', $twig);
        self::assertStringContainsString('conversationGone', $twig);
    }

    public function testTheTechnicalHalfIsItsOwnSection(): void
    {
        // Spec 5.5: separated by a heading, because it is for whoever installed the plugin rather
        // than for whoever sells the products. Asserted here because "visibly separated" is the
        // half of the requirement a refactor drops first.
        $twig = self::twig();

        self::assertStringContainsString('technical.title', $twig);
        self::assertStringContainsString('metrics.escalationsWithoutDestination', $twig);
    }

    public function testTheModuleRegistersTheDashboardBehindTheInsightRight(): void
    {
        // Task 8 left `index.js` importing only the ACL, with a comment saying the route arrives
        // with this page. A page nothing routes to is a page that does not exist.
        $module = self::read(self::MODULE . '/index.js');

        self::assertStringContainsString("Shopware.Module.register('swag-assistant-insights'", $module);
        self::assertStringContainsString('swag-assistant-insights-dashboard', $module);
        self::assertStringContainsString("privilege: 'swag_assistant_insight.viewer'", $module);
    }

    public function testTheChartsUseTheAdministrationsOwnChartComponent(): void
    {
        // `sw-chart` ships with the Administration on ApexCharts 4.4.0 (verified in the installed
        // 6.7 tree). A plugin that bundled its own charting library would ship a second copy of one
        // the shop already loads, so this asserts the absence as well as the presence.
        self::assertStringContainsString('<sw-chart', self::twig());
        self::assertStringNotContainsString('apexcharts', self::read(self::PAGE . '/trends.js'));
    }
}
