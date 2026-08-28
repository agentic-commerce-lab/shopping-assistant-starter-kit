import assert from 'node:assert/strict';
import { test } from 'node:test';

import { formatSpecChips } from '../../src/Resources/app/storefront/src/assistant/render.js';

/*
 * `tests/e2e/README.md` keeps DOM assembly out of unit tests. This is the pure string function this
 * exception is for — the same shape as `formatPriceBasis`'s own test file.
 */

test('renders up to two property values joined by a middle dot', () => {
    // Two different groups, one value each — not two values from the same group, which is the
    // exact shape the bug below guards against.
    const card = { properties: { Material: ['Merino'], Fit: ['Regular'] } };

    assert.equal(formatSpecChips(card), 'Merino · Regular');
});

test('takes only the first value per group, never several values from the same group', () => {
    // Shopware variant generation commonly leaves the full property list on a variant even though
    // the variant itself is only one specific value — a card must never render sibling values as
    // if they were its own.
    const card = { properties: { Material: ['Merino', 'Nylon', 'Cotton'], Fit: ['Regular', 'Slim'] } };

    assert.equal(formatSpecChips(card), 'Merino · Regular');
});

test('skips a property group already shown in card.options, taking the next group instead', () => {
    // A Trail Jersey variant in Blue/M can still carry the full property list (Colour and Size for
    // every sibling), even though it is itself only Blue/M. Both Colour and Size are already shown
    // on the options line, so re-showing them as spec chips is redundant and can show a sibling's
    // value under an options line that already states this unit's own — the exact contradiction
    // this fix closes. Only "Merino" (the one group not already in options) must render.
    const card = {
        options: { Colour: 'Blue', Size: 'M' },
        properties: { Colour: ['Blue', 'Black'], Size: ['M', 'L'], Material: ['Merino'] },
    };

    assert.equal(formatSpecChips(card), 'Merino');
});

test('matches an options group case-insensitively', () => {
    const card = {
        options: { colour: 'Blue' },
        properties: { Colour: ['Blue', 'Black'], Material: ['Merino'] },
    };

    assert.equal(formatSpecChips(card), 'Merino');
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
