import assert from 'node:assert/strict';
import { test } from 'node:test';

import { formatSpecChips } from '../../src/Resources/app/storefront/src/assistant/render.js';

/*
 * `tests/e2e/README.md` keeps DOM assembly out of unit tests. This is the pure string function this
 * exception is for — the same shape as `formatPriceBasis`'s own test file.
 */

test('renders up to two property values joined by a middle dot', () => {
    const card = { properties: { Material: ['Merino', 'Nylon'], Fit: ['Regular'] } };

    assert.equal(formatSpecChips(card), 'Merino · Nylon');
});

test('a card with no properties renders nothing', () => {
    assert.equal(formatSpecChips({ properties: {} }), '');
    assert.equal(formatSpecChips({}), '');
});

test('a card from before this field existed renders nothing rather than throwing', () => {
    assert.equal(formatSpecChips({ properties: null }), '');
    assert.equal(formatSpecChips(undefined), '');
});

test('respects a custom chip limit', () => {
    const card = { properties: { Material: ['Merino', 'Nylon', 'Cotton'] } };

    assert.equal(formatSpecChips(card, 1), 'Merino');
});
