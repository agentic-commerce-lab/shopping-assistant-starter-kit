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
