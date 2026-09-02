/*
 * Taking traces out of the Administration, shared by the list and the detail page.
 *
 * Extracted before the second caller rather than after it: two copies of a fetch-and-save block is
 * the duplication `composer run quality`'s jscpd step exists to catch, and the copy that drifts is
 * always the one nobody reads.
 *
 * The request is built by a pure function so it can be tested without a browser — the URL, the body
 * and the authorization header are the parts that can be wrong in a way nobody notices until a
 * merchant clicks the button.
 */

/**
 * Builds the authenticated export request.
 *
 * `window.open` would be simpler and is wrong: the route emits customer names and is ACL-protected,
 * so it cannot be fetched as a plain URL — a tab has no Authorization header.
 *
 * @param {{apiPath: string, authToken: {access: string}}} api Shopware.Context.api
 * @param {string[]} ids
 */
export function exportRequest(api, ids) {
    return {
        url: `${api.apiPath}/_action/swag-assistant/trace/export`,
        options: {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Authorization: `Bearer ${api.authToken.access}`,
            },
            body: JSON.stringify({ ids }),
        },
    };
}

/**
 * How many ids one `search-ids` request may ask for.
 *
 * **This is not a choice, it is `shopware.api.max_limit`** — the platform's own ceiling on a single
 * Admin API search, wired into `api.request_criteria_builder` in core's
 * `Framework/DependencyInjection/data-abstraction-layer.php`. Asking for more is refused outright:
 *
 *     {"errors":[{"status":"400","code":"FRAMEWORK__QUERY_LIMIT_EXCEEDED",
 *       "detail":"The limit must be lower than or equal to MAX_LIMIT(=500). Given: 1000"}]}
 *
 * Measured against a running 6.7 shop on 2026-09-02, and it is why "Export all" was broken for
 * every merchant regardless of how many conversations they had: the list page asked for its full
 * export bound in one request, the API refused it before reading a single row, and the rejected
 * promise was never caught — so the button did nothing, silently, always.
 *
 * A shop that *lowers* `max_limit` below this would break the same way. It cannot be discovered
 * from the client, so the honest half of the fix is not this constant: it is that the failure now
 * reaches the merchant as the export alert instead of the browser console.
 */
export const SEARCH_IDS_PAGE_LIMIT = 500;

/**
 * Collects up to `bound` conversation ids, one API page at a time.
 *
 * Paged rather than fetched at once because of {@see SEARCH_IDS_PAGE_LIMIT}, and pure — it takes
 * `searchPage(page, limit)` and returns ids — so the paging arithmetic is testable without an
 * Administration. The bug this replaces was arithmetic no test could see.
 *
 * De-duplicated, because paging a sorted set is only stable if the sort is total: two conversations
 * created in the same millisecond can land on both sides of a page boundary. The caller adds `id`
 * as a tiebreak, and this is the belt to that braces — a duplicate id here would be a conversation
 * appearing twice in the exported file.
 *
 * @param {(page: number, limit: number) => Promise<{data: string[], total?: number}>} searchPage
 * @param {number} bound the most ids to collect, i.e. the server's own export bound
 * @returns {Promise<string[]>}
 */
export async function collectExportIds(searchPage, bound, pageSize = SEARCH_IDS_PAGE_LIMIT) {
    const ids = [];
    const seen = new Set();

    for (let page = 1; ids.length < bound; page += 1) {
        const limit = Math.min(pageSize, bound - ids.length);
        // Sequential, not fanned out: what ends this loop is a short page, and firing every
        // request at once would mean guessing the page count from a total that can change between
        // the guess and the request. Two round trips at the shipped bound.
        const { data } = await searchPage(page, limit);

        if (!data?.length) {
            return ids;
        }

        data.forEach((id) => {
            if (!seen.has(id)) {
                seen.add(id);
                ids.push(id);
            }
        });

        // A short page is the last page. Checked on what the server returned rather than on a
        // `total` it also returned: the two can disagree while a shop is writing traces, and the
        // one that decides whether to ask again has to be the one we actually received.
        if (data.length < limit) {
            return ids;
        }
    }

    return ids;
}

/**
 * What the browser saves the file as. The server also sends a name; this is what the anchor uses.
 *
 * JSON is the only format. A CSV export shipped beside it briefly and was removed: it could carry a
 * summary row per conversation but never the events, so it promised traces and delivered metrics.
 */
export function exportFileName() {
    return 'assistant-traces.json';
}

/**
 * Hands the blob to the browser as a download.
 *
 * DOM glue with no branch in it, so it carries no test — everything worth asserting about an export
 * is asserted either in PHP or by {@see exportRequest}.
 */
export function saveBlob(blob, filename) {
    const url = window.URL.createObjectURL(blob);
    const anchor = document.createElement('a');

    anchor.href = url;
    anchor.download = filename;
    anchor.click();

    window.URL.revokeObjectURL(url);
}
