import { createCreature, createStillCreature } from './creature';

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

/**
 * How far above the bottom edge still counts as "fixed to the bottom".
 *
 * Was 4px, which a horizontal scrollbar alone exceeds — see `_obstructionHeight()`. Wide enough to
 * survive a scrollbar and a theme's own bottom inset, narrow enough that a notice sitting halfway up
 * the page is still correctly ignored.
 */
const OBSTRUCTION_BOTTOM_TOLERANCE = 40;

/**
 * The orb.
 *
 * This chunk loads on every storefront page, so it does as little as possible: the resting bubble —
 * the material, the float, the wobble, the blink — is entirely CSS and needs none of this. What is
 * here is only what a stylesheet cannot know: where the pointer is, when to hop, and how tall the
 * cookie bar is today.
 */
export default class SwagAssistantOrb extends PluginBaseClass {
    init() {
        this.root = this.el.closest('[data-swag-assistant-root]');
        this.nudge = this.root?.querySelector('[data-swag-assistant-nudge]');

        // A mark holds still. Only the character floats, breathes, hops and tracks the pointer — a
        // brandable icon doing any of that reads as a widget demanding attention rather than offering
        // it, and the merchant chose the neutral one on purpose.
        this.creature = this.root?.dataset.entryPoint === 'creature'
            ? createCreature(this.el, {
                body: this.el.querySelector('[data-swag-assistant-orb-body]') ?? this.el,
                base: 'idle',
                // The corner orb is the only creature that follows the pointer and the only one with idle
                // beats of its own. The avatar in the header takes its cues from the conversation, and the
                // thinking indicator is busy.
                track: true,
                idle: true,
            })
            : createStillCreature();

        this._trackObstructions();
        this._scheduleNudge();
        this._registerReactions();
    }

    /**
     * Everything the creature does in response to something happening.
     *
     * The panel is a separate plugin in a separate chunk, so the two never hold references to each
     * other: the panel announces what happened on the shared root element and the orb decides how to
     * feel about it. That is why adding a reaction here needs no change over there.
     */
    _registerReactions() {
        this.el.addEventListener('pointerenter', () => this.creature.setMood('happy'));
        this.el.addEventListener('pointerleave', () => this.creature.setMood('idle'));

        // Keyboard users get the same acknowledgement a hover gives.
        this.el.addEventListener('focus', () => this.creature.setMood('happy'));
        this.el.addEventListener('blur', () => this.creature.setMood('idle'));

        this.el.addEventListener('click', () => {
            this._hideNudge();
            this.creature.pop();
            this.root?.dispatchEvent(new CustomEvent('swag-assistant:toggle'));
        });

        // Listening while the panel is open: no idle hops competing with the conversation beside it.
        this.root?.addEventListener('swag-assistant:open', () => {
            this.creature.setBaseMood('focus');
        });

        this.root?.addEventListener('swag-assistant:close', () => {
            this.creature.setBaseMood('idle');
            this.creature.jump();
        });

        /**
         * The panel's own moments, forwarded. `detail.mood` is one of the stylesheet's seven; a
         * `gesture` of 'laugh' or 'shake' plays the body motion that goes with it.
         */
        this.root?.addEventListener('swag-assistant:mood', (event) => {
            const { mood, hold = 1400, gesture } = event.detail ?? {};

            if (gesture === 'laugh') {
                this.creature.laugh(hold);
                return;
            }

            if (gesture === 'shake') {
                this.creature.shake();
            }

            if (mood) {
                this.creature.setMood(mood, hold);
            }
        });
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

    /**
     * How far something fixed to the bottom edge reaches up into the corner the widget sits in.
     *
     * **Corrected, 2026-08-21, measured on the deployed shop.** This returned 0 while the consent bar
     * covered 37 of the orb's 60 pixels — the exact defect the offset was written to prevent, back
     * again. Two mistakes, both about which "bottom" is being talked about:
     *
     * - The test was `rect.bottom >= window.innerHeight - 4`. `window.innerHeight` **includes** the
     *   horizontal scrollbar; an element fixed to `bottom: 0` sits above it. Live numbers: the bar's
     *   bottom was 905 and `innerHeight` was 920, so a bar flush against the bottom edge failed a
     *   test asking whether it was flush against the bottom edge. `documentElement.clientHeight` is
     *   the layout viewport that `position: fixed` actually resolves against, and it read 905.
     * - The offset was the bar's own height. That is only the right number when the bar is flush;
     *   a bar with any gap beneath it needs the distance from the viewport's bottom up to its top,
     *   which is what is measured now and reduces to the height in the flush case.
     */
    _obstructionHeight() {
        const bar = this._findObstruction();

        if (!bar) {
            return 0;
        }

        const rect = bar.getBoundingClientRect();

        if (rect.height === 0) {
            return 0;
        }

        // The viewport `position: fixed` resolves against — scrollbars excluded.
        const viewportBottom = document.documentElement.clientHeight;

        // Only count something that actually reaches the bottom edge. A consent notice rendered
        // inline higher up the page is not in our way. The tolerance is generous on purpose: the
        // tight one is what let a fifteen-pixel scrollbar disable this entirely.
        if (rect.bottom < viewportBottom - OBSTRUCTION_BOTTOM_TOLERANCE) {
            return 0;
        }

        // How high the bar reaches, not how tall it is. Identical for a flush bar, correct for one
        // with a gap beneath it.
        return Math.round(Math.max(0, viewportBottom - rect.top)) + OBSTRUCTION_GAP;
    }

    /**
     * Discoverability without nagging: once per session, and never over an already-open panel.
     *
     * The creature turns to camera as it speaks — a glance is what makes a speech bubble read as
     * something the character said rather than a tooltip that appeared.
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
            this.creature.setMood('happy', NUDGE_VISIBLE_MS);
            this.creature.jump();
            window.setTimeout(() => this._hideNudge(), NUDGE_VISIBLE_MS);
        }, NUDGE_DELAY_MS);
    }

    _hideNudge() {
        if (this.nudge) {
            this.nudge.hidden = true;
        }
    }
}
