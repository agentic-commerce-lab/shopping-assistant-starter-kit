import assert from 'node:assert/strict';
import { test } from 'node:test';

import { clockMs, phaseFacts } from '../../src/Resources/app/administration/src/module/swag-assistant-trace/page/swag-assistant-trace-detail/facts.js';
import {
    buildTimeline,
    phaseOf,
    shopMs,
    splitTurns,
    rawRows,
    share,
    spanMs,
    waitMs,
} from '../../src/Resources/app/administration/src/module/swag-assistant-trace/page/swag-assistant-trace-detail/phases.js';

/*
 * The same exception `markdown.test.js` names: this is pure derivation over data a real run
 * produced, and the interesting cases are ones a browser check would never think to construct —
 * a turn with no `turn.end`, a gap just under the threshold, a stage nobody mapped.
 *
 * The fixture below is a verbatim live turn ("what trail jerseys do you have?", 2026-08-21),
 * offsets included. It is what proved the shop spends 48ms and the model 8.07s.
 */
const ev = (elapsedMs, stage, payload = {}) => ({ elapsedMs, stage, payload });

const LIVE_TURN = [
    ev(18, 'facet.probe'),
    ev(18, 'vocabulary.render'),
    ev(32, 'guard.check'),
    ev(5162, 'tool.call'),
    ev(5171, 'understand'),
    ev(5172, 'query.build'),
    ev(5189, 'retrieve'),
    ev(5195, 'blocklist.filter'),
    ev(5195, 'retrieve.narrow'),
    ev(8132, 'validate'),
    ev(8132, 'grounding.select'),
    ev(8132, 'render'),
    ev(8133, 'turn.end'),
];

test('a live turn collapses into phases separated by the model round trips', () => {
    const rows = buildTimeline(LIVE_TURN);

    assert.deepEqual(
        rows.map((row) => (row.type === 'wait' ? 'wait' : row.key)),
        ['prepare', 'wait', 'understand', 'search', 'wait', 'answer', 'finish'],
    );
});

test('the shop is fast and the model is not, which is the whole point of the page', () => {
    const rows = buildTimeline(LIVE_TURN);

    // 66ms, not the 48ms this asserted until 2026-09-03: the 18ms before the first event is
    // recorded is shop work too — `facet.probe` is written after the probe returns — and the
    // timeline used to start at that first event and throw it away. See `openFirstSpan()`.
    assert.equal(shopMs(rows), 66);
    assert.equal(waitMs(rows), 8067);
    // Every millisecond is accounted for: work plus waiting equals the turn, and equals what
    // `TraceRecorder::turnElapsedMs()` reports to the list page. This closes because sub-threshold
    // gaps are attributed to the phase before them, and the lead-in to the phase that opens.
    assert.equal(shopMs(rows) + waitMs(rows), 8133);
});

test('a gap below the threshold is scheduling noise, not a round trip', () => {
    // 17ms separated `query.build` from `retrieve` on the live turn. Surfacing that as "waiting on
    // the model" would bury the two real gaps in noise.
    const rows = buildTimeline([ev(0, 'understand'), ev(17, 'retrieve'), ev(20, 'render')]);

    assert.equal(rows.filter((row) => row.type === 'wait').length, 0);
});

test('a turn ends at turn.end', () => {
    const turns = splitTurns([ev(10, 'understand'), ev(20, 'turn.end'), ev(5, 'understand'), ev(9, 'turn.end')]);

    assert.equal(turns.length, 2);
    assert.deepEqual(turns.map((turn) => turn.length), [2, 2]);
});

test('a turn that died without turn.end is still split, because elapsed restarts', () => {
    // `elapsedMs` is relative to turn start, so a drop marks a boundary even when the terminal
    // event is missing — which is exactly what an unhandled error leaves behind.
    const turns = splitTurns([ev(10, 'understand'), ev(900, 'retrieve'), ev(12, 'understand')]);

    assert.equal(turns.length, 2);
    assert.deepEqual(turns.map((turn) => turn.length), [2, 1]);
});

test('tool_limit_exceeded ends a turn, because it is how a turn dies in the field', () => {
    const turns = splitTurns([ev(10, 'tool.call'), ev(80, 'turn.tool_limit_exceeded'), ev(5, 'understand')]);

    assert.equal(turns.length, 2);
});

test('an unmapped stage groups as other rather than vanishing', () => {
    // A stage added to the pipeline and not to PHASES must still appear. Dropping it would make
    // the page quietly lie about what ran.
    assert.equal(phaseOf('something.new'), 'other');

    const rows = buildTimeline([ev(0, 'something.new')]);
    assert.equal(rows.length, 1);
    assert.equal(rows[0].key, 'other');
});

test('an empty trace produces no rows rather than throwing', () => {
    assert.deepEqual(buildTimeline([]), []);
    assert.deepEqual(splitTurns([]), []);
    assert.equal(shopMs([]), 0);
});

test('a mid-pipeline facet.probe does not cut a phase in half', () => {
    // Measured on a live turn: `facet.probe` warms a cache before the search and runs again inside
    // it. Treating the second one as the start of a "Prepared" phase split "Understood the
    // question" into two rows with a phantom phase wedged between them.
    const rows = buildTimeline([
        ev(0, 'tool.call'),
        ev(9, 'understand'),
        ev(9, 'facet.probe'),
        ev(10, 'query.build'),
        ev(27, 'retrieve'),
    ]);

    assert.deepEqual(rows.map((row) => row.key), ['understand', 'search']);
    assert.equal(rows[0].events.length, 4);
});

test('a quiet stage still forms a phase when nothing has started yet', () => {
    // The turn genuinely does open with facet.probe and vocabulary.render; those are a real
    // "Prepared" phase and must not vanish.
    const rows = buildTimeline([ev(0, 'facet.probe'), ev(1, 'vocabulary.render'), ev(14, 'guard.check')]);

    assert.deepEqual(rows.map((row) => row.key), ['prepare']);
    assert.equal(rows[0].events.length, 3);
});

test('page context is part of preparing the turn, not an unlabelled Other', () => {
    assert.equal(phaseOf('page.context'), 'prepare');
});

test('a disclosure never reads as Other, and never splits the phase it lands in', () => {
    // `options.disclosed` records what the shop told the model about a family. It fires at two
    // different points — before the prompt on a product page, and mid-search when a family was
    // truncated — so it is both mapped and quiet. Unmapped it would have opened an "Other" row on
    // every one of those turns, which is the wart this file's `prompt` gap already shows.
    assert.equal(phaseOf('options.disclosed'), 'prepare');

    const rows = buildTimeline([
        ev(0, 'retrieve'),
        ev(3, 'options.disclosed', { source: 'families', options: ['S', 'M'] }),
        ev(6, 'render'),
    ]);

    // Joins the search it landed in rather than cutting it in half with a second "Prepared".
    assert.deepEqual(rows.map((row) => row.key), ['search', 'answer']);
    assert.equal(rows[0].events.length, 2);
});

test('a disclosure that opens the turn forms the Prepared phase itself', () => {
    // The product-page path: it is the first event of the turn, before anything else has begun, so
    // the quiet rule has no group to join and the mapping is what keeps it out of "Other".
    const rows = buildTimeline([
        ev(0, 'options.disclosed', { source: 'viewing', options: ['S', 'M'] }),
        ev(2, 'page.context'),
        ev(9, 'guard.check'),
    ]);

    assert.deepEqual(rows.map((row) => row.key), ['prepare']);
    assert.equal(rows[0].events.length, 3);
});

test('the model is named on the timeline, inside the phase that opens the turn', () => {
    // It is the first event of every turn, so an unmapped `model` stage would open all of them
    // with a phase called "Other" — and the name is what tells a merchant reading a bad answer
    // whether the shop was pointed at a different model that week.
    assert.equal(phaseOf('model'), 'prepare');

    const rows = buildTimeline([
        ev(0, 'model', { name: 'gpt-4o-mini' }),
        ev(0, 'facet.probe'),
        ev(14, 'guard.check'),
    ]);

    assert.deepEqual(rows.map((row) => row.key), ['prepare']);
    assert.equal(phaseFacts(rows[0]).find((fact) => fact.label === 'model').value, 'gpt-4o-mini');
});

test('a turn recorded before the model stage existed prints no model rather than a guess', () => {
    const rows = buildTimeline([ev(0, 'facet.probe'), ev(14, 'guard.check')]);

    assert.equal(phaseFacts(rows[0]).find((fact) => fact.label === 'model'), undefined);
});

test('the category retry belongs to the search it retried', () => {
    // It must not open a phase of its own: it is the same search running a second time, and a
    // separate row would read as a second search the shopper caused.
    assert.equal(phaseOf('retrieve.without_category'), 'search');

    const rows = buildTimeline([
        ev(0, 'retrieve'),
        ev(4, 'retrieve.without_category'),
        ev(9, 'render'),
    ]);

    assert.deepEqual(rows.map((row) => row.key), ['search', 'answer']);
    assert.equal(rows[0].events.length, 2);
});

test('the search row reports the retrieval that actually answered', () => {
    // Two `retrieve` events since the category retry became a second pass: the first searched the
    // shopper's category and found nothing, the second searched the shop and found six. Reading the
    // first made the row say "found 0" about a turn that showed six products.
    const rows = buildTimeline([
        ev(0, 'retrieve', { hits: 0 }),
        ev(2, 'retrieve.without_category', { categoryId: 'c1' }),
        ev(4, 'retrieve', { hits: 6 }),
        ev(9, 'render', { renderedIds: [] }),
    ]);

    const search = rows.find((row) => row.key === 'search');

    assert.equal(phaseFacts(search).find((fact) => fact.label === 'found').value, '6');
});

test('leaving the category is reported without a count it does not have', () => {
    // The retry is recorded before the second pass runs, so it cannot know how many it found —
    // printing `hits` there produced a confident 0 on every turn that had one.
    const rows = buildTimeline([
        ev(0, 'retrieve', { hits: 0 }),
        ev(2, 'retrieve.without_category', { categoryId: 'c1' }),
        ev(4, 'retrieve', { hits: 6 }),
    ]);

    const fact = phaseFacts(rows[0]).find((entry) => entry.label === 'left the category');

    assert.ok(fact, 'the row must say the category was abandoned');
    assert.doesNotMatch(fact.value, /^\d+$/, 'it must not claim a number');
});

/*
 * `turn.failed` is the stage a turn that died inside the agent leaves behind — see
 * Core/Agent/FailedTurn.php. Until 2026-09-02 the catch recorded nothing at all, so this page had
 * nothing to show for the one turn a merchant most wants explained; now it must land in the phase
 * that ends a turn rather than in `other`, and must close the turn the way `turn.end` does.
 */
const FAILED_TURN = [
    ev(18, 'facet.probe'),
    ev(32, 'guard.check'),
    ev(4102, 'tool.call'),
    ev(9310, 'turn.failed', { exception: 'Symfony\\AI\\Agent\\Exception\\RuntimeException', message: 'upstream said no' }),
];

test('a failed turn is filed under finish rather than left unmapped', () => {
    assert.equal(phaseOf('turn.failed'), 'finish');
});

test('a failed turn closes the turn the way turn.end does', () => {
    const turns = splitTurns([...FAILED_TURN, ...LIVE_TURN]);

    assert.equal(turns.length, 2);
    assert.equal(turns[0].at(-1).stage, 'turn.failed');
    assert.equal(turns[1].at(-1).stage, 'turn.end');
});

test('the finish row of a failed turn names the exception rather than staying empty', () => {
    const rows = buildTimeline(FAILED_TURN);
    const finish = rows.find((row) => row.type === 'phase' && row.key === 'finish');

    const facts = phaseFacts(finish);

    assert.equal(facts.length, 1);
    assert.equal(facts[0].label, 'failed');
    assert.equal(facts[0].alarming, true);
    // The class, shortened: a merchant reading a timeline needs "RuntimeException", not the
    // namespace it lives in. The full payload is one disclosure away.
    assert.match(facts[0].value, /RuntimeException/);
    assert.match(facts[0].value, /upstream said no/);
    assert.doesNotMatch(facts[0].value, /Symfony/);
});

/*
 * ── Timing honesty ───────────────────────────────────────────────────────────────────────────────
 *
 * Reported from the Administration on 2026-09-03: a raw trace showed eight consecutive events each
 * marked `+4.4 s` on a turn the header said took 4.4 s, which reads as eight steps of 4.4 s each.
 * Reproduced against the local shop's own rows (conversation 01A0610ED52E…, 14.4 s): seven rows
 * read `+10.9 s` for stored offsets of 10851 and 10945, and the last four all read `+14.4 s` —
 * the turn total. Three separate defects, all confirmed on that data.
 */

/**
 * Verbatim from the local shop, conversation 01A0610E362B…: a turn that died in the agent, whose
 * first event is 358ms in because the facet probe missed the cache and is recorded after it
 * returns. That lead-in is the whole of defect three — the list said 496ms, the detail said 138ms.
 */
const SLOW_START_TURN = [
    ev(358, 'facet.probe'),
    ev(358, 'vocabulary.render'),
    ev(361, 'page.context'),
    ev(390, 'guard.check'),
    ev(390, 'prompt', { sha256: 'abc', length: 3000 }),
    ev(496, 'turn.failed', { exception: 'RuntimeException', message: 'no' }),
];

test('the prompt belongs to the phase that prepared it, not to Other', () => {
    // Recorded by AssistantRunner::buildMessageBag() as the last thing before the model call, and
    // in no phase list until now — so every single turn grew a phantom "Other" row and had its
    // "Prepared" phase cut in half around it.
    assert.equal(phaseOf('prompt'), 'prepare');

    const rows = buildTimeline(SLOW_START_TURN);

    assert.deepEqual(rows.map((row) => row.key), ['prepare', 'finish']);
});

test('a turn is measured from its start, not from its first event', () => {
    // `TraceRecorder::turnElapsedMs()` — what the conversation's `totalMs` column and the list
    // page report — is the last offset, measured from turn start. Starting the timeline at the
    // first event instead dropped everything before it: 496ms in the list, 138ms here, same turn.
    //
    // Nothing before the first event can be model latency (the model is called after `prompt`,
    // a prepare stage), so the lead-in is shop work and belongs to the phase that opens the turn.
    const rows = buildTimeline(SLOW_START_TURN);

    assert.equal(rows[0].startMs, 0);
    assert.equal(shopMs(rows) + waitMs(rows), 496);
});

test('the accounting still closes on a turn whose model round trips dominate', () => {
    const rows = buildTimeline(LIVE_TURN);

    assert.equal(shopMs(rows) + waitMs(rows), 8133);
    assert.equal(waitMs(rows), 8067);
});

test('the raw trace reports exact offsets, because rounding them is what made it lie', () => {
    // 10851 and 10945 both round to "+10.9 s". Seven rows of it, then four rows of the turn
    // total. The raw disclosure exists to answer "when exactly", so it may not round.
    const rows = rawRows(buildTimeline([
        ev(10826, 'tool.call'),
        ev(10851, 'facet.probe'),
        ev(10851, 'understand'),
        ev(10945, 'retrieve'),
    ]));

    assert.deepEqual(rows.map((row) => row.atMs), [10826, 10851, 10851, 10945]);
    assert.deepEqual(rows.map((row) => clockMs(row.atMs)), ['0:10.826', '0:10.851', '0:10.851', '0:10.945']);
});

test('the raw trace carries the gap to the previous event, so nobody subtracts by hand', () => {
    const rows = rawRows(buildTimeline(SLOW_START_TURN));

    assert.deepEqual(rows.map((row) => row.stage), [
        'facet.probe', 'vocabulary.render', 'page.context', 'guard.check', 'prompt', 'turn.failed',
    ]);
    // The first row has no predecessor: its offset already says where it sits, and inventing a
    // gap from turn start would be a second number saying the same thing.
    assert.deepEqual(rows.map((row) => row.deltaMs), [null, 0, 3, 29, 0, 106]);
});

test('the gap is measured across a phase boundary, not restarted at each one', () => {
    // The raw list is one flat sequence; a delta that reset per phase would report 0 for the first
    // event after a three-second model round trip, which is the largest gap on the page.
    const rows = rawRows(buildTimeline(LIVE_TURN));
    const toolCall = rows.find((row) => row.stage === 'tool.call');

    assert.equal(toolCall.deltaMs, 5162 - 32);
});

test('a turn written before elapsed_ms existed reports no offsets and no gaps', () => {
    // Absence, not a turn that ran in zero milliseconds. Decided for the whole turn rather than
    // per row: read per row, a stored 0 also swallowed the `model` stage, which is recorded at
    // construction and so reads 0 on nearly every real turn — it printed "—" and took the gap of
    // the row after it along with it.
    const rows = rawRows(buildTimeline([ev(0, 'facet.probe'), ev(0, 'guard.check'), ev(0, 'turn.end')]));

    assert.deepEqual(rows.map((row) => row.atMs), [null, null, null]);
    assert.deepEqual(rows.map((row) => row.deltaMs), [null, null, null]);
});

test('a turn that does have timing keeps the real zero its first event was recorded at', () => {
    const rows = rawRows(buildTimeline(SUMMED_TURN));

    assert.deepEqual(rows.map((row) => row.atMs), [0, 6, 29, 31, 8631, 8640, 8662]);
    assert.deepEqual(rows.map((row) => row.deltaMs), [null, 6, 23, 2, 8600, 9, 22]);
    assert.equal(clockMs(rows[0].atMs), '0:00.000');
});

test('an empty turn produces no raw rows rather than throwing', () => {
    assert.deepEqual(rawRows([]), []);
});

/*
 * ── One number per row ───────────────────────────────────────────────────────────────────────────
 *
 * Reported again on 2026-09-03, on a turn that took 8.7s:
 *
 *     +8.6 s  Built the answer  cards shown 0   31 ms
 *     +8.6 s  Finished          outcome no_result   0 ms
 *
 * "links steht 2x irgendwas von 8 sekunden obwohl die ganze anfrage 8 sekunden gedauert hat … das
 * suggeriert dass die anfrage 32 sekunden dauert". Both `+8.6 s` are the same instant — the answer
 * phase began and ended inside the same millisecond — but every row carried two time-shaped
 * numbers, the left one prefixed with `+`, and the left column repeats. Read down, it sums.
 *
 * Exact milliseconds did not fix that; they made the raw column worse, because six rows of
 * `8631 ms` still read as six amounts. A position and an amount cannot share a column shape.
 */

/** The reported turn, reconstructed from the offsets the merchant pasted. */
const SUMMED_TURN = [
    ev(0, 'model', { name: 'mistralai/mistral-large-2512' }),
    ev(6, 'acme.find_store.offered'),
    ev(29, 'page.context', { category: '01a01edcb74a70fe86a5533cf261f01b' }),
    ev(31, 'guard.check'),
    ev(8631, 'validate'),
    ev(8640, 'render', { renderedIds: [] }),
    ev(8662, 'turn.end', { outcome: 'no_result' }),
];

test("a stage this page has no phase for joins the phase that is running", () => {
    // `acme.find_store.offered` is an extension's stage — the docs' own example plugin records it
    // while the prompt is being built. Giving it a row of its own cut "Prepared" into two rows
    // with a nameless "Other" of 23ms wedged between them, on every turn that shop served. Any
    // plugin adding a stage did this, which makes it a defect in an extensible product rather
    // than a quirk of one extension.
    const rows = buildTimeline(SUMMED_TURN);

    assert.deepEqual(rows.map((row) => (row.type === 'wait' ? 'wait' : row.key)), [
        'prepare', 'wait', 'answer', 'finish',
    ]);
    // It must still be visible: the phase it joined lists it, and the raw trace prints it.
    assert.ok(rows[0].events.some((event) => event.stage === 'acme.find_store.offered'));
    assert.ok(rawRows(rows).some((row) => row.stage === 'acme.find_store.offered'));
});

test('an unmapped stage still opens a row of its own when no phase has begun', () => {
    // The turn genuinely started with it, so there is no phase for it to join and "Other" is the
    // honest name. Dropping it here would make the page silent about what ran first.
    assert.equal(phaseOf('something.new'), 'other');

    const rows = buildTimeline([ev(0, 'something.new'), ev(9, 'render')]);

    assert.deepEqual(rows.map((row) => row.key), ['other', 'answer']);
});

test('the reported turn accounts for its 8.7 seconds across four rows', () => {
    const rows = buildTimeline(SUMMED_TURN);

    assert.deepEqual(rows.map((row) => spanMs(row)), [31, 8600, 31, 0]);
    assert.equal(shopMs(rows) + waitMs(rows), 8662);
});

test('a row reports one length, whichever kind of row it is', () => {
    // The template used to compute `row.endMs - row.startMs` for a phase and read
    // `row.durationMs` for a wait, so the number in the column and the number the bar is drawn
    // from could drift apart. One function, both callers.
    const rows = buildTimeline(SUMMED_TURN);

    assert.equal(spanMs(rows[1]), rows[1].durationMs);
    assert.equal(spanMs(rows[2]), rows[2].endMs - rows[2].startMs);
    assert.equal(rows.reduce((total, row) => total + spanMs(row), 0), 8662);
});

test('an instant is formatted as a clock reading, because clock readings do not sum', () => {
    // The whole defect in one line: `8631 ms` and `+8.6 s` are amounts, and a column of amounts
    // invites addition. `0:08.631` is a position on a stopwatch and reads as one.
    assert.equal(clockMs(8631), '0:08.631');
    assert.equal(clockMs(358), '0:00.358');
    assert.equal(clockMs(0), '0:00.000');
    assert.equal(clockMs(74821), '1:14.821');
});

test('the six rows that read as amounts now read as one instant, six times', () => {
    const rows = rawRows(buildTimeline([
        ev(8631, 'validate'), ev(8631, 'grounding.select'), ev(8631, 'render'),
    ]));

    assert.deepEqual(rows.map((row) => clockMs(row.atMs)), ['0:08.631', '0:08.631', '0:08.631']);
    // And the gaps say what a reader was trying to work out by subtracting them.
    assert.deepEqual(rows.map((row) => row.deltaMs), [null, 0, 0]);
});

test("a row's share of the turn is what the bar is drawn from", () => {
    // The model round trip is 99.3% of this turn. That is the one fact the page exists to show,
    // and a bar shows it without a second number to misread.
    const rows = buildTimeline(SUMMED_TURN);
    const total = shopMs(rows) + waitMs(rows);

    assert.equal(share(rows[1], total), 8600 / 8662);
    assert.equal(share(rows[3], total), 0);
    // A turn with no measured time must not divide by zero.
    assert.equal(share(rows[0], 0), 0);
});
