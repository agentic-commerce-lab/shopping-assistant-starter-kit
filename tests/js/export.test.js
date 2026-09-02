import assert from 'node:assert/strict';
import { test } from 'node:test';

import {
    SEARCH_IDS_PAGE_LIMIT,
    collectExportIds,
    exportFileName,
    exportRequest,
} from '../../src/Resources/app/administration/src/module/swag-assistant-trace/export.js';

/*
 * The two parts of an export the browser cannot tell you are wrong: a request that goes to the
 * wrong place, and one that goes there unauthenticated. Both fail as a 401 or a 404 long after the
 * merchant clicked, which is the worst place to find out.
 */
const api = { apiPath: 'http://shop.test/api', authToken: { access: 'tok-123' } };

test('the request goes to the export action', () => {
    assert.equal(
        exportRequest(api, ['a'.repeat(32)]).url,
        'http://shop.test/api/_action/swag-assistant/trace/export',
    );
});

test('the request carries the admin token, because the route is ACL-protected', () => {
    // Without this header the endpoint answers 401 and the merchant sees "export failed" with no
    // way to tell an expired session from a broken feature.
    const { options } = exportRequest(api, []);

    assert.equal(options.headers.Authorization, 'Bearer tok-123');
    assert.equal(options.method, 'POST');
});

test('the body carries exactly the ids and the format the server expects', () => {
    const { options } = exportRequest(api, ['a'.repeat(32), 'b'.repeat(32)]);

    assert.deepEqual(JSON.parse(options.body), { ids: ['a'.repeat(32), 'b'.repeat(32)] });
});

test('the saved file is a json trace', () => {
    // One format. The CSV that briefly sat beside it could only ever hold a summary row per
    // conversation, never the events — it promised traces and delivered metrics.
    assert.equal(exportFileName(), 'assistant-traces.json');
});

/*
 * "Export all" was broken on every shop, for every merchant, from the day it shipped: the list page
 * asked `search-ids` for its whole export bound in one request, and the Admin API refuses a limit
 * above `shopware.api.max_limit`:
 *
 *     400 FRAMEWORK__QUERY_LIMIT_EXCEEDED — "The limit must be lower than or equal to
 *     MAX_LIMIT(=500). Given: 1000"
 *
 * Verified against a running 6.7 shop on 2026-09-02. Nothing in the suite could see it, because
 * nothing tested the arithmetic that produced the limit. These are those tests.
 */

/** A server holding `count` ids, answering one page at a time and recording what it was asked. */
const shopWith = (count) => {
    const ids = Array.from({ length: count }, (_, index) => String(index).padStart(32, '0'));
    const asked = [];

    return {
        asked,
        searchPage: async (page, limit) => {
            asked.push({ page, limit });

            if (limit > SEARCH_IDS_PAGE_LIMIT) {
                // What the real endpoint does, so a regression fails here rather than in a browser.
                throw new Error(`limit must be lower than or equal to MAX_LIMIT(=${SEARCH_IDS_PAGE_LIMIT})`);
            }

            return { data: ids.slice((page - 1) * limit, (page - 1) * limit + limit), total: count };
        },
    };
};

test('no single request asks for more ids than the API will return', async () => {
    const shop = shopWith(1200);

    await collectExportIds(shop.searchPage, 1000);

    assert.ok(shop.asked.every(({ limit }) => limit <= SEARCH_IDS_PAGE_LIMIT), 'every page is within MAX_LIMIT');
});

test('the export bound is collected across pages rather than truncated to one', async () => {
    const shop = shopWith(1200);

    const ids = await collectExportIds(shop.searchPage, 1000);

    assert.equal(ids.length, 1000, 'the server bound, not the API page size');
    assert.deepEqual(shop.asked, [{ page: 1, limit: 500 }, { page: 2, limit: 500 }]);
});

test('a shop with fewer conversations than one page is one request', async () => {
    // The common case, and the one that was also broken: the old code sent limit 1000 whether the
    // shop held three conversations or three thousand.
    const shop = shopWith(3);

    assert.deepEqual((await collectExportIds(shop.searchPage, 1000)).length, 3);
    assert.deepEqual(shop.asked, [{ page: 1, limit: 500 }]);
});

test('nothing to export is no ids and no second request', async () => {
    const shop = shopWith(0);

    assert.deepEqual(await collectExportIds(shop.searchPage, 1000), []);
    assert.equal(shop.asked.length, 1);
});

test('an id returned on two pages is exported once', async () => {
    // Paging a set sorted by createdAt alone is not stable — traces written in the same millisecond
    // have no defined order — so the caller sorts by id as a tiebreak and this de-duplicates
    // anyway. A duplicate here is a conversation appearing twice in the merchant's file.
    const repeated = async (page, limit) => ({ data: page > 2 ? [] : ['a'.repeat(32), 'b'.repeat(32)].slice(0, limit) });

    assert.deepEqual(await collectExportIds(repeated, 10, 2), ['a'.repeat(32), 'b'.repeat(32)]);
});
