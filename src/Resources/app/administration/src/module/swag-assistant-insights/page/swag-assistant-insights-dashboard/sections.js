/**
 * Which metric goes in which section of the page.
 *
 * Its own module because this is the part that keeps changing — the chart groups and the technical
 * rows have both been rewritten twice as the metrics underneath them were corrected — and because
 * it is a set of decisions rather than behaviour. The page component reads these and renders them;
 * the reasoning for each grouping lives here, next to the grouping it explains.
 */

/**
 * Three charts, and each one is a shape a merchant can act on.
 *
 * The grouping rule is unchanged: every chart holds series whose magnitudes are comparable,
 * because a line chart with 300 turns and 3 unsupported claims on one y-axis draws the
 * second series flat along the axis and hides it. So the funnel's three counts sit
 * together, the two search outcomes sit together, and the three "the turn went wrong"
 * counts share the third.
 *
 * **The description-coverage chart is gone rather than moved, and that is a judgement
 * call.** Measured over this shop's traces, an excerpt reaches the model almost only when a
 * search narrows to three or fewer survivors — 16 of 25 at one survivor, 0 of 10 at twelve
 * — so the pair mostly measures how broadly shoppers phrased their questions. That is worth
 * knowing once, as two numbers in the technical section; it is not worth a month-long trend
 * beside the cart funnel, where it invited a merchant to rewrite product text that would
 * still not be sent. Moving the chart down instead would have put a lone line chart under a
 * row of numbers — a third layout idiom in that section, for a metric whose shape nobody
 * reads.
 *
 * `turnsFoundNothing` and `turnsOverCap` are the renamed `searchesEmpty`/`searchesOverCap`
 * (see `SearchOutcomes`). Keeping the old keys here after the rename is exactly what made
 * this chart plot an empty series, so the names are the same ones `InsightMetrics::counts()`
 * writes and a test now reads that list rather than a copy of it.
 */
export const CHART_GROUPS = [
    { key: 'funnel', keys: ['conversations', 'cartAdded', 'checkoutOffered'] },
    { key: 'searches', keys: ['turnsFoundNothing', 'turnsOverCap'] },
    { key: 'problems', keys: ['unsupportedClaims', 'abortedTurns', 'escalations'] },
];

/**
 * The technical half: the counts that belong to whoever maintains the plugin.
 *
 * `escalationsWithoutDestination` is the one that is a misconfiguration rather than a
 * measurement — a shopper asked for a human and there was nowhere to send them — so it
 * carries an `alarming` flag and the template colours it. The others are facts about the
 * model and the shop.
 *
 * Grouped rather than one flat row: seven heterogeneous figures side by side is a wall, and
 * the previous five only grouped correctly because the column count happened to break in the
 * right place. The groups state the boundary instead of depending on it.
 *
 * **The two description counts moved here out of the merchant half**, where they were
 * presented as a measure of product data and are not one: they measure how often the
 * shortlist was narrow enough for `ShortlistDescriptions` to hand an excerpt over, which is
 * a fact about query breadth and about this plugin's own threshold. Shown to a merchant,
 * "110 of 135 turns without your descriptions" reads as an instruction to rewrite text that
 * will still not be sent. Shown to whoever installed the plugin, it says whether the
 * hand-over path is working at all — which is what this section is for.
 */
export function technicalGroups(metrics) {
    return [
        {
            key: 'turns',
            rows: [
                { key: 'abortedTurns', alarming: false },
                { key: 'escalations', alarming: false },
                {
                    key: 'escalationsWithoutDestination',
                    alarming: (metrics.escalationsWithoutDestination ?? 0) > 0,
                },
            ],
        },
        {
            key: 'descriptions',
            rows: [
                { key: 'turnsWithDescription', alarming: false },
                { key: 'turnsWithoutDescription', alarming: false },
            ],
        },
        {
            // The judge's own health, not the shop's. `judgeFindingsDiscarded` is NOT alarming
            // here: a few refused rows in a large batch are normal, and colouring it red every
            // night would train a reader to ignore the one night it matters. The findings block
            // carries the loud version, where an empty worklist would otherwise lie.
            key: 'judge',
            rows: [
                { key: 'judgeConversationsDropped', alarming: false },
                { key: 'judgeFindingsDiscarded', alarming: false },
            ],
        },
    ];
}
