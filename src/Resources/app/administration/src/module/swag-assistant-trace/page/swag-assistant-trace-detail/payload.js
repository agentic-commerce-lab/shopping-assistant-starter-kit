/**
 * The four fields ARCHITECTURE.md calls "the four ways this class of product lies". They render
 * inline on every row and are never behind an expander — a merchant must not have to go looking for
 * the evidence that the assistant dropped a filter or invented a product.
 *
 * Split into its own module rather than living on the component: `scripts/check_file_length.php`
 * walks `src`, and these two helpers are the part worth testing separately if a JS test harness is
 * ever added.
 */
const ALWAYS_VISIBLE = [
    'filtersDropped',
    'inventedProductIds',
    'modelClaimsDiscarded',
    'stockSource',
];

export function alwaysVisible(payload) {
    const source = payload || {};

    return ALWAYS_VISIBLE
        .filter((key) => source[key] !== undefined)
        .map((key) => ({ key, value: JSON.stringify(source[key]) }));
}

export function prettyPayload(payload) {
    return JSON.stringify(payload || {}, null, 2);
}

/**
 * The stored transcript, as turns a merchant can read.
 *
 * This is the only record of what was asked and answered: the trace events carry the pipeline's
 * decisions (`understand` records the parsed search term, `render` records rendered ids) and no
 * prose whatsoever. A trace view without this shows how the machine behaved and never what the
 * conversation was.
 *
 * Shapes defensively because `transcript` is a JSON column written by `TranscriptCodec` — a turn
 * from an older plugin version may be missing keys, and a detail page that throws on one bad row
 * is worse than one that renders it thinly.
 */
export function readTurns(transcript) {
    if (!Array.isArray(transcript)) {
        return [];
    }

    return transcript.map((turn, index) => ({
        index,
        role: turn?.role ?? 'unknown',
        prose: turn?.prose ?? '',
        cardIds: Array.isArray(turn?.cardIds) ? turn.cardIds : [],
        outcome: turn?.outcome ?? null,
        warnings: shapeWarnings(turn?.warnings),
        createdAt: turn?.createdAt ?? null,
    }));
}

/**
 * Warnings are the assistant admitting it nearly lied — an unbacked price or an availability claim
 * with no source. They render as a warning alert on the turn, never folded away.
 */
function shapeWarnings(warnings) {
    const source = warnings || {};
    const prices = Array.isArray(source.unbackedPrices) ? source.unbackedPrices : [];
    const claims = Array.isArray(source.unbackedAvailabilityClaims) ? source.unbackedAvailabilityClaims : [];

    return { prices, claims, any: prices.length > 0 || claims.length > 0 };
}
