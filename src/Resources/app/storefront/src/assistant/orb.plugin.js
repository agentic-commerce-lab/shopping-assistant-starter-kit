const { PluginBaseClass } = window;

const NUDGE_DELAY_MS = 4000;
const NUDGE_VISIBLE_MS = 6000;
const NUDGE_SEEN_KEY = 'swagAssistantNudgeSeen';

/**
 * Other fixed things live in the bottom-right corner. Measured in a real 6.7 shop: the
 * cookie-consent bar is `position: fixed`, `z-index: 1100` and 57px tall, and it covered 38px of the
 * 60px orb — on a first visit, which is exactly when the nudge below fires.
 */
const OBSTRUCTION_SELECTORS = [
    '.cookie-permission-container',
    '.js-cookie-permission-container',
];

const OBSTRUCTION_GAP = 8;

export default class SwagAssistantOrb extends PluginBaseClass {
    init() {
        this.root = this.el.closest('[data-swag-assistant-root]');
        this.nudge = this.root?.querySelector('[data-swag-assistant-nudge]');

        this._varyBlink();
        this._trackObstructions();
        this._scheduleNudge();

        this.el.addEventListener('click', () => {
            this._hideNudge();
            this.root?.dispatchEvent(new CustomEvent('swag-assistant:toggle'));
        });
    }

    /**
     * Varies the blink *phase*, not whether it blinks.
     *
     * Without this, every orb in every open tab blinks in lockstep, which reads as a synchronised
     * animation rather than as a creature. The value is a CSS custom property so the animation
     * itself stays in the stylesheet.
     */
    _varyBlink() {
        this.el.style.setProperty('--swag-assistant-blink-delay', `${Math.floor(Math.random() * 5000)}ms`);
    }

    /**
     * Publishes the height of anything covering our corner, so the stylesheet can lift the orb clear.
     *
     * Measured rather than hardcoded: the consent bar wraps to two lines on narrow viewports, so its
     * height is not a constant. Raising the orb's z-index above the bar instead would be worse — it
     * would sit on top of the consent buttons.
     */
    _trackObstructions() {
        if (!this.root) {
            return;
        }

        const update = () => {
            this.root.style.setProperty('--swag-assistant-obstruction', `${this._obstructionHeight()}px`);
        };

        update();

        const bar = this._findObstruction();

        if (bar) {
            // Handles the bar wrapping to two lines, and the viewport changing under it.
            new ResizeObserver(update).observe(bar);

            // The bar is removed from the DOM when dismissed rather than hidden, so watch its parent
            // for that removal. Scoped to one element's children — not a body-wide subtree observer,
            // which would fire on every storefront DOM change for no benefit.
            if (bar.parentElement) {
                new MutationObserver(update).observe(bar.parentElement, { childList: true });
            }
        }

        window.addEventListener('resize', update, { passive: true });
    }

    _findObstruction() {
        for (const selector of OBSTRUCTION_SELECTORS) {
            const candidate = document.querySelector(selector);

            if (candidate) {
                return candidate;
            }
        }

        return null;
    }

    _obstructionHeight() {
        const bar = this._findObstruction();

        if (!bar) {
            return 0;
        }

        const rect = bar.getBoundingClientRect();

        if (rect.height === 0) {
            return 0;
        }

        // Only count something that actually sits along the bottom edge. A consent notice rendered
        // inline higher up the page is not in our way.
        const sitsAtTheBottom = rect.bottom >= window.innerHeight - 4;

        return sitsAtTheBottom ? Math.round(rect.height) + OBSTRUCTION_GAP : 0;
    }

    /**
     * Discoverability without nagging: once per session, and never over an already-open panel.
     */
    _scheduleNudge() {
        if (!this.nudge || window.sessionStorage.getItem(NUDGE_SEEN_KEY) === '1') {
            return;
        }

        window.setTimeout(() => {
            if (this.root?.classList.contains('is-open')) {
                return;
            }

            this.nudge.hidden = false;
            window.sessionStorage.setItem(NUDGE_SEEN_KEY, '1');
            window.setTimeout(() => this._hideNudge(), NUDGE_VISIBLE_MS);
        }, NUDGE_DELAY_MS);
    }

    _hideNudge() {
        if (this.nudge) {
            this.nudge.hidden = true;
        }
    }
}
