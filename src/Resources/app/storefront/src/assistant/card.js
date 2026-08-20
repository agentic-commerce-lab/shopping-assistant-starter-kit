/*
 * A product card, built entirely from server-rendered fields.
 *
 * **Every figure here comes from the `cards[]` payload.** Nothing is read out of the model's prose —
 * that separation is the product's central claim, and this file is where a client would break it
 * most easily. If a value you want is not on the card, the answer is to render it on the server, not
 * to parse it out of a sentence.
 */
import { formatPrice } from './render';

/** Below this, "in stock" is true but reassuring a shopper with a bare "In stock" overstates it. */
const STOCK_LOW_THRESHOLD = 5;

/** Beyond this, staggering the entrance stops reading as choreography and starts reading as lag. */
const MAX_STAGGER_STEPS = 6;

const STAGGER_STEP_MS = 40;

/**
 * One card is an answer; several are a shortlist.
 *
 * A single decisive result gets the full-width hero treatment, because that is exactly the moment
 * price and availability must be unmissable. Several become a horizontally scrollable row — inside
 * its own container, so the panel itself never scrolls sideways.
 */
export function renderCards(container, cards, options) {
    if (!Array.isArray(cards) || cards.length === 0) {
        return null;
    }

    const wrapper = document.createElement('div');
    wrapper.className = cards.length === 1
        ? 'swag-assistant-cards swag-assistant-cards--hero'
        : 'swag-assistant-cards swag-assistant-cards--row';

    cards.forEach((card, index) => {
        const el = buildCard(card, options);
        const step = Math.min(index, MAX_STAGGER_STEPS);
        el.style.setProperty('--swag-assistant-card-delay', `${step * STAGGER_STEP_MS}ms`);
        wrapper.appendChild(el);
    });

    container.appendChild(wrapper);

    return wrapper;
}

function buildCard(card, { locale, addToCartEnabled, translations }) {
    const el = document.createElement('article');
    el.className = 'swag-assistant-card';
    el.dataset.productId = card.id;

    el.appendChild(buildMedia(card, translations));

    const info = document.createElement('div');
    info.className = 'swag-assistant-card__info';

    info.appendChild(text('h3', 'swag-assistant-card__name', card.name));

    const options = Object.values(card.options ?? {});
    if (options.length > 0) {
        info.appendChild(text('p', 'swag-assistant-card__options', options.join(' · ')));
    }

    const price = formatPrice(card.price, card.currency, locale);
    if (price !== '') {
        info.appendChild(text('p', 'swag-assistant-card__price', price));
    }

    info.appendChild(buildStock(card, translations));

    // `stockSource` says whether the stock figure belongs to the variant the shopper asked about or
    // to its parent. A client cannot infer it, and a shopper who is quoted the parent's number is
    // the shopper whose order gets cancelled. Nothing else in this product says this out loud.
    if (card.stockSource === 'parent' && translations.parentStock) {
        info.appendChild(text('p', 'swag-assistant-card__note', translations.parentStock));
    }

    if (card.deliveryTime && translations.delivery) {
        info.appendChild(text(
            'p',
            'swag-assistant-card__delivery',
            translations.delivery.replace('%time%', card.deliveryTime),
        ));
    }

    info.appendChild(buildActions(card, { addToCartEnabled, translations }));
    el.appendChild(info);

    return el;
}

/**
 * A designed absence, not a broken image.
 *
 * Every product in the demo catalogue has `imageUrl: null`, so this is the common path rather than
 * the edge case. It carries an accessible name because a decorative-looking box that is actually
 * "we have no picture of this" is information.
 */
function buildMedia(card, translations) {
    const media = document.createElement('div');
    media.className = 'swag-assistant-card__media';

    if (!card.imageUrl) {
        media.classList.add('swag-assistant-card__media--empty');
        media.setAttribute('role', 'img');
        media.setAttribute('aria-label', translations.noImage ?? '');

        return media;
    }

    const img = document.createElement('img');
    img.src = card.imageUrl;
    img.alt = card.name ?? '';
    img.loading = 'lazy';
    img.decoding = 'async';
    media.appendChild(img);

    return media;
}

/**
 * Status is never colour alone: the dot is decorative and the label carries the meaning. The brand
 * palette has no red, so "out of stock" is muted grey rather than alarming — which is also the
 * honest register for it.
 */
function buildStock(card, translations) {
    const stock = document.createElement('p');
    stock.className = 'swag-assistant-card__stock';

    let state = 'out';
    let label = translations.outOfStock ?? '';

    if (card.inStock) {
        const low = typeof card.stock === 'number' && card.stock <= STOCK_LOW_THRESHOLD;
        state = low ? 'low' : 'in';
        label = (low ? translations.lowStock : translations.inStock) ?? '';
    }

    stock.classList.add(`swag-assistant-card__stock--${state}`);

    const dot = document.createElement('span');
    dot.className = 'swag-assistant-card__dot';
    dot.setAttribute('aria-hidden', 'true');
    stock.appendChild(dot);
    stock.appendChild(document.createTextNode(label));

    return stock;
}

function buildActions(card, { addToCartEnabled, translations }) {
    const actions = document.createElement('div');
    actions.className = 'swag-assistant-card__actions';

    if (card.url) {
        const view = document.createElement('a');
        view.className = 'swag-assistant-card__view';
        view.href = card.url;
        view.textContent = translations.view ?? '';
        actions.appendChild(view);
    }

    if (!addToCartEnabled) {
        return actions;
    }

    const add = document.createElement('button');
    add.className = 'swag-assistant-card__add';
    add.type = 'button';
    add.dataset.swagAssistantAdd = card.id;
    add.textContent = translations.add ?? '';

    if (!card.inStock) {
        add.disabled = true;
        // A disabled control must say why it is disabled. Grey is not a reason.
        const reason = translations.outOfStockReason ?? '';
        add.title = reason;
        add.setAttribute('aria-label', `${translations.add ?? ''} — ${reason}`);
    }

    actions.appendChild(add);

    return actions;
}

function text(tag, className, value) {
    const el = document.createElement(tag);
    el.className = className;
    el.textContent = value ?? '';

    return el;
}
