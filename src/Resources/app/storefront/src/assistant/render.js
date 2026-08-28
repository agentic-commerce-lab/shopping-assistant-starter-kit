/*
 * Turns one message into DOM.
 *
 * The hierarchy is carried entirely by one contrast: **the shopper is contained, the assistant is
 * not.** A shopper's message is a right-aligned bubble; the assistant's is full-width text with no
 * container at all. That reads as the shop speaking rather than as a peer in a group chat, which is
 * why no avatar or accent rule is added on top of it.
 */
import { renderCards } from './card.js';
import { toFragment } from './markdown.js';

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
 * The price with the quantity it assumes, when that quantity is worth stating.
 *
 * The server chooses the figure; this only refuses to print it bare. A graduated product priced at
 * its minimum order quantity is a true number attached to a condition, and dropping the condition
 * makes it a false one.
 *
 * @param {{price?: number, currency?: string, priceQuantity?: number}} card
 * @param {string} locale
 * @param {{priceAt?: string}} translations
 * @returns {string} the formatted price, or '' when there is nothing honest to print
 */
export function formatPriceBasis(card, locale, translations = {}) {
    const price = formatPrice(card?.price, card?.currency, locale);

    if (price === '') {
        return '';
    }

    // A card written before this field existed, or a client that omits it, means one unit — never
    // a guess at what the minimum might have been.
    const quantity = Number.isInteger(card?.priceQuantity) ? card.priceQuantity : 1;

    if (quantity <= 1) {
        return price;
    }

    return (translations.priceAt ?? '%price% each at %count% units')
        .replace('%price%', price)
        .replace('%count%', String(quantity));
}

/** Two is enough to differentiate at a glance without crowding a 176px card. */
const MAX_SPEC_CHIPS = 2;

/**
 * A short "Merino · Waterproof" line for a card, built entirely from the `properties` the server
 * already sent — nothing here is read from the model's prose, same rule `formatPriceBasis` follows.
 *
 * Two rules keep this from contradicting the options line directly above it on the same card:
 *
 * 1. **Skip any property group already shown in `card.options`** (case-insensitive key match). A
 *    variant commonly carries its whole property list even though it is itself only one specific
 *    colour/size — a Trail Jersey in Blue/M can still have `properties: {Colour: [Blue, Black]}` —
 *    so showing that group again as a "spec chip" can print a sibling's value right under an options
 *    line that already states this unit's own value for the same group.
 * 2. **At most one value per remaining group.** The same sprawling-variant data means a group can
 *    carry several values; only the first is this unit's own attribute the way `options` states one
 *    value per group, never a list.
 */
export function formatSpecChips(card, maxChips = MAX_SPEC_CHIPS) {
    const properties = card?.properties;

    if (properties === null || typeof properties !== 'object' || Array.isArray(properties)) {
        return '';
    }

    const shownGroups = new Set(Object.keys(card?.options ?? {}).map((group) => group.toLowerCase()));

    const values = Object.entries(properties)
        .filter(([group]) => !shownGroups.has(group.toLowerCase()))
        .map(([, groupValues]) => (Array.isArray(groupValues) ? groupValues[0] : undefined))
        .filter((value) => typeof value === 'string' && value !== '');

    if (values.length === 0) {
        return '';
    }

    return values.slice(0, maxChips).join(' · ');
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
        handoff,
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

    // After the correction, before the evidence: an escalated reply carries no cards, so in practice
    // this is the last thing in the message — but the order holds if that ever changes.
    const contact = buildHandoff(handoff, translations);
    if (contact) {
        wrapper.appendChild(contact);
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
    const properties = warnings?.unbackedPropertyClaims ?? [];

    if (availability.length === 0 && prices.length === 0 && properties.length === 0) {
        return null;
    }

    const el = document.createElement('p');
    el.className = 'swag-assistant-warning';
    // "note" rather than "alert": it is a correction to something already on screen, not an
    // interruption, and an assertive live region would talk over the reply itself.
    el.setAttribute('role', 'note');

    // Availability outranks price outranks property. Being told a sold-out item is available is
    // the failure that cancels an order; a restated number is a smaller sin; a wrong material or
    // attribute claim is smaller still.
    if (availability.length > 0) {
        el.textContent = translations.warningAvailability ?? '';
    } else if (prices.length > 0) {
        el.textContent = translations.warningPrice ?? '';
    } else {
        el.textContent = translations.warningProperty ?? '';
    }

    return el;
}

/**
 * The contact block for an escalated reply, or null.
 *
 * Null covers both "not an escalation" and "no destination configured" — the server collapses those
 * into one absent value on purpose, because a contact notice with no link is exactly the empty
 * promise this feature exists to remove.
 *
 * The link text is a snippet, never the URL: a raw href shown to a shopper reads as debug output, and
 * `handoff.url` may be an absolute address on another host.
 */
function buildHandoff(handoff, translations) {
    if (!handoff || typeof handoff.url !== 'string' || handoff.url === '') {
        return null;
    }

    const el = document.createElement('div');
    el.className = 'swag-assistant-handoff';
    // "note", matching the warning: it accompanies a reply already on screen rather than interrupting
    // it, and an assertive region would talk over the reply itself.
    el.setAttribute('role', 'note');

    const text = document.createElement('p');
    text.className = 'swag-assistant-handoff__text';
    // The merchant's own words when they wrote any, the translated default when they did not — the
    // same fallback `greeting` uses, so an unconfigured German shop still reads as German.
    text.textContent = typeof handoff.message === 'string' && handoff.message !== ''
        ? handoff.message
        : (translations.handoffMessage ?? '');
    el.appendChild(text);

    const link = document.createElement('a');
    link.className = 'swag-assistant-handoff__action';
    link.href = handoff.url;
    link.textContent = translations.handoffAction ?? '';
    // The href is scheme-checked server-side (SystemConfigAssistantConfig::safeUrl). This is the
    // second half of that: an external destination must not get a handle on the shop's window.
    link.rel = 'noopener noreferrer';
    el.appendChild(link);

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
 * Scrolls the log to its end.
 *
 * **A known defect lives here, and five attempts failed to fix it.** For a reply taller than the
 * panel this parks the *end* of the message at the bottom of the view, so the answer opens already
 * above the fold. Reported from the deployed shop with a screenshot: a reply listing jerseys and
 * tyres had "the text about jerseys pushed so far up it isn't visible", and what the shopper landed
 * on was the card row underneath it.
 *
 * What was tried, and what each measurement said:
 *
 * | Attempt | Result |
 * |---|---|
 * | `scrollTop += rect(msg).top - rect(log).top` | first line 88px too high — the log has `scroll-behavior: smooth`, so the offset was read mid-animation |
 * | `scrollTo({top: msg.offsetTop})` | 63px too high |
 * | the same, repeated on the next animation frame | 52px too high — one frame is not enough |
 * | `msg.scrollIntoView({block: 'start'})` | 112px too high |
 * | all of the above, measured after a 700ms settle | unchanged |
 *
 * Instrumenting every scroll on the log proved nothing else moves it afterwards: the last scroll in
 * the sequence is this function's own. So the offset being written is simply not the offset the
 * message ends up at — the message's position inside this scroller is **not stable at append
 * time**, because the log is a column flex container whose first child carries `margin-top: auto`,
 * and the panel is simultaneously resolving its own height between `min-height` and `max-height`.
 * Free space decides the transcript's position, and free space is still changing.
 *
 * **That makes it a layout problem, not a scrolling one.** The fix is to stop the offset depending
 * on free space — anchor the transcript to the top of the log instead of letting a short one settle
 * at the bottom. That changes how a one-message conversation looks, which is a design decision
 * rather than a bug fix, so it is written down here instead of taken unilaterally. See the
 * `test.fixme` in the end-to-end suite.
 *
 * @param {HTMLElement} log
 * @param {HTMLElement} [target] accepted and currently unused — see above
 */
export function scrollToLatest(log, target) {
    log.scrollTop = log.scrollHeight;
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

