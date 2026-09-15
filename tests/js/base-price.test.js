import assert from 'node:assert/strict';
import { test } from 'node:test';

import { formatBasePrice } from '../../src/Resources/app/storefront/src/assistant/render.js';

/*
 * The base price as a string, never as arithmetic.
 *
 * The server divides — see `BasePrice` — so every case here is about what the card WRITES, and the
 * one thing it must never do is compute. `tests/js/price-basis.test.js` names why a pure string
 * function is the exception to keeping DOM assembly out of unit tests: the interesting cases are a
 * missing field, a reference other than one, and a client that predates the field, none of which a
 * browser check would think to set up.
 */

const EN = { basePrice: '(%price% / %unit%)' };

test('writes the figure the storefront writes', () => {
    const card = { currency: 'EUR', basePrice: { price: 25.56, referenceUnit: 1, unit: 'Liter' } };

    // en-GB throughout, as `price-basis.test.js` does: a German locale puts a NON-BREAKING space
    // before the euro sign, and an expectation written with an ordinary one fails on a difference
    // no reader of the diff can see.
    assert.equal(formatBasePrice(card, 'en-GB', EN), '(\u20ac25.56 / Liter)');
});

test('a reference other than one is spelled out', () => {
    const card = { currency: 'EUR', basePrice: { price: 498, referenceUnit: 100, unit: 'Gramm' } };

    assert.equal(formatBasePrice(card, 'en-GB', EN), '(\u20ac498.00 / 100 Gramm)');
});

test('a product sold by the piece writes nothing', () => {
    assert.equal(formatBasePrice({ currency: 'EUR', basePrice: null }, 'en-GB', EN), '');
    assert.equal(formatBasePrice({ currency: 'EUR' }, 'en-GB', EN), '');
});

test('a card from before the field existed writes nothing', () => {
    assert.equal(formatBasePrice(undefined, 'en-GB', EN), '');
    assert.equal(formatBasePrice({}, 'en-GB', EN), '');
});

test('an incomplete base price writes nothing rather than half a figure', () => {
    const noUnit = { currency: 'EUR', basePrice: { price: 25.56, referenceUnit: 1, unit: '' } };
    const noPrice = { currency: 'EUR', basePrice: { referenceUnit: 1, unit: 'Liter' } };

    assert.equal(formatBasePrice(noUnit, 'en-GB', EN), '');
    assert.equal(formatBasePrice(noPrice, 'en-GB', EN), '');
});

test('falls back to a readable shape when the translation is missing', () => {
    const card = { currency: 'EUR', basePrice: { price: 20, referenceUnit: 1, unit: 'Liter' } };

    assert.equal(formatBasePrice(card, 'en-GB', {}), '(€20.00 / Liter)');
});
