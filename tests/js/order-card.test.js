import assert from 'node:assert/strict';
import { test } from 'node:test';
import { renderOrders } from '../../src/Resources/app/storefront/src/assistant/order-card.js';

/*
 * The order number links to the order in the account, from the server's URL and nowhere else.
 *
 * A minimal DOM stand-in: `order-card.js` only creates elements, sets properties and appends.
 */
globalThis.document = {
    createElement: (tag) => ({
        tag,
        children: [],
        appendChild(child) {
            this.children.push(child);
            return child;
        },
    }),
};

const TRANSLATIONS = { orderNumber: 'Order %number%' };

function numberOf(order) {
    const container = document.createElement('div');
    renderOrders(container, [order], { locale: 'en', translations: TRANSLATIONS });

    const card = container.children[0].children[0];
    return card.children[0].children[0];
}

test('the order number is a real link when the server sent a url', () => {
    const number = numberOf({ orderNumber: '10023', url: '/account/order/abc' });

    assert.equal(number.tag, 'a');
    assert.equal(number.href, '/account/order/abc');
    assert.equal(number.rel, 'noopener');
    assert.equal(number.textContent, 'Order 10023');
});

test('without a url the order number stays plain text', () => {
    for (const url of [undefined, null, '']) {
        const number = numberOf({ orderNumber: '10023', url });

        assert.equal(number.tag, 'span');
        assert.equal(number.href, undefined);
    }
});
