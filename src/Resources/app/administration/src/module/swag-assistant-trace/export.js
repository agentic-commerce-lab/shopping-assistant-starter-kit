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
