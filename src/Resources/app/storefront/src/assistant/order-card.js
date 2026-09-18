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
 * One order with its lines — what `get_order` answered.
 *
 * Rendered as the same card as a summary, plus a line list. The two are one component on purpose: a
 * shopper who asked "what was in 10023" and then "show my orders" should not watch the same order
 * change shape between two replies.
 *
 * @param {HTMLElement} container
 * @param {Object|null} detail
 * @param {{locale: string, translations: Object}} options
 */
export function renderOrderDetail(container, detail, { locale, translations }) {
    if (!detail) {
        return;
    }

    const list = document.createElement('ul');
    list.className = 'swag-assistant-orders';

    const card = buildOrderCard(detail, { locale, translations });
    const lines = buildLines(detail.lines, detail.currency, { locale, translations });

    if (lines !== null) {
        // Before the documents, which `buildOrderCard` already appended: what was in the order reads
        // ahead of what can be downloaded about it.
        card.insertBefore(lines, card.querySelector('.swag-assistant-card__documents'));
    }

    list.appendChild(card);
    container.appendChild(list);
}

function buildLines(lines, currency, { locale, translations }) {
    if (!Array.isArray(lines) || lines.length === 0) {
        return null;
    }

    const list = document.createElement('ul');
    list.className = 'swag-assistant-order__lines';

    lines.forEach((line) => {
        const item = document.createElement('li');
        item.className = 'swag-assistant-order__line';

        const name = document.createElement('span');
        name.className = 'swag-assistant-order__line-name';
        // Text node, never innerHTML — the widget's standing rule, and a line label is merchant
        // content stored at order time.
        name.textContent = (translations.orderLine ?? '%count% × %name%')
            .replace('%count%', line.quantity ?? 1)
            .replace('%name%', line.name ?? '');
        item.appendChild(name);

        const price = document.createElement('span');
        price.className = 'swag-assistant-order__line-price';
        // The figure the model was never given. It rejoins the name here, from the server.
        price.textContent = formatMoney(line.lineTotal, currency, locale);
        item.appendChild(price);

        list.appendChild(item);
    });

    return list;
}

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

    // Only when the card is NOT going to itemise. A detail card carries `lines` and no `itemCount`,
    // so this read `?? 0` and printed "0 items" directly above two of them — a card contradicting
    // itself, which is the one thing every figure in this widget is rendered server-side to avoid.
    // Where the lines are shown they ARE the count, and a number above them is redundant as well as
    // wrong.
    if (typeof order.itemCount === 'number') {
        const items = document.createElement('span');
        items.className = 'swag-assistant-order__items';
        items.textContent = (translations.orderItems ?? '%count% items').replace('%count%', order.itemCount);
        header.appendChild(items);
    }

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
    return formatMoney(order.total, order.currency, locale);
}

/**
 * A line carries no currency of its own — the ORDER's is passed down.
 *
 * Formatting is the one thing the client may do to a figure, because it changes nothing about what
 * the figure says. An amount or a currency the server did not send renders as nothing rather than as
 * `NaN` or a bare number whose unit nobody can see.
 */
function formatMoney(amount, currency, locale) {
    if (typeof amount !== 'number' || typeof currency !== 'string' || currency === '') {
        return '';
    }

    return new Intl.NumberFormat(locale, { style: 'currency', currency }).format(amount);
}
