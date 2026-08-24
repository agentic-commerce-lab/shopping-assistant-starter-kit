/*
 * The creature.
 *
 * One module drives the assistant everywhere it appears: the orb in the corner, the avatar in the
 * panel header, and the bubble leading the thinking indicator. They are the same object, so they get
 * the same behaviour rather than three implementations that drift.
 *
 * **This module never styles anything.** It sets a `data-mood` string and two custom properties, and
 * the stylesheet owns what each of those looks like. That split is what makes the whole personality
 * survive `prefers-reduced-motion` intact: a mood changes the *shape* of the face, which is state, so
 * the creature still smiles and still squints with every animation switched off. Only the motion —
 * the hops, the squashes, the pointer tracking — is guarded, and it is guarded here, once, rather
 * than being undone with `!important` further down the stylesheet.
 */

/** The moods the stylesheet implements. Anything else is a typo, so it is refused rather than set. */
const MOODS = ['idle', 'happy', 'laugh', 'curious', 'wow', 'sleepy', 'focus'];

/** How far the face slides toward the pointer, as a fraction of the creature's size. */
const LOOK_REACH = 0.055;

/** Beyond this the pointer is no longer "near", and the creature stops tracking it. */
const LOOK_RANGE_PX = 620;

/** No pointer movement for this long and the eyes drift back to centre. */
const LOOK_RELEASE_MS = 2600;

/** Nothing at all for this long and the creature dozes off. */
const DOZE_AFTER_MS = 90_000;

const IDLE_BEAT_MIN_MS = 5200;
const IDLE_BEAT_MAX_MS = 11_000;

const reducedMotion = () => window.matchMedia('(prefers-reduced-motion: reduce)').matches;

/**
 * Pointer tracking is for pointers. On a touch screen there is no cursor to follow, and a
 * `pointermove` listener would only fire mid-tap — so the whole feature is skipped and the creature
 * keeps its idle beats and its tap reactions instead.
 */
const hasFinePointer = () => window.matchMedia('(hover: hover) and (pointer: fine)').matches;

/**
 * @param {HTMLElement} host element carrying `data-mood`; the face must be inside it
 * @param {{body?: HTMLElement, base?: string, track?: boolean, idle?: boolean}} options
 *        `body` is the element gestures transform — separate from `host` on the orb, where `host` is
 *        the `<button>` and must never move.
 */
export function createCreature(host, options = {}) {
    const { body = host, base = 'idle', track = false, idle = false } = options;

    let baseMood = base;
    let holdTimer = 0;
    let beatTimer = 0;
    let releaseTimer = 0;
    let dozeTimer = 0;
    let frame = 0;
    let pending = null;
    let cachedBox = null;
    let disposed = false;
    const cleanups = [];

    // Varies the blink *phase*, not whether it blinks. Without it every creature in every open tab
    // blinks in lockstep, which reads as a synchronised animation rather than as something alive.
    host.style.setProperty('--swag-assistant-blink-delay', `${Math.floor(Math.random() * 5000)}ms`);
    host.dataset.mood = baseMood;

    function setMood(mood, holdMs = 0) {
        if (disposed || !MOODS.includes(mood)) {
            return;
        }

        window.clearTimeout(holdTimer);
        host.dataset.mood = mood;

        if (holdMs > 0) {
            holdTimer = window.setTimeout(() => {
                host.dataset.mood = baseMood;
            }, holdMs);
        }
    }

    function setBaseMood(mood) {
        if (!MOODS.includes(mood)) {
            return;
        }

        baseMood = mood;
        window.clearTimeout(holdTimer);
        host.dataset.mood = mood;
    }

    /**
     * `composite: 'add'` matters more than it looks: the body already carries two CSS loops (the
     * float and the wobble), and a gesture that *replaced* their transform would snap the creature to
     * wherever the keyframe started. Adding to it instead means a hop composes on top of the float,
     * and when the hop ends the float is exactly where it would have been.
     */
    function gesture(keyframes, duration, easing = 'ease-in-out') {
        if (disposed || reducedMotion() || typeof body.animate !== 'function') {
            return null;
        }

        return body.animate(keyframes, { duration, easing, composite: 'add' });
    }

    /** Anticipate, launch, round out at the apex, land heavy, settle. Squash and stretch, in order. */
    function jump() {
        return gesture([
            { transform: 'scale(1, 1) translateY(0)', offset: 0 },
            { transform: 'scale(1.14, 0.82) translateY(3px)', offset: 0.18, easing: 'ease-out' },
            { transform: 'scale(0.88, 1.2) translateY(-13px)', offset: 0.44, easing: 'ease-out' },
            { transform: 'scale(1.03, 0.97) translateY(-15px)', offset: 0.58, easing: 'ease-in' },
            { transform: 'scale(1.16, 0.8) translateY(2px)', offset: 0.82, easing: 'ease-in' },
            { transform: 'scale(1, 1) translateY(0)', offset: 1 },
        ], 760);
    }

    /** Two quick bobs under a laughing face. Shallower than a jump: the creature is amused, not airborne. */
    function bounce() {
        return gesture([
            { transform: 'scale(1, 1) translateY(0)' },
            { transform: 'scale(0.96, 1.06) translateY(-5px)' },
            { transform: 'scale(1.06, 0.94) translateY(1px)' },
            { transform: 'scale(0.98, 1.03) translateY(-3px)' },
            { transform: 'scale(1, 1) translateY(0)' },
        ], 620);
    }

    /** A pop, for the moment the panel opens out of it. */
    function pop() {
        return gesture([
            { transform: 'scale(1)' },
            { transform: 'scale(0.84)' },
            { transform: 'scale(1.08)' },
            { transform: 'scale(1)' },
        ], 420, 'cubic-bezier(0.34, 1.56, 0.64, 1)');
    }

    /** A refusal. Horizontal, because that is the gesture for "no" almost everywhere. */
    function shake() {
        return gesture([
            { transform: 'translateX(0)' },
            { transform: 'translateX(-4px)' },
            { transform: 'translateX(4px)' },
            { transform: 'translateX(-2px)' },
            { transform: 'translateX(0)' },
        ], 340);
    }

    function laugh(holdMs = 1500) {
        setMood('laugh', holdMs);
        bounce();
    }

    /*
     * Looking.
     *
     * The box is cached rather than measured per frame. The creature is inside a `position: fixed`
     * corner, so its rect only changes when the viewport does or when the consent-bar offset shifts
     * it — and a `getBoundingClientRect()` on every pointer move is a forced layout read on every
     * pointer move, on every storefront page.
     */
    function box() {
        if (!cachedBox) {
            cachedBox = host.getBoundingClientRect();
        }

        return cachedBox;
    }

    function look(clientX, clientY) {
        const rect = box();

        if (rect.width === 0) {
            return;
        }

        const dx = clientX - (rect.left + rect.width / 2);
        const dy = clientY - (rect.top + rect.height / 2);
        const distance = Math.hypot(dx, dy);

        if (distance > LOOK_RANGE_PX) {
            recentre();
            return;
        }

        // Normalised, then scaled by the creature's own size, so a 34px avatar and a 60px orb move
        // proportionally rather than by the same absolute pixels.
        const reach = rect.width * LOOK_REACH;
        const unit = distance === 0 ? 0 : reach / distance;

        host.style.setProperty('--swag-assistant-look-x', `${(dx * unit).toFixed(2)}px`);
        host.style.setProperty('--swag-assistant-look-y', `${(dy * unit).toFixed(2)}px`);
    }

    function recentre() {
        host.style.setProperty('--swag-assistant-look-x', '0px');
        host.style.setProperty('--swag-assistant-look-y', '0px');
    }

    function onPointerMove(event) {
        wake();

        pending = event;

        // One write per frame at most, no matter how fast the pointer moves.
        if (frame === 0) {
            frame = window.requestAnimationFrame(() => {
                frame = 0;

                if (pending && !disposed) {
                    look(pending.clientX, pending.clientY);
                }
            });
        }

        window.clearTimeout(releaseTimer);
        releaseTimer = window.setTimeout(recentre, LOOK_RELEASE_MS);
    }

    /*
     * Idle beats.
     *
     * A creature that only reacts is a widget with a face. These are the moments it does something
     * unprompted — a glance, a hop, a grin — on a randomised interval so it never reads as a loop.
     *
     * Every beat is scheduled with a timer rather than driven by a frame loop, so an idle orb costs
     * the page nothing between beats.
     */
    const BEATS = [
        () => setMood('curious', 1500),
        () => setMood('happy', 1400),
        () => {
            setMood('happy', 1100);
            jump();
        },
        () => setMood('wow', 800),
    ];

    function scheduleBeat() {
        window.clearTimeout(beatTimer);

        const delay = IDLE_BEAT_MIN_MS + Math.random() * (IDLE_BEAT_MAX_MS - IDLE_BEAT_MIN_MS);

        beatTimer = window.setTimeout(() => {
            // Nothing loops while the tab is in the background: an animation nobody can see is pure
            // cost. `visibilitychange` below restarts the schedule when the tab comes back.
            if (!disposed && !document.hidden && host.dataset.mood === baseMood) {
                BEATS[Math.floor(Math.random() * BEATS.length)]();
            }

            scheduleBeat();
        }, delay);
    }

    function wake() {
        if (baseMood === 'sleepy') {
            setBaseMood('idle');
        }

        window.clearTimeout(dozeTimer);
        dozeTimer = window.setTimeout(() => setBaseMood('sleepy'), DOZE_AFTER_MS);
    }

    function on(target, type, handler, opts) {
        target.addEventListener(type, handler, opts);
        cleanups.push(() => target.removeEventListener(type, handler, opts));
    }

    function start() {
        if (track && hasFinePointer() && !reducedMotion()) {
            on(document, 'pointermove', onPointerMove, { passive: true });
        }

        // The cached rect stops being true when the viewport changes, and when the consent-bar offset
        // moves the whole corner.
        const invalidate = () => {
            cachedBox = null;
        };

        on(window, 'resize', invalidate, { passive: true });
        on(window, 'scroll', invalidate, { passive: true });

        if (idle) {
            wake();
            scheduleBeat();

            on(document, 'visibilitychange', () => {
                if (document.hidden) {
                    window.clearTimeout(beatTimer);
                    return;
                }

                scheduleBeat();
            });
        }
    }

    function destroy() {
        disposed = true;
        [holdTimer, beatTimer, releaseTimer, dozeTimer].forEach(window.clearTimeout);
        window.cancelAnimationFrame(frame);
        cleanups.forEach((off) => off());
    }

    start();

    return { setMood, setBaseMood, jump, bounce, pop, shake, laugh, recentre, destroy };
}

/**
 * The same interface, doing nothing.
 *
 * The neutral entry point is a mark, not a character: it does not float, breathe, hop, blink or
 * follow the pointer. Returned instead of `createCreature` so the orb plugin keeps one collaborator
 * with one shape — the alternative was optional-chaining fourteen call sites, which would have made
 * every one of them read as if the creature might be missing rather than as if it is deliberately
 * still.
 *
 * `recentre` and `destroy` are included because the plugin calls them on layout changes and teardown.
 */
export function createStillCreature() {
    const nothing = () => {};

    return {
        setMood: nothing,
        setBaseMood: nothing,
        jump: nothing,
        bounce: nothing,
        pop: nothing,
        shake: nothing,
        laugh: nothing,
        recentre: nothing,
        destroy: nothing,
    };
}
