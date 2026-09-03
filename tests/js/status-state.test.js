import assert from 'node:assert/strict';
import { test } from 'node:test';

import { statusState } from '../../src/Resources/app/administration/src/component/swag-assistant-status-switch/state.js';

/*
 * The card used to have two states and one of them was a lie: `assistantEnabled` defaults to on, so
 * a shop with no model configured reported "Running" — green dot, "each reply spends model credit on
 * your account" — while /assistant/chat answered 503 and the storefront rendered no orb at all.
 * Measured 2026-09-03.
 */

test('an enabled shop with a model is running', () => {
    assert.equal(statusState(true, true), 'Running');
});

test('an enabled shop with no model anywhere is not answering yet', () => {
    assert.equal(statusState(true, false), 'Unconfigured');
});

test('an absent setting counts as enabled, matching the stored default', () => {
    assert.equal(statusState(undefined, false), 'Unconfigured');
});

test('a failed check reads as running rather than inventing a worse state', () => {
    // The endpoint behind it is a diagnostic. A diagnostic that cannot run must not report a state
    // the merchant did not configure — the shop-info screen's store status follows the same rule.
    assert.equal(statusState(true, null), 'Running');
});

test('stopped wins over unconfigured, because it is the more decisive thing the merchant said', () => {
    assert.equal(statusState(false, false), 'Stopped');
    assert.equal(statusState(false, true), 'Stopped');
});
