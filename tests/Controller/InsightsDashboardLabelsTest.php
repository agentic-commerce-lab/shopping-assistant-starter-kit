<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Controller;

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
    /**
     * Every key `metrics` can hold, copied from `InsightMetrics::toArray()`.
     *
     * Copied rather than imported on purpose: the point is that the *Administration* knows about
     * all of them. Reading the list from the PHP class would let a key be added to both sides of a
     * shared constant and still reach a merchant untranslated.
     */
    private const METRIC_KEYS = [
        'conversations',
        'cartAdded',
        'checkoutOffered',
        'searchesEmpty',
        'searchesOverCap',
        'turnsWithDescription',
        'turnsWithoutDescription',
        'unsupportedClaims',
        'abortedTurns',
        'escalations',
        'escalationsWithoutDestination',
    ];

    public function testEveryLabelExistsInBothLanguages(): void
    {
        self::assertSame(self::keys(self::snippets('en-GB')), self::keys(self::snippets('de-DE')));
    }

    public function testEveryMetricTheRunStoresHasALabel(): void
    {
        $en = self::snippets('en-GB');

        foreach (self::METRIC_KEYS as $key) {
            self::assertNotSame(
                '',
                self::leaf($en, 'swag-assistant-insights.metric.' . $key),
                sprintf('metric "%s" would render as its own key', $key),
            );
        }
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

    /**
     * @param array<mixed> $tree
     *
     * @return list<string>
     */
    private static function keys(array $tree, string $prefix = ''): array
    {
        $keys = [];

        foreach ($tree as $key => $value) {
            $path = ltrim($prefix . '.' . $key, '.');
            $keys = [...$keys, ...(\is_array($value) ? self::keys($value, $path) : [$path])];
        }

        sort($keys);

        return $keys;
    }
}
