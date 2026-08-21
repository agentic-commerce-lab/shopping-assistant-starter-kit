/*
 * Turns one message into DOM.
 *
 * The hierarchy is carried entirely by one contrast: **the shopper is contained, the assistant is
 * not.** A shopper's message is a right-aligned bubble; the assistant's is full-width text with no
 * container at all. That reads as the shop speaking rather than as a peer in a group chat, which is
 * why no avatar or accent rule is added on top of it.
 */
import { renderCards } from './card';
import { toFragment } from './markdown';

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
        warnings,
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

    // Order is the argument: the claim, then the correction, then the evidence that corrects it.
    const warning = buildWarning(warnings, translations);
    if (warning) {
        wrapper.appendChild(warning);
    }

    if (Array.isArray(cards) && cards.length > 0) {
        renderCards(wrapper, cards, { locale, addToCartEnabled, translations });
    }

    const time = buildTime(createdAt, locale);
    if (time) {
        wrapper.appendChild(time);
    }

    log.appendChild(wrapper);
    scrollToLatest(log, wrapper);

    if (animate) {
        window.requestAnimationFrame(() => wrapper.classList.remove('is-entering'));
    }

    return wrapper;
}

/**
 * Text nodes, never `innerHTML`.
 *
 * **Corrected, 2026-08-21.** This used to split on blank lines and set `textContent`, on the stated
 * grounds that "the model is instructed to answer in plain sentences" — an instruction the system
 * prompt did not actually contain. So the model formatted, and the shopper read the syntax:
 * measured on the live shop, *"1. **Alloy Water Bottle 750 ml** 2. **Alloy Water Bottle 750ml**"*,
 * asterisks visible and both list items collapsed onto one line, because a single newline was not a
 * break here.
 *
 * The prompt now asks for restraint *and* {@see toFragment} reads what arrives anyway — a prompt is
 * a request, not a guarantee, which is the same reasoning that puts a grounding audit behind every
 * rule the prompt already states. The safety property the old comment was defending is unchanged
 * and unconditional: no HTML is ever parsed, because the parser emits DOM nodes rather than markup.
 * See `markdown.js`.
 */
function buildProse(prose) {
    const body = document.createElement('div');
    body.className = 'swag-assistant-message__body';

    body.appendChild(toFragment(prose));

    return body;
}

/**
 * Says out loud when the reply's own words contradict the cards beside them.
 *
 * The server supplies this: `warnings.unbackedAvailabilityClaims` and `warnings.unbackedPrices`. The
 * controller's comment states the intent — *"the cards are always authoritative; this says when the
 * sentence beside them is not, so the interface can annotate it, de-emphasise it, or drop it."*
 * Ignoring it would leave the handsomest part of the product carrying its ugliest known defect: a
 * live turn once replied *"the Trail Jersey is available in Blue, size M"* beside a card reporting
 * stock 0.
 *
 * The notice can be **specific** rather than hedging, because the signal is narrow by design: an
 * availability claim is only flagged when *every* rendered card is out of stock, so the true state is
 * known rather than guessed.
 *
 * Three things this deliberately does not do:
 *
 * - **It does not edit or delete the prose.** Rewriting a reply to hide a mistake is how a product
 *   loses the right to be trusted, and phrase-level surgery would mangle sentences.
 * - **It does not dim the prose.** "De-emphasise" is one of the options the server offers, but
 *   reducing body-text contrast fails the accessibility floor. Emphasis is added to the correction,
 *   never subtracted from the text.
 * - **It does not highlight the offending phrase inline.** Underlining the model's error mid-sentence
 *   draws the eye to one failure and quietly undermines every other sentence.
 */
function buildWarning(warnings, translations) {
    const availability = warnings?.unbackedAvailabilityClaims ?? [];
    const prices = warnings?.unbackedPrices ?? [];

    if (availability.length === 0 && prices.length === 0) {
        return null;
    }

    const el = document.createElement('p');
    el.className = 'swag-assistant-warning';
    // "note" rather than "alert": it is a correction to something already on screen, not an
    // interruption, and an assertive live region would talk over the reply itself.
    el.setAttribute('role', 'note');

    // Availability outranks price. Being told a sold-out item is available is the failure that
    // cancels an order; a restated number is a smaller sin.
    el.textContent = availability.length > 0
        ? (translations.warningAvailability ?? '')
        : (translations.warningPrice ?? '');

    return el;
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

/**
 * Puts the newest message where it can actually be read.
 *
 * Scrolling to the bottom is right for a short reply and wrong for a long one: it parks the *end* of
 * the message at the bottom of the view, so a reply taller than the panel opens with its own answer
 * already above the fold. Reported from the deployed shop with a screenshot — a reply listing jerseys
 * and tyres had "the text about jerseys pushed so far up it isn't visible", and what the shopper
 * landed on was the card row underneath it.
 *
 * So a message taller than the viewport is aligned to its **start**, and everything else still goes
 * to the bottom. Reading a long answer from its first line is not a preference; reading it from the
 * middle is a bug.
 *
 * @param {HTMLElement} log
 * @param {HTMLElement} [target] the message to bring into view; defaults to the end of the log
 */
export function scrollToLatest(log, target) {
    if (!target || target.offsetHeight <= log.clientHeight) {
        log.scrollTop = log.scrollHeight;

        return;
    }

    // Delta between the two rects rather than `offsetTop`, which is relative to whichever ancestor
    // happens to be positioned and silently wrong the moment that changes.
    log.scrollTop += target.getBoundingClientRect().top - log.getBoundingClientRect().top;
}
/**
 * Suggestion chips, under the greeting, on first open only.
 *
 * The empty state used to be a greeting and a blank field, which asks a shopper to invent a question
 * before they know what the assistant can answer. These are *prompts*, not shortcuts: picking one
 * fills the composer and focuses it, so the shopper still sends their own message and can edit it
 * first. That distinction is why they are buttons inside a labelled group rather than links that fire
 * a request.
 *
 * They leave the moment the conversation starts. A first-run affordance that stays is clutter.
 *
 * @param {HTMLElement} container the greeting message to hang them under
 * @param {Array<string>} prompts
 * @param {{label: string, onPick: (prompt: string) => void}} options
 * @returns {HTMLElement|null}
 */
export function renderChips(container, prompts, { label, onPick }) {
    const usable = (prompts ?? []).map((prompt) => String(prompt).trim()).filter((prompt) => prompt !== '');

    if (usable.length === 0) {
        return null;
    }

    const group = document.createElement('div');
    group.className = 'swag-assistant-chips';
    // A named group, so a screen reader announces what these three buttons are for before reading
    // them out as a list of unrelated questions.
    group.setAttribute('role', 'group');
    group.setAttribute('aria-label', label ?? '');

    usable.forEach((prompt, index) => {
        const chip = document.createElement('button');
        chip.type = 'button';
        chip.className = 'swag-assistant-chip';
        chip.textContent = prompt;
        chip.style.setProperty('--swag-assistant-chip-delay', `${index * 60}ms`);
        chip.addEventListener('click', () => onPick(prompt));
        group.appendChild(chip);
    });

    // Above the timestamp, not after it: the chips are part of the greeting, and a stamp is what ends
    // a message.
    const time = container.querySelector('.swag-assistant-message__time');

    if (time) {
        container.insertBefore(group, time);
    } else {
        container.appendChild(group);
    }

    return group;
}

