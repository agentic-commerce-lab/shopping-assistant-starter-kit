import assert from 'node:assert/strict';
import { test } from 'node:test';
import { addButtonState } from '../../src/Resources/app/storefront/src/assistant/card.js';

/*
 * What the add button says when the cart already holds the product.
 *
 * ## The defect this exists to prevent coming back
 *
 * "Added" used to live only in `markAdded()`, a DOM mutation the click handler applied. Nothing
 * that rebuilt a card from the server knew about it — so the assistant's own confirmation card,
 * rendered right after its `add_to_cart` call, read "Add to cart", and the button underneath that
 * label was live: clicking it really did add a second one.
 *
 * ## Why a pure function rather than a DOM assertion
 *
 * The decision is "what does this button say", which is a question about the card's data, not about
 * the document. Keeping it separable is also what makes it testable in `node --test` at all —
 * everything else in `card.js` needs a DOM.
 *
 * `%count%` interpolation rather than a client-side plural rule: the snippet is the merchant's to
 * translate, exactly as `documentsMore` and `delivery` already are.
 */
const TRANSLATIONS = {
    add: 'In den Warenkorb',
    inCart: 'Im Warenkorb (%count%)',
    addAnother: 'Noch eins hinzufügen',
};

test('a card the cart does not hold offers the ordinary add', () => {
    const state = addButtonState({ inCart: 0 }, TRANSLATIONS);

    assert.equal(state.inCart, 0);
    assert.equal(state.label, 'In den Warenkorb');
});

test('a card the cart holds says so, with the count', () => {
    const state = addButtonState({ inCart: 2 }, TRANSLATIONS);

    assert.equal(state.inCart, 2);
    assert.equal(state.label, 'Im Warenkorb (2)');
});

/*
 * The visible label is the STATE; the accessible name is the ACTION.
 *
 * A button whose label reads "Im Warenkorb (2)" has stopped saying what pressing it does, and a
 * screen reader user gets only that label. `buildAdd()` already draws this distinction for the
 * out-of-stock case, where `aria-label` carries the action plus the reason it is refused.
 */
test('the action stays available to a screen reader when the label went stateful', () => {
    assert.equal(addButtonState({ inCart: 2 }, TRANSLATIONS).action, 'Noch eins hinzufügen');
    assert.equal(addButtonState({ inCart: 0 }, TRANSLATIONS).action, 'In den Warenkorb');
});

/*
 * An older server, a card resolved before `inCart` existed, or a payload that lost the field —
 * all three must read as "not in the cart" rather than as "in the cart, quantity unknown". The
 * failure direction matters: a missing badge is a smaller lie than a badge for something nobody
 * bought.
 */
test('a card with no inCart field at all falls back to the ordinary add', () => {
    for (const card of [{}, { inCart: null }, { inCart: undefined }, { inCart: 'two' }, { inCart: -1 }]) {
        const state = addButtonState(card, TRANSLATIONS);

        assert.equal(state.inCart, 0, `inCart: ${JSON.stringify(card.inCart)}`);
        assert.equal(state.label, 'In den Warenkorb');
    }
});
