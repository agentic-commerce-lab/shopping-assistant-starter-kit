import { createTransport } from './transport';
import { renderMessage, scrollToLatest } from './render';
import { createThinking } from './thinking';

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

/** Long enough for the 320ms open transition, short enough not to strand a reduced-motion user. */
const CLOSE_FALLBACK_MS = 400;

export default class SwagAssistantPanel extends PluginBaseClass {
    init() {
        this.panel = this.el.querySelector('[data-swag-assistant-panel]');
        this.orb = this.el.querySelector('[data-swag-assistant-orb]');
        this.log = this.el.querySelector('[data-swag-assistant-log]');
        this.form = this.el.querySelector('[data-swag-assistant-form]');
        this.input = this.el.querySelector('[data-swag-assistant-input]');
        this.sendButton = this.el.querySelector('[data-swag-assistant-send]');

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

        this._registerPanelEvents();
        this._registerComposerEvents();
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

        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape' && this.isOpen()) {
                this.close();
            }
        });

        this.panel?.addEventListener('keydown', (event) => this._trapTab(event));

        // Re-hydrate once, on first open rather than on page load: a shopper who never opens the
        // panel should cost the server nothing.
        this.el.addEventListener('swag-assistant:open', () => this._hydrateOnce(), { once: true });
    }

    _registerComposerEvents() {
        this.form?.addEventListener('submit', (event) => {
            event.preventDefault();
            this._submit();
        });

        // Enter sends, Shift+Enter makes a newline — the convention every chat interface uses.
        this.input?.addEventListener('keydown', (event) => {
            if (event.key === 'Enter' && !event.shiftKey) {
                event.preventDefault();
                this._submit();
            }
        });

        this.input?.addEventListener('input', () => this._reflectLength());

        // Delegated: cards are created long after this handler is bound, and rebinding per card
        // would leak a listener for every product a long conversation shows.
        this.log?.addEventListener('click', (event) => {
            const button = event.target.closest('[data-swag-assistant-add]');

            if (button && !button.disabled) {
                this._addToCart(button);
            }
        });
    }

    /**
     * Adds a card's product through Shopware's **own** cart route.
     *
     * The shop keeps ownership of cart rules, prices and stock reservation; this plugin adds no cart
     * logic. The button is only rendered at all when the merchant's `enableAddToCart` guardrail is
     * on — that flag governs the assistant's tool rather than this route, and gating the button on it
     * anyway is the only reading of the setting a merchant would accept.
     */
    async _addToCart(button) {
        const card = button.closest('.swag-assistant-card');
        const original = button.textContent;

        button.disabled = true;
        card?.querySelector('.swag-assistant-card__error')?.remove();

        try {
            await this.transport.addToCart(button.dataset.swagAssistantAdd);

            // Re-render the header's cart count through the theme's own plugin. `fetch()` is
            // CartWidgetPlugin's public method — verified against the installed 6.7 storefront rather
            // than guessed, because an invented event name fails silently and leaves a stale count.
            window.PluginManager.getPluginInstances('CartWidget')
                ?.forEach((instance) => instance.fetch?.());

            button.textContent = this.translations.addedToCart ?? original;
            button.classList.add('is-added');
        } catch {
            button.disabled = false;

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

        // Two frames, deliberately. One lets the `hidden` removal take effect, the second gives the
        // transition a start state to animate from — without it the panel simply appears, fully
        // open, with no motion at all.
        window.requestAnimationFrame(() => window.requestAnimationFrame(() => {
            this.el.classList.add('is-open');
        }));

        this.orb?.setAttribute('aria-expanded', 'true');
        this.input?.focus();
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

        renderMessage(this.log, {
            role: 'assistant',
            prose: greeting,
            // The greeting is generated here and now, so this client legitimately knows its time.
            // Re-hydrated messages get theirs from the server or show none at all.
            createdAt: new Date().toISOString(),
            locale: this.locale,
            translations: this.translations,
            animate: false,
        });
    }

    /**
     * Refuse an oversized message before the request rather than after.
     *
     * The server rejects anything past the limit outright — it never truncates, because truncating
     * sends the model half a question which it will then answer confidently. Spending a round trip to
     * be told that is a waste of the shopper's time.
     */
    _reflectLength() {
        const tooLong = this.input.value.length > MAX_MESSAGE_LENGTH;

        this.input.classList.toggle('is-too-long', tooLong);
        this.sendButton.disabled = tooLong || this.isBusy;
    }

    async _submit() {
        const message = this.input.value.trim();

        if (message === '' || this.isBusy || message.length > MAX_MESSAGE_LENGTH) {
            return;
        }

        this._setBusy(true);
        this.input.value = '';
        this.input.classList.remove('is-too-long');

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
        } catch (error) {
            this._renderFailure(error, sent, message);
        } finally {
            this.thinking.stop();
            this._setBusy(false);
        }
    }

    _setBusy(busy) {
        this.isBusy = busy;
        this.sendButton.disabled = busy;
        // Not the textarea: someone can usefully start composing the next question while waiting.
        this.el.classList.toggle('is-busy', busy);
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
            this.input.disabled = true;
            this.sendButton.disabled = true;
        } else if (error.status === 400) {
            text.textContent = this.translations.errorTooLong ?? '';
        } else {
            text.textContent = this.translations.errorNetwork ?? '';
            notice.appendChild(this._buildRetry(notice, sentMessage, originalText));
        }

        this.log.appendChild(notice);
        scrollToLatest(this.log);
    }

    _buildRetry(notice, sentMessage, originalText) {
        const retry = document.createElement('button');
        retry.type = 'button';
        retry.className = 'swag-assistant-failure__retry';
        retry.textContent = this.translations.errorRetry ?? '';

        retry.addEventListener('click', () => {
            // Remove the failed exchange rather than stacking a second copy of the question under it.
            notice.remove();
            sentMessage.remove();
            this.input.value = originalText;
            this._submit();
        });

        return retry;
    }

    /**
     * Keeps Tab inside the panel while it is open.
     *
     * The panel is deliberately **not** `aria-modal` on desktop — the storefront behind it stays
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
