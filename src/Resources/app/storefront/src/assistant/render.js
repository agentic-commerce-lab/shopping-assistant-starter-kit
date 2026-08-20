/*
 * Turns one message into DOM.
 *
 * The hierarchy is carried entirely by one contrast: **the shopper is contained, the assistant is
 * not.** A shopper's message is a right-aligned bubble; the assistant's is full-width text with no
 * container at all. That reads as the shop speaking rather than as a peer in a group chat, which is
 * why no avatar or accent rule is added on top of it.
 */
import { renderCards } from './card';

const ROLE_USER = 'user';

/**
 * Returns '' for an unknown time rather than substituting the current one.
 *
 * Found by re-hydrating a conversation held four minutes earlier: every restored message displayed
 * *now*, because the fallback was `new Date()`. A fabricated timestamp is worse than an absent one —
 * it is a figure the server never produced, presented as fact, in a product whose whole claim is
 * that it does not do that.
 */
export function formatTime(value, locale) {
    if (!value) {
        return '';
    }

    const date = new Date(value);

    if (Number.isNaN(date.getTime())) {
        return '';
    }

    return new Intl.DateTimeFormat(locale, { hour: '2-digit', minute: '2-digit' }).format(date);
}

/**
 * The server sends `74.9` and `"EUR"`, never a formatted string — formatting is locale-dependent and
 * therefore the client's job. The locale comes from the storefront request, not from the browser, so
 * a price in the panel is punctuated the way every other price on the page is.
 */
export function formatPrice(amount, currency, locale) {
    if (typeof amount !== 'number' || !currency) {
        return '';
    }

    return new Intl.NumberFormat(locale, { style: 'currency', currency }).format(amount);
}

/**
 * @param {HTMLElement} log
 * @param {{
 *   role: string, prose: string, cards?: Array, warnings?: object, createdAt?: string,
 *   locale: string, translations?: object, addToCartEnabled?: boolean, animate?: boolean,
 * }} message
 * @returns {HTMLElement} the appended message element
 */
export function renderMessage(log, message) {
    const {
        role,
        prose,
        cards,
        createdAt,
        locale,
        translations = {},
        addToCartEnabled = false,
        animate = true,
    } = message;

    const isUser = role === ROLE_USER;

    const wrapper = document.createElement('div');
    wrapper.className = `swag-assistant-message swag-assistant-message--${isUser ? 'user' : 'assistant'}`;

    // Only genuinely new messages animate. Re-hydrated history is content, not an event — and
    // nothing may sit at opacity 0 at rest, because JS enhances an entrance, it never gates
    // existence.
    if (animate) {
        wrapper.classList.add('is-entering');
    }

    wrapper.appendChild(buildProse(prose));

    if (Array.isArray(cards) && cards.length > 0) {
        renderCards(wrapper, cards, { locale, addToCartEnabled, translations });
    }

    const time = buildTime(createdAt, locale);
    if (time) {
        wrapper.appendChild(time);
    }

    log.appendChild(wrapper);
    scrollToLatest(log);

    if (animate) {
        window.requestAnimationFrame(() => wrapper.classList.remove('is-entering'));
    }

    return wrapper;
}

/**
 * `textContent`, never `innerHTML`.
 *
 * The prose is model output. Rendering it as markup would make every reply an injection surface, and
 * a markdown parser buys nothing here — the model is instructed to answer in plain sentences, and
 * the figures a shopper needs live on the cards, not in the text.
 */
function buildProse(prose) {
    const body = document.createElement('div');
    body.className = 'swag-assistant-message__body';

    const paragraphs = String(prose ?? '')
        .split(/\n{2,}/)
        .map((paragraph) => paragraph.trim())
        .filter((paragraph) => paragraph !== '');

    if (paragraphs.length === 0) {
        return body;
    }

    paragraphs.forEach((paragraph) => {
        const p = document.createElement('p');
        p.textContent = paragraph;
        body.appendChild(p);
    });

    return body;
}

/**
 * Null when the time is unknown, so the caller can omit the element entirely. A message with no
 * timestamp is a message whose time the server has not told us yet — which is the truth until the
 * transcript carries one.
 */
function buildTime(createdAt, locale) {
    const label = formatTime(createdAt, locale);

    if (label === '') {
        return null;
    }

    const time = document.createElement('time');
    time.className = 'swag-assistant-message__time';
    // A machine-readable dateTime beside the human one, so the timestamp survives being read by
    // something other than eyes.
    time.dateTime = new Date(createdAt).toISOString();
    time.textContent = label;

    return time;
}

export function scrollToLatest(log) {
    log.scrollTop = log.scrollHeight;
}
