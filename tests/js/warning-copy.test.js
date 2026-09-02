import assert from 'node:assert/strict';
import { test } from 'node:test';

import { warningCopy } from '../../src/Resources/app/storefront/src/assistant/render.js';

/*
 * The correction's wording depends on whether there is anything to correct against.
 *
 * Reported on the staging shop, 2026-09-02: asked for the return policy, the assistant answered
 * correctly out of the shop's own document and the shopper was shown "The material and attribute
 * details on the card below are the ones that apply." — with no card below it. Two separate defects
 * met there: the claim was wrongly flagged at all (fixed by giving the property audit the retrieved
 * passages), and the copy promised a card the reply had not rendered.
 *
 * A shop-information answer and a "nothing found" answer both render no card, so this is not a rare
 * shape. Suppressing the note instead was the alternative and is worse: a genuine invention on a
 * cardless turn would then be shown to a shopper with no correction at all.
 *
 * `tests/e2e/README.md` keeps DOM assembly out of unit tests, which is why the copy DECISION is a
 * pure exported function and `buildWarning()` is the thin element wrapper around it.
 */

const TRANSLATIONS = {
    warningAvailability: 'availability WITH card',
    warningPrice: 'price WITH card',
    warningProperty: 'property WITH card',
    warningAvailabilityNoCard: 'availability NO card',
    warningPriceNoCard: 'price NO card',
    warningPropertyNoCard: 'property NO card',
};

test('a reply with no card gets copy that does not point at one', () => {
    const copy = warningCopy({ unbackedPropertyClaims: ['Rim'] }, TRANSLATIONS, false);

    assert.equal(copy, 'property NO card');
});

test('a reply with cards keeps the copy that points at them', () => {
    const copy = warningCopy({ unbackedPropertyClaims: ['Rim'] }, TRANSLATIONS, true);

    assert.equal(copy, 'property WITH card');
});

test('the availability-over-price-over-property ranking survives, in both shapes', () => {
    const all = { unbackedAvailabilityClaims: ['in stock'], unbackedPrices: ['9.99'], unbackedPropertyClaims: ['Rim'] };

    assert.equal(warningCopy(all, TRANSLATIONS, true), 'availability WITH card');
    assert.equal(warningCopy(all, TRANSLATIONS, false), 'availability NO card');

    const priced = { unbackedPrices: ['9.99'], unbackedPropertyClaims: ['Rim'] };

    assert.equal(warningCopy(priced, TRANSLATIONS, true), 'price WITH card');
    assert.equal(warningCopy(priced, TRANSLATIONS, false), 'price NO card');
});

test('no claims means no note, with or without cards', () => {
    assert.equal(warningCopy({}, TRANSLATIONS, false), null);
    assert.equal(warningCopy(undefined, TRANSLATIONS, true), null);
});

/* A shop that has not run the snippet update yet must not be shown the string "undefined". */
test('a missing snippet degrades to an empty note rather than to undefined', () => {
    assert.equal(warningCopy({ unbackedPropertyClaims: ['Rim'] }, {}, false), '');
});
