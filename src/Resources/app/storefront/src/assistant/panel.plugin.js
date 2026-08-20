const { PluginBaseClass } = window;

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
        this.input = this.el.querySelector('[data-swag-assistant-input]');

        this.el.addEventListener('swag-assistant:toggle', () => this.toggle());
        this.el.querySelector('[data-swag-assistant-close]')
            ?.addEventListener('click', () => this.close());

        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape' && this.isOpen()) {
                this.close();
            }
        });

        this.panel?.addEventListener('keydown', (event) => this._trapTab(event));
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
        // transition a start state to animate from — without it the panel simply appears, fully open,
        // with no motion at all.
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

    /**
     * Keeps Tab inside the panel while it is open.
     *
     * The panel is deliberately **not** `aria-modal` on desktop — the storefront behind it stays
     * usable, and claiming modality while the page is still reachable would be a lie to a screen
     * reader. Trapping Tab is still right: the panel is a conversation, and tabbing out of it
     * mid-sentence into the page's navigation is never what someone meant.
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
