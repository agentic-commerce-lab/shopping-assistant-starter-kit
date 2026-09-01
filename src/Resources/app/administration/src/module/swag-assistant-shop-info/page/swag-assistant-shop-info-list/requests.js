/*
 * The three write requests, built by pure functions so they can be tested without a browser.
 *
 * Same reasoning as the trace module's `export.js`: the URL, the body and the authorization header
 * are the parts that can be wrong in a way nobody notices until a merchant clicks the button.
 *
 * `documentId` is posted in a form body rather than put in the path. The endpoints are POST-only —
 * including delete — because a GET or a DELETE with a routable id invites being tried from a browser
 * bar, and deleting a document silently removes passages a shopper can otherwise still retrieve.
 */

const ACTION = '_action/swag-assistant/shop-info';

function headers(api) {
    return { Authorization: `Bearer ${api.authToken.access}` };
}

/**
 * @param {{apiPath: string, authToken: {access: string}}} api Shopware.Context.api
 * @param {File} file
 * @param {string} salesChannelId
 */
export function uploadRequest(api, file, salesChannelId) {
    const body = new FormData();

    body.append('file', file);
    body.append('salesChannelId', salesChannelId);

    // No Content-Type: the browser sets the multipart boundary, and setting it by hand produces a
    // request PHP parses into an empty $_FILES with no error anywhere.
    return { url: `${api.apiPath}/${ACTION}/upload`, options: { method: 'POST', headers: headers(api), body } };
}

/**
 * Index the shop's own legal pages, and index everything again.
 *
 * Both are per sales channel, like everything else on this screen: documents and the embedding model
 * are scoped to one, and a bulk action that crossed channels would index a shop's German terms into
 * an English storefront.
 */
export function indexPagesRequest(api, salesChannelId) {
    return channelPost(api, 'index-pages', salesChannelId);
}

export function reindexAllRequest(api, salesChannelId) {
    return channelPost(api, 'reindex-all', salesChannelId);
}

function channelPost(api, action, salesChannelId) {
    const body = new FormData();

    body.append('salesChannelId', salesChannelId);

    return { url: `${api.apiPath}/${ACTION}/${action}`, options: { method: 'POST', headers: headers(api), body } };
}

/**
 * Which store answers, and whether the other still holds documents (spec D6).
 *
 * The only GET on this screen, and the only request not scoped to a sales channel: the database
 * engine and the installed package belong to the shop, not to a channel.
 */
export function storeStatusRequest(api) {
    return { url: `${api.apiPath}/${ACTION}/store-status`, options: { method: 'GET', headers: headers(api) } };
}

export function reindexRequest(api, documentId) {
    return formPost(api, 'reindex', documentId);
}

export function deleteRequest(api, documentId) {
    return formPost(api, 'delete', documentId);
}

function formPost(api, action, documentId) {
    const body = new FormData();

    body.append('documentId', documentId);

    return { url: `${api.apiPath}/${ACTION}/${action}`, options: { method: 'POST', headers: headers(api), body } };
}

/**
 * The first error detail the API returned, or null.
 *
 * Shopware's API error envelope is `{errors: [{detail}]}`, and the controller refuses with a reason a
 * merchant can act on — "this PDF is a scan and has no text layer". Dropping that in favour of a
 * generic message is what makes an upload failure unactionable.
 */
export function errorDetail(payload) {
    const first = payload && Array.isArray(payload.errors) ? payload.errors[0] : null;

    return first && typeof first.detail === 'string' ? first.detail : null;
}
