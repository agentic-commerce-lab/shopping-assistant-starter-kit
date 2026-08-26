/*
 * The wait.
 *
 * A real turn against the live shop was measured at **18.8 seconds**, so this is the single most
 * important element in the widget. A two-second loop played ten times is where "cute" goes to die, so
 * the indicator is *phased*: the copy changes as the wait goes on, and what a shopper perceives is
 * state changing — which reads as progress.
 *
 * **The copy deliberately claims nothing about what the server is doing.** Trace events are persisted
 * once, after the run completes, so there is no progress signal to read — a line like "searching the
 * catalogue…" would be invented. That would put an unbacked claim about server work into a product
 * whose entire thesis is that the interface never states what the server did not produce. So the copy
 * only ever says how long it has been, which is something we actually know.
 *
 * ## Two indicators, because there are two entry points
 *
 * The creature gets the creature: a face that changes expression through the wait, on a body that
 * wakes, searches, bobs and settles. Someone who chose the character chose the personality.
 *
 * The chat icon gets **three dots**. The neutral entry point is a flat mark that holds still, and
 * giving it a shaded sphere with eyes for nineteen seconds is the widget disagreeing with itself
 * about what it is — a merchant who picked "neutral" did not pick a face that only appears while
 * they wait. The dots also keep **one steady rhythm** rather than the creature's four acts: an
 * indicator that performs an act structure is not a neutral indicator. The phase copy still changes
 * on both, because that is the part carrying information rather than personality.
 *
 * `_icons.scss` already reserved this: it says three dots are a *state* and belong to the thinking
 * indicator rather than to the entry point. This is where that promise gets kept.
 */
import { buildFace } from './face';

/**
 * Thresholds in milliseconds. If a turn finishes early the later phases simply never fire, which is
 * correct rather than a missing feature.
 *
 * `phase` drives the creature's body motion from the stylesheet; `mood` drives its face. Both are
 * needed, because only the second one survives `prefers-reduced-motion` — someone who asked for less
 * motion still sees the creature settle from alert to patient across the wait, which is the part
 * that was carrying the information. The dots read neither: they hold one rhythm, and the copy
 * beside them is what marks the time.
 */
const PHASES = [
    { at: 0, phase: 'wake', mood: 'curious', copy: 'thinkingStart' },
    { at: 3000, phase: 'search', mood: 'curious', copy: 'thinkingStart' },
    { at: 8000, phase: 'waiting', mood: 'focus', copy: 'thinkingWaiting' },
    { at: 15_000, phase: 'patient', mood: 'sleepy', copy: 'thinkingPatient' },
];

const DOT_COUNT = 3;

export function createThinking(log, translations) {
    let el = null;
    let timers = [];

    /**
     * Read from the DOM rather than passed in, because the entry point is already on the widget root
     * as the attribute the whole stylesheet keys off. Threading it through the constructor would give
     * one fact two owners, and the one in the DOM is the one the CSS believes.
     */
    function entryPoint() {
        return log.closest('[data-entry-point]')?.dataset.entryPoint === 'creature'
            ? 'creature'
            : 'icon';
    }

    function start() {
        stop();

        const style = entryPoint();

        el = document.createElement('div');
        el.className = 'swag-assistant-thinking';
        el.dataset.variant = style;
        el.dataset.phase = 'wake';

        el.appendChild(style === 'creature' ? creatureStage(el) : dotsStage());

        log.appendChild(el);
        log.scrollTop = log.scrollHeight;

        say(PHASES[0].copy);

        timers = PHASES.map(({ at, phase, mood, copy }) => window.setTimeout(() => {
            if (!el) {
                return;
            }

            el.dataset.phase = phase;

            if (style === 'creature') {
                el.dataset.mood = mood;
            }

            if (at > 0) {
                say(copy);
            }
        }, at));
    }

    function creatureStage(host) {
        host.dataset.mood = 'curious';
        // Its own blink phase, so it does not blink in lockstep with the orb in the corner.
        host.style.setProperty('--swag-assistant-blink-delay', `${Math.floor(Math.random() * 3000)}ms`);

        const stage = document.createElement('span');
        stage.className = 'swag-assistant-thinking__stage';
        stage.setAttribute('aria-hidden', 'true');

        const orb = document.createElement('span');
        orb.className = 'swag-assistant-thinking__orb';
        orb.appendChild(buildFace());

        stage.appendChild(orb);

        return stage;
    }

    function dotsStage() {
        const stage = document.createElement('span');
        stage.className = 'swag-assistant-thinking__dots';
        stage.setAttribute('aria-hidden', 'true');

        for (let index = 0; index < DOT_COUNT; index += 1) {
            const dot = document.createElement('i');
            dot.className = 'swag-assistant-thinking__dot';
            stage.appendChild(dot);
        }

        return stage;
    }

    /**
     * Replaces the copy element rather than rewriting its text.
     *
     * The log is a `role="log"` with `aria-relevant="additions"`, which announces nodes that arrive —
     * so a fresh element is announced where a mutated `textContent` is at the mercy of the screen
     * reader's interpretation. Four announcements across ~19 seconds is the reassurance; getting them
     * announced is not optional, and it is also what re-plays the fade.
     */
    function say(key) {
        el.querySelector('.swag-assistant-thinking__copy')?.remove();

        const copy = document.createElement('span');
        copy.className = 'swag-assistant-thinking__copy';
        copy.textContent = translations[key] ?? '';
        el.appendChild(copy);
    }

    function stop() {
        timers.forEach((timer) => window.clearTimeout(timer));
        timers = [];
        el?.remove();
        el = null;
    }

    return { start, stop };
}
