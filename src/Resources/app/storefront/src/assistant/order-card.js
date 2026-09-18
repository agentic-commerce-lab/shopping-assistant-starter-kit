/*
 * The shopper's own orders, as cards.
 *
 * Its own file rather than a branch inside `card.js`. The two share a document row and nothing else:
 * a product card sells something — image, price, stock, add-to-cart — and an order card reports
 * something that already happened. Widening `card.js` to cover both would put two purposes behind
 * one set of conditionals, and the file is already the widget's largest.
 *
 * **Every figure here was rendered by the server.** `OrderPayload` builds this payload from the
 * `OrderSummary` list the turn actually retrieved, never from the model's prose — the same rule as
 * the product cards, applied where the figure is the shopper's own money. This file formats what it
 * is given and computes nothing.
 *
 * The invoice links are the server's too: built from `frontend.account.order.single.document`, a
 * route that is login-required and re-authenticates, so a link copied out of here is a link the
 * browser still has to earn.
 */
import { buildDocumentList } from './documents.js';

/**
 * @param {HTMLElement} container
 * @param {Array<Object>} orders
 * @param {{locale: string, translations: Object}} options
 */
export function renderOrders(container, orders, { locale, translations }) {
    if (!Array.isArray(orders) || orders.length === 0) {
        return;
    }

    const list = document.createElement('ul');
    list.className = 'swag-assistant-orders';

    orders.forEach((order) => {
        list.appendChild(buildOrderCard(order, { locale, translations }));
    });

    container.appendChild(list);
}

function buildOrderCard(order, { locale, translations }) {
    const item = document.createElement('li');
    item.className = 'swag-assistant-order';

    item.appendChild(buildHeader(order, { locale, translations }));

    const documents = buildDocumentList(order.documents, translations);

    if (documents !== null) {
        item.appendChild(documents);
    }

    return item;
}

function buildHeader(order, { locale, translations }) {
    const header = document.createElement('div');
    header.className = 'swag-assistant-order__header';

    const number = document.createElement('span');
    number.className = 'swag-assistant-order__number';
    // Text node, never innerHTML — the widget's standing safety rule. An order number is server
    // data, but the rule does not have exceptions for trustworthy fields.
    number.textContent = (translations.orderNumber ?? '#%number%').replace('%number%', order.orderNumber ?? '');
    header.appendChild(number);

    const meta = document.createElement('span');
    meta.className = 'swag-assistant-order__meta';
    meta.textContent = [formatDate(order.orderedAt, locale), order.state, formatTotal(order, locale)]
        .filter((part) => part !== '')
        .join(' · ');
    header.appendChild(meta);

    const items = document.createElement('span');
    items.className = 'swag-assistant-order__items';
    items.textContent = (translations.orderItems ?? '%count% items').replace('%count%', order.itemCount ?? 0);
    header.appendChild(items);

    return header;
}

/**
 * The server sends `Y-m-d` and the browser formats it for the shopper's locale.
 *
 * A date is the one figure here the client may reshape, because reshaping it changes nothing about
 * what it says. An invalid or missing value renders as nothing rather than as "Invalid Date".
 */
function formatDate(value, locale) {
    if (typeof value !== 'string' || value === '') {
        return '';
    }

    const parsed = new Date(`${value}T00:00:00`);

    if (Number.isNaN(parsed.getTime())) {
        return '';
    }

    return new Intl.DateTimeFormat(locale, { dateStyle: 'medium' }).format(parsed);
}

/** Formatting only. The number and its currency both came from the server. */
function formatTotal(order, locale) {
    if (typeof order.total !== 'number' || typeof order.currency !== 'string' || order.currency === '') {
        return '';
    }

    return new Intl.NumberFormat(locale, { style: 'currency', currency: order.currency }).format(order.total);
}
