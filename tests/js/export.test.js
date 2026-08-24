import assert from 'node:assert/strict';
import { test } from 'node:test';

import {
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
