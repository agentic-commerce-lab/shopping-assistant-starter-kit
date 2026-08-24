import assert from 'node:assert/strict';
import { test } from 'node:test';

import { promptText } from '../../src/Resources/app/administration/src/module/swag-assistant-trace/page/swag-assistant-trace-detail/payload.js';

/*
 * The prompt is the one payload a merchant reads as prose rather than as data. JSON.stringify turns
 * its newlines into two-character escapes on a single 4 KB line, which is visible and unreadable at
 * the same time.
 */
test('the prompt payload yields its text as prose', () => {
    assert.equal(promptText({ text: 'Line one\nLine two', sha256: 'abc', length: 17 }), 'Line one\nLine two');
});

test('a payload without prompt text yields null so the caller can fall back', () => {
    assert.equal(promptText({ hits: 3 }), null);
    assert.equal(promptText({}), null);
    assert.equal(promptText(null), null);
    assert.equal(promptText(undefined), null);
});

test('a non-string text is refused rather than rendered', () => {
    // Defensive for the same reason readTurns() is: this is a JSON column, and a row written by an
    // older plugin version must render thinly rather than throw on a detail page.
    assert.equal(promptText({ text: 42 }), null);
    assert.equal(promptText({ text: '' }), null);
});
