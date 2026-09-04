/**
 * Turns a flat list of trace events into something a merchant can read.
 *
 * The raw trace is ~18 rows per turn and treats bookkeeping and signal as equals: a live run of
 * "what trail jerseys do you have?" records `facet.probe`, `vocabulary.render` and `guard.check`
 * beside the two facts that matter — what was searched for, and what was shown. Read top to bottom
 * it answers no question a merchant actually has.
 *
 * Two things this derives that the row list cannot show:
 *
 * 1. **Phases.** Consecutive events belonging to the same part of the pipeline collapse into one
 *    line with one duration. Stage names stay available underneath; they stop being the structure.
 * 2. **The gaps.** Nothing is recorded while the model is thinking, so the model's latency is
 *    invisible in a list of events — it is the *space between* them. On that same live run the
 *    shop did 48ms of work and the two model round trips took 8.07s. Naming the gaps is the single
 *    most useful thing this page does, and it is only visible once events are grouped.
 */

const PHASES = [
    /*
     * `model` is the first event of every turn — AssistantAgentFactory records it before anything
     * can fail — and it is listed here rather than left to fall through to `other` for that reason:
     * an unlisted first stage would open every single turn with a phase called "Other".
     */
    /*
     * `prompt` is the last stage before the model is called — AssistantRunner::buildMessageBag()
     * records it as it hands the system message over — and it was in no list here at all, so every
     * single turn grew an "Other" row between "Prepared" and the model's first round trip, with
     * `prepare` cut in half around it. Measured on the local shop's own traces: the row was always
     * present and always 0ms, i.e. pure noise in the one place the page is supposed to be quiet.
     */
    { key: 'prepare', stages: ['model', 'options.disclosed', 'page.context', 'facet.probe', 'vocabulary.render', 'guard.check', 'prompt'] },
    { key: 'understand', stages: ['tool.call', 'understand', 'query.build', 'tool.arguments.rejected'] },
    /*
     * `retrieve.relaxTerm_without_options` joins its three siblings here rather than relying on
     * `joinsRunningPhase()` to absorb it. It would be absorbed correctly — `retrieve` always fires
     * first, so it can never be the event that opens the phase — but a list holding three of the
     * four relaxations and not the fourth is a trap for whoever adds the fifth.
     */
    { key: 'search', stages: ['retrieve', 'retrieve.narrow', 'retrieve.without_options', 'retrieve.without_category', 'retrieve.relaxTerm', 'retrieve.relaxTerm_without_options', 'variant.resolve', 'blocklist.filter'] },
    /*
     * The one phase where something changed.
     *
     * `cart.add` used to be a second `tool.call` and therefore landed in `understand` — so the only
     * action in this product that alters the shop's state was filed under working out the question.
     * A merchant scanning for "did it actually put something in a cart" had to read the payloads to
     * find out.
     */
    { key: 'act', stages: ['cart.add', 'checkout.offered'] },
    { key: 'answer', stages: ['validate', 'grounding.select', 'render', 'claims.audit'] },
    /*
     * `turn.failed` is how a turn that died inside the agent ends — see Core/Agent/FailedTurn.php.
     * It belongs here rather than in `other` for the same reason `cart.add` was moved out of
     * `understand`: the one turn a merchant opens this page to explain must not be filed under
     * bookkeeping. It is deliberately not a `turn.end`, so both names are listed everywhere one is.
     */
    { key: 'finish', stages: ['turn.end', 'turn.failed', 'turn.tool_limit_exceeded', 'escalate'] },
];

const TERMINAL = ['turn.end', 'turn.failed', 'turn.tool_limit_exceeded'];

/**
 * Stages that run wherever they are needed rather than at a point in the pipeline.
 *
 * `facet.probe` fires once to warm the cache and again inside the search; `vocabulary.render` is
 * prompt bookkeeping. Letting either start a phase cut "Understood the question" in half on a real
 * turn and invented a second "Prepared" between the pieces — a phase that never happened. They
 * join whatever phase is already running and only form their own when nothing else has begun.
 *
 * `options.disclosed` is the same shape and is why this list matters rather than being a curiosity:
 * it fires before the prompt when the shopper has a product open, and again in the middle of a
 * search that truncated a family. Mapped to `prepare` above so it never reads as "Other", and quiet
 * here so the search-path occurrence joins the search instead of splitting it in two.
 */
const QUIET = ['facet.probe', 'vocabulary.render', 'options.disclosed'];

/**
 * True for a stage that must not start a phase of its own while one is already running.
 *
 * {@link QUIET} names the three core stages that fire wherever they are needed. A stage no phase
 * claims is the same shape for a different reason: it has no place in the pipeline this page knows
 * about, so it cannot mark a transition between parts of it. `acme.find_store.offered` — the docs'
 * example extension, recorded while the prompt is built — cut "Prepared" into two rows with a
 * nameless "Other" of 23ms between them on every turn that shop served, and any plugin adding a
 * stage did the same. It still shows up: the phase it joins lists it, and the raw trace prints it.
 *
 * An unmapped stage that opens a turn has no phase to join and keeps its own row, where "Other" is
 * the honest name for it.
 */
function joinsRunningPhase(stage) {
    return QUIET.includes(stage) || phaseOf(stage) === 'other';
}

/**
 * Below this a gap is scheduling noise, not a round trip. Model calls run in seconds; the largest
 * within-phase gap measured on a real turn was 17ms.
 */
const WAIT_THRESHOLD_MS = 250;

export function phaseOf(stage) {
    return PHASES.find((phase) => phase.stages.includes(stage))?.key ?? 'other';
}

/**
 * Splits events into turns. `seq` is monotonic across a whole conversation, but `elapsedMs`
 * restarts at each turn — so a drop in elapsed marks a boundary even when no terminal stage is
 * present. That fallback still matters: a turn killed by a PHP error or a timeout leaves no stage at
 * all. A turn that died inside the agent now leaves `turn.failed` and is closed by name.
 */
export function splitTurns(events) {
    const turns = [];
    let current = [];

    events.forEach((event, index) => {
        const previous = events[index - 1];

        if (current.length && previous && event.elapsedMs < previous.elapsedMs) {
            turns.push(current);
            current = [];
        }

        current.push(event);

        if (TERMINAL.includes(event.stage)) {
            turns.push(current);
            current = [];
        }
    });

    if (current.length) {
        turns.push(current);
    }

    return turns;
}

/**
 * One turn's events as an ordered list of phase groups, with `wait` entries standing in for the
 * gaps between them.
 */
export function buildTimeline(events) {
    const rows = [];
    let group = null;

    events.forEach((event) => {
        const key = joinsRunningPhase(event.stage) && group ? group.key : phaseOf(event.stage);

        if (group && group.key === key) {
            group.events.push(event);
            group.endMs = event.elapsedMs;

            return;
        }

        if (group) {
            const gap = event.elapsedMs - group.endMs;

            if (gap >= WAIT_THRESHOLD_MS) {
                rows.push({ type: 'wait', startMs: group.endMs, durationMs: gap });
            }
        }

        group = { type: 'phase', key, startMs: event.elapsedMs, endMs: event.elapsedMs, events: [event] };
        rows.push(group);
    });

    return openFirstSpan(closeSpans(rows));
}

/**
 * Pulls the opening phase back to the turn's start, so the page measures the same turn the list does.
 *
 * `TraceRecorder`'s offsets are relative to its construction, and the first event is recorded some
 * way in: `facet.probe` is written *after* the probe returns, and a cache miss makes that 358ms on
 * a real turn. Starting the timeline at the first event silently discarded that time — the list
 * page, which reads `turnElapsedMs()` (the last offset, measured from the start), reported 496ms
 * for a turn this page called 138ms. Same turn, 3.6x apart, and the raw trace below agreed with
 * neither.
 *
 * The lead-in is attributed to the phase that opens the turn rather than shown as a gap, because it
 * cannot be anything but shop work: the model is not called until after `prompt`, which is a
 * `prepare` stage, so nothing before the first event is latency waiting on it.
 */
function openFirstSpan(rows) {
    if (rows[0]?.type === 'phase') {
        rows[0].startMs = 0;
    }

    return rows;
}

/**
 * Extends each phase to where the next row begins, so the milliseconds add up.
 *
 * A gap under {@link WAIT_THRESHOLD_MS} belongs to the phase before it — nothing else was running.
 * Leaving those unattributed made `shopMs() + waitMs()` fall 18ms short of the turn on the live
 * fixture, which is the kind of quiet arithmetic hole that makes a diagnostic page untrustworthy.
 * A wait row already starts at the previous phase's last event, so this is a no-op there.
 */
function closeSpans(rows) {
    rows.forEach((row, index) => {
        const next = rows[index + 1];

        if (row.type === 'phase' && next) {
            row.endMs = next.startMs;
        }
    });

    return rows;
}

/**
 * How long a row lasted, whichever kind of row it is.
 *
 * A phase carries a start and an end and a wait carries a length, and the template used to reach
 * for whichever the row happened to have. Once the bar and the number are both drawn from a row's
 * length they must agree, so both read it from here.
 */
export function spanMs(row) {
    return row.type === 'wait' ? row.durationMs : row.endMs - row.startMs;
}

/**
 * A row's length as a fraction of the turn, for the bar that replaced the offset column.
 *
 * The offset was the defect: every row carried a position *and* a length, both formatted as times,
 * and a column of positions reads as a column of lengths — a merchant reported a 8.7s turn as
 * "32 seconds" by adding the left column up. A bar states the position and the proportion without
 * a second number to misread, and it is the shape that makes this page's one finding obvious:
 * on the reported turn the model round trip is 99.3% of it.
 */
export function share(row, totalMs) {
    return totalMs > 0 ? spanMs(row) / totalMs : 0;
}

/** Milliseconds spent inside the shop, i.e. everything not sitting in a gap. */
export function shopMs(rows) {
    return rows.filter((row) => row.type === 'phase').reduce((total, row) => total + spanMs(row), 0);
}

/** Milliseconds spent waiting on the model. */
export function waitMs(rows) {
    return rows.filter((row) => row.type === 'wait').reduce((total, row) => total + spanMs(row), 0);
}

/**
 * The raw disclosure: every event of a turn in order, with its exact offset and the gap to the
 * event before it.
 *
 * Both numbers exist because of what the rounded offset did on its own. `humanMs` is a duration
 * formatter — one decimal place past a second, which is right for "this phase took 2.1 s" — and
 * the raw list reused it for offsets. On the local shop's 14.4s turn that printed seven consecutive
 * rows as `+10.9 s` (stored: 10851, 10851, 10851, 10945, 10945, 10945) and closed with four rows of
 * `+14.4 s`, the turn total. Read down the column it says eight steps of 14.4 s each, which is how
 * this was reported. Exact milliseconds cannot round two different instants together; the delta
 * turns "when" into "how long since", which is what anyone reading this column was subtracting by
 * hand anyway.
 *
 * The gap is measured across phase boundaries, not restarted at each one: the largest one on the
 * page is the model round trip, and it lands on the first event *after* a phase ends.
 *
 * **Whether a turn has timing at all is decided once, for the turn.** A stored `0` is ambiguous per
 * row — the turn's first millisecond, or a row written before `elapsed_ms` existed — and reading it
 * as absence swallowed the `model` stage, which `AssistantAgentFactory` records at construction and
 * which therefore reads 0 on nearly every turn. A turn where *nothing* carries an offset is the
 * un-migrated one; one where anything does has a real zero at its start.
 *
 * @return {Array<{event: object, stage: string, atMs: (number|null), deltaMs: (number|null)}>}
 */
export function rawRows(rows) {
    const events = rows.filter((row) => row.type === 'phase').flatMap((row) => row.events);
    const timed = events.some((event) => event.elapsedMs > 0);

    return events.map((event, index) => {
        const previous = events[index - 1];

        return {
            event,
            stage: event.stage,
            atMs: timed ? event.elapsedMs : null,
            deltaMs: timed && previous ? event.elapsedMs - previous.elapsedMs : null,
        };
    });
}
