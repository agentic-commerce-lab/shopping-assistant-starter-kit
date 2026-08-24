import assert from 'node:assert/strict';
import { test } from 'node:test';

import {
    buildTimeline,
    phaseOf,
    shopMs,
    splitTurns,
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

    assert.equal(shopMs(rows), 48);
    assert.equal(waitMs(rows), 8067);
    // Every millisecond is accounted for: work plus waiting equals the turn. This closes because
    // sub-threshold gaps are attributed to the phase before them; leaving them out lost 18ms.
    assert.equal(shopMs(rows) + waitMs(rows), 8133 - 18);
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
