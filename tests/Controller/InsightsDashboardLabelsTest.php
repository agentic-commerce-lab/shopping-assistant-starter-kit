<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Controller;

use Swag\AssistantStarterKit\Core\Insights\InsightsAggregator;

/**
 * Every label the dashboard needs, in both languages.
 *
 * The two mistakes this catches are invisible in a browser until the wrong shop opens the page: a
 * key defined in one language and not the other, and a key the page references that neither file
 * defines. Both render as the raw dotted string — and the first renders correctly in whichever
 * language the developer happens to be running.
 *
 * The third is a label missing for a value the database can hold: a metric key, a finding type or a
 * severity, each of which would put `turnsWithoutDescription` in front of a merchant.
 *
 * See {@see InsightsModuleTestCase} for what these assertions cannot do, and for why the tests are
 * split across two classes.
 */
final class InsightsDashboardLabelsTest extends InsightsModuleTestCase
{
    public function testEveryLabelExistsInBothLanguages(): void
    {
        self::assertSame(self::keys(self::snippets('en-GB')), self::keys(self::snippets('de-DE')));
    }

    /**
     * Every key a run can actually store, read from the class that produces them.
     *
     * **This used to be a hand-copied list, and that was wrong in a way this project then paid
     * for.** The old docblock argued that copying kept the Administration honest, because a shared
     * constant could be renamed on both sides and still reach a merchant untranslated. The real
     * outcome was the opposite: `searchesEmpty` and `searchesOverCap` were renamed to
     * `turnsFoundNothing` and `turnsOverCap` in the metrics, the page went on asking for the old
     * keys and plotted an empty chart, and this test stayed green because it was checking labels
     * for keys nothing produced any more.
     *
     * `aggregate([])` over no traces is the cheapest honest source: it is the real producer, it
     * needs no fixture, and every count comes back zero — the values are irrelevant here, only the
     * key set is. A rename now fails this test until the snippets follow.
     */
    public function testEveryMetricTheRunStoresHasALabel(): void
    {
        $en = self::snippets('en-GB');

        foreach (array_keys(InsightsAggregator::aggregate([])->counts()) as $key) {
            self::assertNotSame(
                '',
                self::leaf($en, 'swag-assistant-insights.metric.' . $key),
                sprintf('metric "%s" would render as its own key', $key),
            );
        }
    }

    /**
     * The counts `InsightRunWriter` merges into the metrics JSON, which the test above cannot see.
     *
     * `aggregate([])->counts()` is the aggregator's key set, and the judge's own two counts are not
     * in it — they are added when the row is written, so a missing label for either would reach a
     * merchant as a raw `judgeFindingsDiscarded`. Read out of the writer's own array literal rather
     * than copied here, for the reason the test above records: a hand-copied list went stale and
     * stayed green through a rename.
     *
     * Honest limit: this reads the keys written INSIDE the `'metrics' => [...]` literal, so a count
     * merged some other way would still be missed.
     */
    public function testEveryCountTheWriterMergesInHasALabel(): void
    {
        $writer = self::read(__DIR__ . '/../../src/Core/Insights/InsightRunWriter.php');

        self::assertSame(1, preg_match("/'metrics' => \\[(.*?)\\],/s", $writer, $block));

        preg_match_all("/'([A-Za-z]+)' =>/", (string) ($block[1] ?? ''), $merged);

        $keys = $merged[1] ?? [];

        self::assertNotSame([], $keys, 'the writer merges no named counts; this regex is stale');

        $en = self::snippets('en-GB');
        $de = self::snippets('de-DE');
        $path = 'swag-assistant-insights.metric.';

        $missing = array_values(array_filter(
            $keys,
            static fn(string $key): bool => (
                self::leaf($en, $path . $key) === ''
                || self::leaf($de, $path . $key) === ''
            ),
        ));

        self::assertSame([], $missing, 'no label in both languages for: ' . implode(', ', $missing));
    }

    public function testEveryFindingTypeAndSeverityHasALabel(): void
    {
        // The closed sets from `JudgeFindingType` and the severity column. A judge response is
        // validated against them before anything is stored, so these are exactly the values a row
        // can hold — a missing label here is a raw `wrong_or_missed_answer` in front of a merchant.
        $en = self::snippets('en-GB');

        foreach (['injection_attempt', 'wrong_or_missed_answer', 'bad_tool_use', 'frustration'] as $type) {
            self::assertNotSame('', self::leaf($en, 'swag-assistant-insights.type.' . $type), $type);
        }

        foreach (['info', 'warning', 'critical'] as $severity) {
            self::assertNotSame('', self::leaf($en, 'swag-assistant-insights.severity.' . $severity), $severity);
        }
    }

    public function testEverySnippetKeyTheModuleReferencesIsDefined(): void
    {
        // The one assertion that catches a typo. It sees only keys written as single-quoted string
        // literals — a template literal such as `…metric.${serie.name}` is invisible to it, which
        // is exactly why the two tests above enumerate those closed sets by hand instead.
        $en = self::snippets('en-GB');

        foreach (self::referencedKeys() as $key) {
            self::assertNotSame('', self::leaf($en, $key), sprintf('"%s" is referenced but not defined', $key));
        }
    }

    public function testTheReferenceScanActuallyFoundSomething(): void
    {
        // Guards the test above against passing because its regex stopped matching: a scan that
        // finds nothing asserts nothing, and would go green through a file rename.
        self::assertGreaterThan(10, count(self::referencedKeys()));
    }

    /**
     * Every `swag-assistant-insights.*` key the module writes as a string literal.
     *
     * @return list<string>
     */
    private static function referencedKeys(): array
    {
        $sources = glob(self::PAGE . '/*.{js,twig}', \GLOB_BRACE);

        self::assertIsArray($sources, 'the dashboard page directory must exist');

        $found = [];

        foreach ([...$sources, self::MODULE . '/index.js'] as $source) {
            $matches = [];
            preg_match_all("/'(swag-assistant-insights\\.[A-Za-z0-9_.]+)'/", self::read($source), $matches);

            // `?? []` although a pattern with one capture group always fills `$matches[1]`: the
            // analyzer cannot prove that from `preg_match_all`'s signature, and a docblock claiming
            // the shape would be asserting something about a builtin rather than about this code.
            $found = [...$found, ...($matches[1] ?? [])];
        }

        $unique = array_values(array_unique($found));
        sort($unique);

        return $unique;
    }
}
