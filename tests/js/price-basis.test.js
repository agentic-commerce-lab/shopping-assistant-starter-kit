import assert from 'node:assert/strict';
import { test } from 'node:test';

import { formatPriceBasis } from '../../src/Resources/app/storefront/src/assistant/render.js';

/*
 * `tests/e2e/README.md` keeps DOM assembly out of unit tests, because such tests pass by restating
 * the code they check. This is the exception it names around: a pure string function whose
 * interesting cases — a case-of-24 product, a missing currency, a quantity the server did not send
 * — are exactly the ones a browser check would never think to set up.
 */

const translations = { priceAt: '%price% each at %count% units' };

test('a single-unit price is stated plainly', () => {
    const card = { price: 79.9, currency: 'EUR', priceQuantity: 1 };

    assert.equal(formatPriceBasis(card, 'en-GB', translations), '€79.90');
});

test('a price that assumes a minimum order says so', () => {
    // 70.00 is only obtainable at 24 units. Printing it bare is the same class of defect as
    // printing the single-unit price was.
    const card = { price: 70, currency: 'EUR', priceQuantity: 24 };

    assert.equal(formatPriceBasis(card, 'en-GB', translations), '€70.00 each at 24 units');
});

test('a card with no usable price renders nothing rather than a stray label', () => {
    assert.equal(formatPriceBasis({ price: null, currency: 'EUR', priceQuantity: 24 }, 'en-GB', translations), '');
    assert.equal(formatPriceBasis({ price: 70, currency: '', priceQuantity: 24 }, 'en-GB', translations), '');
});

test('a card from before this field existed is treated as a single unit', () => {
    // A transcript re-hydrated by an older cached bundle, or any client that omits the key.
    const card = { price: 79.9, currency: 'EUR' };

    assert.equal(formatPriceBasis(card, 'en-GB', translations), '€79.90');
});
