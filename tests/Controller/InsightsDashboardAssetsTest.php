<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Controller;

/**
 * The structural half of what can be checked about the dashboard without a browser: that the page
 * is reachable, that it renders findings and charts, that a finding whose conversation is gone
 * renders without a dead link, and that the technical section is genuinely its own section.
 *
 * The wording — snippet keys and labels — is {@see InsightsDashboardLabelsTest}. Neither class can
 * tell you how the page LOOKS; see {@see InsightsModuleTestCase} for why, and for why that is
 * stated instead of glossed over.
 */
final class InsightsDashboardAssetsTest extends InsightsModuleTestCase
{
    public function testTheTwoEmptyStatesAreDifferentSentences(): void
    {
        // "The feature is off" and "last night had nothing to report" mean opposite things and a
        // merchant must be able to tell them apart at a glance.
        $en = self::snippets('en-GB');

        $disabled = self::leaf($en, 'swag-assistant-insights.empty.disabled');
        $nothing = self::leaf($en, 'swag-assistant-insights.empty.nothingFound');

        self::assertNotSame('', $disabled);
        self::assertNotSame('', $nothing);
        self::assertNotSame($disabled, $nothing);
    }

    public function testTheThirdEmptyStateIsAlsoItsOwnSentence(): void
    {
        // A third state the plan did not name but the clock creates: switched on this afternoon,
        // with the nightly task yet to fire. Saying "nothing to report" then is a lie about the
        // shop, and saying "it is off" is a lie about the setting.
        $en = self::snippets('en-GB');

        $pending = self::leaf($en, 'swag-assistant-insights.empty.noRuns');

        self::assertNotSame('', $pending);
        self::assertNotSame(self::leaf($en, 'swag-assistant-insights.empty.disabled'), $pending);
        self::assertNotSame(self::leaf($en, 'swag-assistant-insights.empty.nothingFound'), $pending);
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
