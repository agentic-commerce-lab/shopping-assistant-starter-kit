/*
 * A shopper-resizable panel.
 *
 * Width and height, desktop only. On a phone the panel is a full-screen sheet (`inset: 0`), and
 * resizing something that already fills the screen means nothing — so the handle is not rendered
 * there at all rather than rendered and ignored.
 *
 * Growth is up and to the left. The panel is anchored to the bottom-right by the orb it opens from,
 * so a handle that grew it downward would push it off the screen.
 */

/** Narrower than this and a product card cannot lay out; wider and it stops being a panel. */
export const MIN_WIDTH = 360;

export const MAX_WIDTH = 720;

/** Below this the header, one message and the composer no longer fit. */
export const MIN_HEIGHT = 320;

export const MAX_HEIGHT = 900;

/**
 * Breathing room above the panel, and **only** that.
 *
 * It was 112 and stood for the whole distance between the panel and the viewport edges, which is
 * the bug this constant caused: the panel is not anchored to the bottom of the window but to the
 * orb, which is itself pushed up by whatever obstructs the corner — the cookie bar, measured at
 * runtime. On a 720px window with that bar the panel's own top landed at **-51px**, putting the
 * header, the reset button and the close button above the screen edge with no way to reach them.
 *
 * The distance below the panel is now measured rather than assumed — see {@see availableHeight} —
 * so this is what is left over at the top.
 */
const VIEWPORT_MARGIN = 16;

/**
 * The size the panel opens at when `localStorage` holds nothing.
 *
 * **Exported because the stylesheet owns the same two numbers**, and it has to: the panel must be
 * correctly sized on first paint, before the lazily-loaded chunk that imports this file has run. So
 * `_tokens.scss` carries the width and `_panel.scss` the height ceiling, and
 * `tests/js/panel-defaults.test.js` reads both back to check they still agree with these. Change one
 * and that test names the other.
 *
 * Raised from 420x640 on 2026-08-31: a full reply did not fit, so reading one answer meant scrolling
 * the log. The viewport clamp in `_panel.scss` is unchanged and still wins, so a taller ceiling
 * cannot push the panel off a short screen.
 */
export const DEFAULT_WIDTH = 480;

export const DEFAULT_HEIGHT = 760;

/**
 * A size the panel can actually be, given the window it is in.
 *
 * The viewport is the harder constraint in both directions: a panel wider than the window is a
 * horizontal scrollbar on the whole page, and one taller than the window cannot be closed. So the
 * floor yields to it — `Math.min` is applied last on purpose.
 *
 * Non-finite input yields the default rather than `NaN`. This is read back from `localStorage`, which
 * is a string store anyone can edit, and a `NaN` width is a panel with no size at all.
 */
export function clampSize(size, viewport) {
    const width = Number(size?.width);
    const height = Number(size?.height);

    return {
        width: fit(width, DEFAULT_WIDTH, MIN_WIDTH, MAX_WIDTH, viewport?.width),
        height: fit(height, DEFAULT_HEIGHT, MIN_HEIGHT, MAX_HEIGHT, viewport?.height),
    };
}

/**
 * How much vertical room the panel actually has, given the gap beneath it.
 *
 * The panel is anchored above the orb, and the orb rides on `--swag-assistant-obstruction`, so that
 * gap is not a constant anyone can write down. Exported because it is the one piece of this
 * arithmetic that can be wrong in a way nobody sees until a control is off-screen.
 *
 * @param {number} innerHeight window.innerHeight
 * @param {number} gapBelow    distance from the viewport's bottom edge to the panel's own
 */
export function availableHeight(innerHeight, gapBelow) {
    const below = Number.isFinite(gapBelow) ? Math.max(0, gapBelow) : 0;

    return Math.max(0, innerHeight - below);
}

/**
 * The gap between the viewport's bottom edge and the panel's, from the two CSS offsets that produce
 * it: the root's (the orb's inset plus whatever obstructs the corner) and the panel's own.
 *
 * **Read from computed style, never from `getBoundingClientRect()`.** The panel opens with a `scale`
 * transform and `restoreSize` runs during it, so a rect read there is the *scaled* one — measured:
 * it produced a ceiling 11px too tall, which then corrected itself mid-drag and made a width drag
 * change the height. These two values are static lengths that no transform touches.
 */
export function gapBelowPanel(panel) {
    const root = panel?.offsetParent ?? panel?.parentElement;

    if (!panel || !root) {
        return 0;
    }

    const rootBottom = Number.parseFloat(getComputedStyle(root).bottom);
    const panelBottom = Number.parseFloat(getComputedStyle(panel).bottom);

    return (Number.isFinite(rootBottom) ? rootBottom : 0) + (Number.isFinite(panelBottom) ? panelBottom : 0);
}

function fit(value, fallback, min, max, available) {
    const wanted = Number.isFinite(value) ? value : fallback;
    const ceiling = Number.isFinite(available) ? Math.min(max, available - VIEWPORT_MARGIN) : max;

    // Floor first, then ceiling: on a window too small for the floor, the window wins.
    return Math.min(Math.max(wanted, min), Math.max(ceiling, 0));
}

/**
 * Makes `handle` resize `panel`, calling `onCommit` once per gesture.
 *
 * `onCommit` fires on release rather than per frame: persistence is one write per drag, not one per
 * pointer event, and the caller owns where it is written.
 *
 * Returns a teardown function.
 */
export function attachResize(panel, handles, { onCommit } = {}) {
    const grips = [...(handles ?? [])].filter(Boolean);

    if (!panel || grips.length === 0) {
        return () => {};
    }

    let start = null;

    // The height the panel may occupy, not the height of the window: the gap below it belongs to
    // the orb and to whatever pushed the orb up, and counting it as usable is what put the header
    // off-screen.
    const viewport = () => ({
        width: window.innerWidth,
        height: availableHeight(window.innerHeight, gapBelowPanel(panel)),
    });

    const apply = (size) => {
        const fitted = clampSize(size, viewport());
        // Written in px rather than through a transform: this is a real layout change, and the panel's
        // own content has to reflow into it. There is no transition on these two properties, so a drag
        // cannot start one and lag the cursor.
        panel.style.width = `${fitted.width}px`;
        panel.style.height = `${fitted.height}px`;
        // `_panel.scss` caps the panel too, and that cap silently won over an inline height —
        // measured: dragging up grew nothing at all. Once a shopper has picked a height, that choice
        // is the constraint; the viewport guarantee the cap provided is enforced by `clampSize`,
        // which is the better place for it **as long as it is given the real available height**.
        panel.style.maxHeight = `${fitted.height}px`;

        return fitted;
    };

    const onPointerDown = (event) => {
        const axis = event.currentTarget.dataset.swagAssistantResize;

        // `offset*`, not `getBoundingClientRect()`. The panel opens with a `scale` transform, and a
        // rect measured mid-transition is the *scaled* size — grabbing an edge while it was still
        // animating started the drag from a size the panel was never going to keep, and the panel
        // jumped by the difference. Measured at 28px on a 607px panel.
        start = {
            x: event.clientX,
            y: event.clientY,
            width: panel.offsetWidth,
            height: panel.offsetHeight,
            axis,
        };

        // Capture, so a fast drag that leaves an 8px strip keeps resizing instead of stopping dead.
        event.currentTarget.setPointerCapture(event.pointerId);
        event.preventDefault();
    };

    const onPointerMove = (event) => {
        if (start === null) {
            return;
        }

        // The axis is the grip's, so a left-edge drag cannot change the height by accident — which is
        // what a window frame does, and what makes a one-dimensional adjustment feel deliberate.
        const wantsWidth = start.axis === 'width' || start.axis === 'both';
        const wantsHeight = start.axis === 'height' || start.axis === 'both';

        apply({
            width: wantsWidth ? start.width + (start.x - event.clientX) : start.width,
            height: wantsHeight ? start.height + (start.y - event.clientY) : start.height,
        });
    };

    const onPointerUp = (event) => {
        if (start === null) {
            return;
        }

        start = null;

        if (event.currentTarget.hasPointerCapture?.(event.pointerId)) {
            event.currentTarget.releasePointerCapture(event.pointerId);
        }

        onCommit?.({ width: panel.offsetWidth, height: panel.offsetHeight });
    };

    /*
     * Arrow keys, because a pointer-only handle is one a keyboard user cannot reach — and every other
     * control in this widget is operable. 24px a step: coarse enough to cross the range in a few
     * presses, fine enough to land somewhere deliberate.
     */
    const onKeyDown = (event) => {
        const step = { ArrowLeft: [24, 0], ArrowRight: [-24, 0], ArrowUp: [0, 24], ArrowDown: [0, -24] }[event.key];

        if (step === undefined) {
            return;
        }

        event.preventDefault();
        const committed = apply({ width: panel.offsetWidth + step[0], height: panel.offsetHeight + step[1] });
        onCommit?.(committed);
    };

    const listeners = [
        ['pointerdown', onPointerDown],
        ['pointermove', onPointerMove],
        ['pointerup', onPointerUp],
        ['pointercancel', onPointerUp],
        ['keydown', onKeyDown],
    ];

    grips.forEach((grip) => listeners.forEach(([type, fn]) => grip.addEventListener(type, fn)));

    return () => {
        grips.forEach((grip) => listeners.forEach(([type, fn]) => grip.removeEventListener(type, fn)));
    };
}

/** The stored size, clamped to the window it is about to be applied in. */
export function restoreSize(panel, stored) {
    if (!panel) {
        return;
    }

    const size = clampSize(stored, {
        width: window.innerWidth,
        height: availableHeight(window.innerHeight, gapBelowPanel(panel)),
    });
    panel.style.width = `${size.width}px`;
    panel.style.height = `${size.height}px`;
    panel.style.maxHeight = `${size.height}px`;
}
