/**
 * The one sentence worth reading per phase, pulled from the payloads the stages recorded.
 *
 * A merchant does not want `{"hits":7,"retainedIds":[...32 hex ids...]}`; they want "found 7,
 * kept 6". The raw payload stays one disclosure away for whoever needs the ids.
 */
export function phaseFacts(row) {
    const payload = (stage) => row.events.find((event) => event.stage === stage)?.payload ?? null;

    /**
     * The **last** event of a stage, for stages a turn can record more than once.
     *
     * `retrieve` is one since the category retry became a second pass: the first records the search
     * inside the shopper's category, the second the one that ran after it was given up. Reading the
     * first made the row say "found 0" about a turn that went on to find six.
     */
    const lastPayload = (stage) =>
        row.events.filter((event) => event.stage === stage).at(-1)?.payload ?? null;

    switch (row.key) {
        case 'prepare':
            return prepareFacts(payload('page.context'), payload('model'));
        case 'understand':
            return understandFacts(payload('understand'), payload('query.build'));
        case 'search':
            return searchFacts(
                lastPayload('retrieve'),
                payload('retrieve.narrow'),
                payload('blocklist.filter'),
                payload('retrieve.without_category'),
            );
        case 'answer':
            return answerFacts(payload('render'), payload('validate'));
        case 'finish':
            return finishFacts(payload('turn.end'), payload('turn.failed'), row);
        default:
            return [];
    }
}

function understandFacts(understand, query) {
    const facts = [];

    if (understand?.term) {
        facts.push({ label: 'searched for', value: understand.term });
    }

    if (understand?.selectionCount) {
        facts.push({ label: 'options given', value: String(understand.selectionCount) });
    }

    const dropped = query?.filtersDropped ?? [];

    if (dropped.length) {
        facts.push({ label: 'filters dropped', value: dropped.join(', '), alarming: true });
    }

    return facts;
}

/**
 * Which model answered, and whether the shopper was on a product or a category page.
 *
 * The page context is the row that explains an unusually fast turn — and, when the model still
 * called a tool anyway, the row that says the shortcut was available and went unused.
 *
 * The model's name is first because it is the row that reframes every other row beneath it: a
 * timeline full of dropped filters and invented products reads differently once you can see the
 * shop was pointed at a small model that week. Turns recorded before the stage existed have no
 * name and print none, rather than printing a guess.
 */
function prepareFacts(pageContext, model) {
    const facts = [];

    if (model?.name) {
        facts.push({ label: 'model', value: model.name });
    }

    if (!pageContext) {
        return facts;
    }

    if (pageContext.resolved) {
        facts.push({ label: 'viewing product', value: pageContext.resolved });
    } else if (pageContext.reported) {
        // Reported but not resolved means the blocklist or the catalogue scope refused it, which is
        // the trust model working and worth seeing rather than inferring from an absence.
        facts.push({ label: 'reported product not in scope', value: 'ignored' });
    }

    if (pageContext.category) {
        facts.push({ label: 'browsing category', value: pageContext.category });
    }

    return facts;
}

function searchFacts(retrieve, narrow, blocklist, withoutCategory) {
    const facts = [];

    if (typeof retrieve?.hits === 'number') {
        facts.push({ label: 'found', value: String(retrieve.hits) });
    }

    if (typeof narrow?.survivors === 'number') {
        facts.push({ label: 'kept', value: String(narrow.survivors) });
    }

    const removed = blocklist?.removedIds ?? [];

    if (removed.length) {
        facts.push({ label: 'blocked', value: String(removed.length) });
    }

    if (withoutCategory) {
        // No count: this row marks where the category was given up, and the `retrieve` that follows
        // it reports what the uncaged search found. It used to print `hits`, which the retry no
        // longer carries — it is recorded before the second pass runs — so the row read "0" on
        // every turn that had one.
        facts.push({ label: 'left the category', value: 'searched the whole shop' });
    }

    return facts;
}

function answerFacts(render, validate) {
    const facts = [];
    const rendered = render?.renderedIds ?? [];

    facts.push({ label: 'cards shown', value: String(rendered.length) });

    const invented = validate?.inventedProductIds ?? [];

    if (invented.length) {
        facts.push({ label: 'invented products removed', value: invented.join(', '), alarming: true });
    }

    if (validate?.droppedCount) {
        facts.push({ label: 'claims discarded', value: String(validate.droppedCount), alarming: true });
    }

    return facts;
}

function finishFacts(end, failed, row) {
    if (row.events.some((event) => event.stage === 'turn.tool_limit_exceeded')) {
        return [{ label: 'ended', value: 'tool budget exhausted', alarming: true }];
    }

    /*
     * Checked before `outcome`, because a failed turn has no `turn.end` at all — see
     * Core/Agent/FailedTurn.php for why one is not synthesised. This row is the entire reason that
     * stage exists: a live failure on 2026-09-02 left a merchant a timeline that stopped after
     * `tool.call` and said nothing.
     */
    if (failed) {
        return [{ label: 'failed', value: describeFailure(failed), alarming: true }];
    }

    return end?.outcome ? [{ label: 'outcome', value: end.outcome }] : [];
}

/**
 * `Symfony\AI\Agent\Exception\RuntimeException: upstream said no` is a class path with a sentence
 * attached. A merchant reading a timeline needs the short name and the sentence; the namespace is in
 * the raw payload, one disclosure away, for whoever is going to grep for it.
 */
function describeFailure(failed) {
    const name = String(failed.exception ?? 'error').split('\\').at(-1);
    const message = failed.message ? `: ${failed.message}` : '';

    return `${name}${message}`;
}

/** `8183` reads as an id; `8.2 s` reads as a duration. */
export function humanMs(ms) {
    if (!Number.isFinite(ms)) {
        return '—';
    }

    return ms < 1000 ? `${ms} ms` : `${(ms / 1000).toFixed(1)} s`;
}
