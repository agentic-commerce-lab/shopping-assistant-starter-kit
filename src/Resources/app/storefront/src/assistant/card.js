/*
 * A product card, built entirely from server-rendered fields.
 *
 * **Every figure here comes from the `cards[]` payload.** Nothing is read out of the model's prose —
 * that separation is the product's central claim, and this file is where a client would break it most
 * easily. If a value you want is not on the card, the answer is to render it on the server, not to
 * parse it out of a sentence.
 */
import { formatPriceBasis, formatSpecChips } from './render.js';

/** Below this, "in stock" is true but reassuring a shopper with a bare "In stock" overstates it. */
const STOCK_LOW_THRESHOLD = 5;

/**
 * `StockSource::Parent` — the card stands for a product family, not for a unit anyone can buy.
 *
 * The server also sends `variant` and `product`, and **both are directly buyable**: a variant's
 * figure is its own, and a product with no variants has nothing to resolve. Only this one value
 * means the shopper still has a choice to make.
 */
const STOCK_SOURCE_FAMILY = 'parent';

/** Beyond this, staggering the entrance stops reading as choreography and starts reading as lag. */
const MAX_STAGGER_STEPS = 6;

const STAGGER_STEP_MS = 45;

/**
 * One card is an answer; several are a shortlist.
 *
 * A single decisive result gets the full-width horizontal treatment, because that is exactly the
 * moment price and availability must be unmissable. Several become a horizontally scrollable row of
 * upright cards — inside its own container, so the panel itself never scrolls sideways.
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

    const specs = formatSpecChips(card);
    if (specs !== '') {
        info.appendChild(text('p', 'swag-assistant-card__specs', specs));
    }

    if (card.deliveryTime && translations.delivery) {
        info.appendChild(text(
            'p',
            'swag-assistant-card__delivery',
            translations.delivery.replace('%time%', card.deliveryTime),
        ));
    }

    // `stockSource` says whose stock figure this is. A client cannot infer it, and a shopper quoted
    // a product family's aggregate is the shopper whose order gets cancelled. Nothing else in this
    // product says this out loud.
    //
    // **Only `parent`**, which now means one thing: a family standing in for variants nobody has
    // picked from. Until 2026-08-21 it also meant "a product with no variants", so every simple
    // product in the catalogue carried this note — measured live, three cards in one reply saying
    // "Stock shown for the product, not this variant" about products that have no variants.
    if (card.stockSource === STOCK_SOURCE_FAMILY && translations.parentStock) {
        info.appendChild(text('p', 'swag-assistant-card__note', translations.parentStock));
    }

    info.appendChild(buildFacts(card, { locale, translations }));
    info.appendChild(buildActions(card, { addToCartEnabled, translations }));
    el.appendChild(info);

    return el;
}

/**
 * A designed absence, not a broken image.
 *
 * Every product in the demo catalogue has `imageUrl: null`, so this is the common path rather than the
 * edge case — which is why it is a soft brand wash with the icon kit's own glyph rather than a grey
 * box. It carries an accessible name because a decorative-looking rectangle that actually means "we
 * have no picture of this" is information.
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
 * Price and stock on one row, because they are one question. Reading the price and then hunting for
 * whether the thing can actually be bought is two lookups for what is a single thought.
 */
function buildFacts(card, { locale, translations }) {
    const facts = document.createElement('div');
    facts.className = 'swag-assistant-card__facts';

    const price = formatPriceBasis(card, locale, translations);
    if (price !== '') {
        facts.appendChild(text('p', 'swag-assistant-card__price', price));
    }

    // Said once, next to the figure it qualifies. The card never computes what the other tiers
    // cost — only the server may state a price, and it has stated the one that applies.
    if (card.hasVolumePricing && translations.volumePricing) {
        facts.appendChild(text('p', 'swag-assistant-card__price-note', translations.volumePricing));
    }

    facts.appendChild(buildStock(card, translations));

    return facts;
}

/**
 * Status is never colour alone: the dot is decorative and the label carries the meaning. The brand
 * palette has no red, so "out of stock" is muted grey rather than alarming — which is also the honest
 * register for it.
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

    // **No add button on a card that stands for a product family.**
    //
    // `stockSource: 'parent'` means the server could not tell which variant this is about — it is the
    // state the parent-stock note exists to disclose. Offering one-click purchase there would let a
    // shopper who asked for "black, size M" buy an unspecified variant, which is precisely the
    // expectation D4 exists to prevent. The assistant's own resolver refuses to guess a variant; the
    // interface holds the same line and sends them to the product page, where they choose it
    // themselves.
    //
    // Measured: a live turn for "black, size M" returned the parent at 79.90 with 35 in stock while
    // Black/M is 69.90 with 3.
    //
    // **What this must not do is refuse a product that has no variants.** It did, for as long as
    // `parent` meant both things: asking for a 750ml bottle returned three simple products, all in
    // stock, none of them addable — a broken widget rather than a careful one.
    if (card.stockSource === STOCK_SOURCE_FAMILY) {
        return actions;
    }

    actions.appendChild(buildAdd(card, translations));

    return actions;
}

/**
 * The glyph is a shopping bag and it becomes a check on success — but the *label* is what carries the
 * outcome, changing from "Add to cart" to "Added". Colour and iconography confirm; they never inform.
 */
function buildAdd(card, translations) {
    const add = document.createElement('button');
    add.className = 'swag-assistant-card__add';
    add.type = 'button';
    add.dataset.swagAssistantAdd = card.id;

    add.appendChild(icon('cart'));

    const label = document.createElement('span');
    label.className = 'swag-assistant-card__add-label';
    label.textContent = translations.add ?? '';
    add.appendChild(label);

    if (!card.inStock) {
        add.disabled = true;
        // A disabled control must say why it is disabled. Grey is not a reason.
        const reason = translations.outOfStockReason ?? '';
        add.title = reason;
        add.setAttribute('aria-label', `${translations.add ?? ''} — ${reason}`);
    }

    return add;
}

/**
 * Swaps the button into its succeeded state, and pulses the card once so the confirmation belongs to
 * the product rather than floating over the storefront.
 *
 * @param {HTMLButtonElement} button
 * @param {string} label
 */
export function markAdded(button, label) {
    const icons = button.querySelector('.swag-assistant-icon');
    const text = button.querySelector('.swag-assistant-card__add-label');

    button.classList.remove('is-adding');
    button.classList.add('is-added');

    if (icons) {
        icons.className = 'swag-assistant-icon swag-assistant-icon--check';
    }

    if (text) {
        text.textContent = label;
    }

    const card = button.closest('.swag-assistant-card');

    if (!card) {
        return;
    }

    // Removed after it plays, so a second add to the same card can pulse again.
    card.classList.add('is-added');
    card.addEventListener('animationend', () => card.classList.remove('is-added'), { once: true });
}

export function icon(name) {
    const el = document.createElement('span');
    el.className = `swag-assistant-icon swag-assistant-icon--${name}`;
    el.setAttribute('aria-hidden', 'true');

    return el;
}

function text(tag, className, value) {
    const el = document.createElement(tag);
    el.className = className;
    el.textContent = value ?? '';

    return el;
}
