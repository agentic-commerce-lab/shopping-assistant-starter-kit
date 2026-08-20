import { createTransport } from './transport';
import { renderMessage, renderChips, scrollToLatest } from './render';
import { createThinking } from './thinking';
import { createComposer } from './composer';
import { createCreature } from './creature';
import { markAdded } from './card';

const { PluginBaseClass } = window;

const TOKEN_KEY = 'swagAssistantToken';

/** `ChatRequest::MAX_MESSAGE_LENGTH`. The server rejects rather than truncates, so refuse earlier. */
const MAX_MESSAGE_LENGTH = 2000;

const FOCUSABLE = [
    'button:not([disabled])',
    'textarea:not([disabled])',
    'input:not([disabled])',
    'a[href]',
    '[tabindex]:not([tabindex="-1"])',
].join(', ');

/** Long enough for the 380ms open transition, short enough not to strand a reduced-motion user. */
const CLOSE_FALLBACK_MS = 450;

/** The viewport below which the panel is a full-screen sheet rather than a floating panel. */
const SHEET_BREAKPOINT = 575;

export default class SwagAssistantPanel extends PluginBaseClass {
    init() {
        this.panel = this.el.querySelector('[data-swag-assistant-panel]');
        this.orb = this.el.querySelector('[data-swag-assistant-orb]');
        this.log = this.el.querySelector('[data-swag-assistant-log]');
        this.avatar = this.el.querySelector('[data-swag-assistant-avatar]');

        this.locale = this.el.dataset.locale || 'en-GB';
        this.addToCartEnabled = this.el.dataset.addToCartEnabled === 'true';
        this.translations = this._readTranslations();
        this.isBusy = false;

        this.transport = createTransport({
            chatUrl: this.el.dataset.chatUrl,
            historyUrl: this.el.dataset.historyUrl,
            cardsUrl: this.el.dataset.cardsUrl,
            cartUrl: this.el.dataset.cartUrl,
        });
        this.thinking = createThinking(this.log, this.translations);

        this.composer = createComposer({
            form: this.el.querySelector('[data-swag-assistant-form]'),
            input: this.el.querySelector('[data-swag-assistant-input]'),
            button: this.el.querySelector('[data-swag-assistant-send]'),
            maxLength: MAX_MESSAGE_LENGTH,
            onSubmit: (message) => this._submit(message),
        });

        // The docked creature: the same object as the orb, so opening the panel reads as the bubble
        // coming along rather than as a static avatar appearing in a frame. No pointer tracking and no
        // idle beats — it takes every cue from the conversation.
        this.face = this.avatar
            ? createCreature(this.avatar, { base: 'happy' })
            : null;

        this._registerPanelEvents();
        this._registerLogEvents();
    }

    _readTranslations() {
        const source = this.el.querySelector('[data-swag-assistant-translations]');

        try {
            return JSON.parse(source?.textContent || '{}');
        } catch {
            // A missing snippet must not take the whole widget down with it.
            return {};
        }
    }

    _registerPanelEvents() {
        this.el.addEventListener('swag-assistant:toggle', () => this.toggle());
        this.el.querySelector('[data-swag-assistant-close]')
            ?.addEventListener('click', () => this.close());
        this.el.querySelector('[data-swag-assistant-reset]')
            ?.addEventListener('click', () => this._reset());

        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape' && this.isOpen()) {
                this.close();
            }
        });

        this.panel?.addEventListener('keydown', (event) => this._trapTab(event));

        // Re-hydrate once, on first open rather than on page load: a shopper who never opens the panel
        // should cost the server nothing.
        this.el.addEventListener('swag-assistant:open', () => this._hydrateOnce(), { once: true });
    }

    _registerLogEvents() {
        // Delegated: cards are created long after this handler is bound, and rebinding per card would
        // leak a listener for every product a long conversation shows.
        this.log?.addEventListener('click', (event) => {
            const button = event.target.closest('[data-swag-assistant-add]');

            if (button && !button.disabled) {
                this._addToCart(button);
            }
        });
    }

    /**
     * Tells the creatures how to feel about something.
     *
     * The orb lives in a different chunk and is a different plugin, so the two never hold references
     * to each other: this announces the moment on the shared root and the orb decides what to do with
     * it. Adding a reaction there needs no change here, and vice versa.
     */
    _feel(mood, { hold = 1400, gesture } = {}) {
        if (gesture === 'laugh') {
            this.face?.laugh(hold);
        } else {
            if (gesture === 'shake') {
                this.face?.shake();
            }

            this.face?.setMood(mood, hold);
        }

        this.el.dispatchEvent(new CustomEvent('swag-assistant:mood', {
            detail: { mood, hold, gesture },
        }));
    }

    /**
     * Adds a card's product through Shopware's **own** cart route.
     *
     * The shop keeps ownership of cart rules, prices and stock reservation; this plugin adds no cart
     * logic. The button is only rendered at all when the merchant's `enableAddToCart` guardrail is on
     * — that flag governs the assistant's tool rather than this route, and gating the button on it
     * anyway is the only reading of the setting a merchant would accept.
     */
    async _addToCart(button) {
        const card = button.closest('.swag-assistant-card');

        button.disabled = true;
        button.classList.add('is-adding');
        card?.querySelector('.swag-assistant-card__error')?.remove();

        try {
            await this.transport.addToCart(button.dataset.swagAssistantAdd);

            // Re-render the header's cart count through the theme's own plugin. `fetch()` is
            // CartWidgetPlugin's public method — verified against the installed 6.7 storefront rather
            // than guessed, because an invented event name fails silently and leaves a stale count.
            window.PluginManager.getPluginInstances('CartWidget')
                ?.forEach((instance) => instance.fetch?.());

            markAdded(button, this.translations.addedToCart ?? '');

            // The one unambiguous success in the whole widget, and the only place the creature laughs.
            // Reserving it for this keeps it worth something: a celebration attached to every click is
            // noise by the third one.
            this._feel('laugh', { gesture: 'laugh', hold: 1600 });
        } catch {
            button.disabled = false;
            button.classList.remove('is-adding');
            this._feel('focus', { gesture: 'shake', hold: 900 });

            // Inline on the card, not a toast: the failure belongs to this product, and a toast
            // floating over the storefront is detached from the thing that failed.
            const message = document.createElement('p');
            message.className = 'swag-assistant-card__error';
            message.setAttribute('role', 'alert');
            message.textContent = this.translations.errorCartFailed ?? '';
            card?.querySelector('.swag-assistant-card__info')?.appendChild(message);
        }
    }

    isOpen() {
        return this.el.classList.contains('is-open');
    }

    toggle() {
        if (this.isOpen()) {
            this.close();
            return;
        }

        this.open();
    }

    open() {
        this.panel.hidden = false;

        /*
         * `aria-modal` is set here rather than in the template, because whether it is true depends on
         * the viewport. On desktop the panel is genuinely non-modal — the storefront behind it stays
         * usable, and claiming modality to a screen reader while the page is still reachable is a
         * lie. The mobile sheet covers the entire viewport, where it is simply true.
         */
        this.panel.setAttribute(
            'aria-modal',
            window.innerWidth <= SHEET_BREAKPOINT ? 'true' : 'false',
        );

        // Two frames, deliberately. One lets the `hidden` removal take effect, the second gives the
        // transition a start state to animate from — without it the panel simply appears, fully open,
        // with no motion at all.
        window.requestAnimationFrame(() => window.requestAnimationFrame(() => {
            this.el.classList.add('is-open');
        }));

        this.orb?.setAttribute('aria-expanded', 'true');
        this.composer.focus();
        this.el.dispatchEvent(new CustomEvent('swag-assistant:open'));
    }

    close() {
        this.el.classList.remove('is-open');
        this.orb?.setAttribute('aria-expanded', 'false');

        // Focus goes back where it came from. Someone who closed with Escape must not be dropped at
        // the top of the document.
        this.orb?.focus();
        this.el.dispatchEvent(new CustomEvent('swag-assistant:close'));

        const hide = () => {
            if (!this.isOpen()) {
                this.panel.hidden = true;
            }
        };

        this.panel.addEventListener('transitionend', hide, { once: true });
        // prefers-reduced-motion removes the transition, so transitionend never fires.
        window.setTimeout(hide, CLOSE_FALLBACK_MS);
    }

    async _hydrateOnce() {
        const token = window.sessionStorage.getItem(TOKEN_KEY);
        const { messages } = await this.transport.history(token);

        await this._renderHistory(messages);
    }

    /**
     * @param {Array<object>} messages
     */
    async _renderHistory(messages) {
        this.log.replaceChildren();

        if (!Array.isArray(messages) || messages.length === 0) {
            this._renderGreeting();
            return;
        }

        // One request for every card in the whole transcript, deduplicated — not one per message.
        // Without this a restored conversation showed prose reading "the card here shows its current
        // price and stock" with no card beneath it.
        const wanted = [...new Set(messages.flatMap((message) => message.cardIds ?? []))];
        const resolved = await this.transport.cards(wanted);

        // animate: false — re-hydrated history is content, not an event.
        messages.forEach((message) => renderMessage(this.log, {
            role: message.role,
            prose: message.prose,
            createdAt: message.createdAt,
            warnings: message.warnings,
            // Order preserved as the turn rendered them; anything the catalogue no longer offers is
            // simply absent rather than invented.
            cards: (message.cardIds ?? []).map((id) => resolved.get(id)).filter(Boolean),
            locale: this.locale,
            translations: this.translations,
            addToCartEnabled: this.addToCartEnabled,
            animate: false,
        }));

        scrollToLatest(this.log);
    }

    _renderGreeting() {
        const greeting = this.el.dataset.greeting?.trim();

        if (!greeting) {
            return;
        }

        const message = renderMessage(this.log, {
            role: 'assistant',
            prose: greeting,
            // **No timestamp, deliberately.** Not because we cannot know it — this line is generated
            // here and now — but because nothing *happened* at that time. A greeting is the panel's
            // opening state rather than a message that arrived, and stamping it drops a third
            // identical time into a two-message exchange for no information.
            locale: this.locale,
            translations: this.translations,
            animate: false,
        });

        this.chips = renderChips(message, this._suggestions(), {
            label: this.translations.suggestionsLabel,
            onPick: (prompt) => {
                this.composer.fill(prompt);
                this._feel('happy', { hold: 900 });
            },
        });

        scrollToLatest(this.log);
    }

    /**
     * The prompts a merchant can translate or blank out per sales channel, since they are snippets
     * rather than plugin config. Anything left empty is dropped, so a shop that wants no chips gets
     * none by clearing them.
     */
    _suggestions() {
        return [
            this.translations.suggestionOne,
            this.translations.suggestionTwo,
            this.translations.suggestionThree,
        ].filter(Boolean);
    }

    /** Collapses the chip row once the shopper has asked something of their own. */
    _retireChips() {
        if (!this.chips) {
            return;
        }

        const chips = this.chips;
        this.chips = null;

        chips.classList.add('is-leaving');
        chips.addEventListener('animationend', () => chips.remove(), { once: true });
        // The animation is removed under prefers-reduced-motion, so animationend never fires.
        window.setTimeout(() => chips.remove(), 400);
    }

    /**
     * Starts over.
     *
     * Client-side only, and complete: dropping the session token is what makes the *server* start a
     * new conversation, because the next turn arrives without one and is issued a fresh transcript.
     * The old transcript is not deleted — it is simply no longer addressed, which is the same thing
     * from the shopper's side and requires no endpoint that does not exist.
     */
    _reset() {
        window.sessionStorage.removeItem(TOKEN_KEY);
        this.thinking.stop();
        this.composer.clear();
        this.log.replaceChildren();
        this.chips = null;
        this._renderGreeting();
        this._feel('wow', { hold: 900 });
        this.composer.focus();
    }

    async _submit(message) {
        // The composer refuses a re-entrant submit itself; this guards the one path that bypasses it,
        // which is the retry button calling straight in with the original text.
        if (this.isBusy) {
            return;
        }

        this._retireChips();
        this._setBusy(true);
        this.composer.clear();

        const sent = renderMessage(this.log, {
            role: 'user',
            prose: message,
            createdAt: new Date().toISOString(),
            locale: this.locale,
            translations: this.translations,
        });

        this.thinking.start();

        try {
            const reply = await this.transport.send(message, window.sessionStorage.getItem(TOKEN_KEY));

            if (reply.token) {
                window.sessionStorage.setItem(TOKEN_KEY, reply.token);
            }

            renderMessage(this.log, {
                role: 'assistant',
                prose: reply.prose,
                cards: reply.cards,
                warnings: reply.warnings,
                createdAt: new Date().toISOString(),
                locale: this.locale,
                translations: this.translations,
                addToCartEnabled: this.addToCartEnabled,
            });

            // An answer arrived. Brief, and it is the only reaction to a reply — a nineteen-second wait
            // ending in a celebration would be the wrong size of gesture for something that is simply
            // the product working.
            this._feel('wow', { hold: 900 });
        } catch (error) {
            this._renderFailure(error, sent, message);
        } finally {
            this.thinking.stop();
            this._setBusy(false);
        }
    }

    _setBusy(busy) {
        this.isBusy = busy;
        this.composer.setBusy(busy);
        // Not the textarea: someone can usefully start composing the next question while waiting.
        this.el.classList.toggle('is-busy', busy);
        // The creature is the busy signal: it turns attentive while a turn is in flight, and the
        // phased indicator in the log carries the words.
        this.face?.setBaseMood(busy ? 'curious' : 'happy');
    }

    /**
     * Three failures a shopper must be able to tell apart, and one rule across all of them: **their
     * message stays in the log.** Losing what someone typed because a request failed is the outcome
     * that makes a retry feel like starting over.
     */
    _renderFailure(error, sentMessage, originalText) {
        const notice = document.createElement('div');
        notice.className = 'swag-assistant-failure';
        notice.setAttribute('role', 'alert');

        const text = document.createElement('span');
        notice.appendChild(text);

        if (error.status === 503 || error.status === 429) {
            // The shop has no model, or the kill switch / daily cap stopped it. Retrying cannot help,
            // so no retry is offered and the composer closes.
            text.textContent = this.translations.errorUnavailable ?? '';
            this.composer.close();
        } else if (error.status === 400) {
            text.textContent = this.translations.errorTooLong ?? '';
        } else {
            text.textContent = this.translations.errorNetwork ?? '';
            notice.appendChild(this._buildRetry(notice, sentMessage, originalText));
        }

        this._feel('focus', { gesture: 'shake', hold: 1200 });
        this.log.appendChild(notice);
        scrollToLatest(this.log);
    }

    _buildRetry(notice, sentMessage, originalText) {
        const retry = document.createElement('button');
        retry.type = 'button';
        retry.className = 'swag-assistant-failure__retry';
        retry.textContent = this.translations.errorRetry ?? '';

        retry.addEventListener('click', () => this._retry(notice, sentMessage, originalText));

        return retry;
    }

    /**
     * Asks the server what happened before asking it again.
     *
     * **Measured against the live shop: the server finishes a turn even when the client is gone.** A
     * request aborted at 3 seconds still landed both turns about twelve seconds later. So a failed
     * request usually means the answer exists and only the *response* was lost — and blindly
     * re-sending would record the shopper's question a second time, so a reload would show them
     * asking twice.
     *
     * Re-hydrating first is also the better outcome when it works: the shopper gets the answer they
     * already paid for instead of waiting another nineteen seconds for a duplicate of it.
     */
    async _retry(notice, sentMessage, originalText) {
        notice.remove();
        sentMessage.remove();

        const { messages } = await this.transport.history(window.sessionStorage.getItem(TOKEN_KEY));
        const last = Array.isArray(messages) ? messages[messages.length - 1] : undefined;

        // An assistant turn at the end means the server got there without us.
        if (last?.role === 'assistant') {
            await this._renderHistory(messages);
            return;
        }

        this._submit(originalText);
    }

    /**
     * Keeps Tab inside the panel while it is open.
     *
     * On desktop the panel is deliberately **not** `aria-modal` — the storefront behind it stays
     * usable, and claiming modality while the page is still reachable would be a lie to a screen
     * reader. Trapping Tab is still right: tabbing out of a half-written question into the page's
     * navigation is never what someone meant.
     */
    _trapTab(event) {
        if (event.key !== 'Tab') {
            return;
        }

        const focusable = [...this.panel.querySelectorAll(FOCUSABLE)]
            .filter((el) => el.offsetParent !== null);

        if (focusable.length === 0) {
            return;
        }

        const first = focusable[0];
        const last = focusable[focusable.length - 1];

        if (event.shiftKey && document.activeElement === first) {
            event.preventDefault();
            last.focus();
            return;
        }

        if (!event.shiftKey && document.activeElement === last) {
            event.preventDefault();
            first.focus();
        }
    }
}
