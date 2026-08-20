/*
 * The wait.
 *
 * A real turn against the live shop was measured at **18.8 seconds**, so this is the single most
 * important element in the widget. A two-second loop played ten times is where "cute" goes to die, so
 * the indicator is *phased*: the creature changes what it is doing, the copy changes with it, and what
 * a shopper perceives is state changing — which reads as progress.
 *
 * **The copy deliberately claims nothing about what the server is doing.** Trace events are persisted
 * once, after the run completes, so there is no progress signal to read — a line like "searching the
 * catalogue…" would be invented. That would put an unbacked claim about server work into a product
 * whose entire thesis is that the interface never states what the server did not produce. So the copy
 * only ever says how long it has been, which is something we actually know.
 */
import { buildFace } from './face';

/**
 * Thresholds in milliseconds. If a turn finishes early the later phases simply never fire, which is
 * correct rather than a missing feature.
 *
 * `phase` drives the body motion from the stylesheet; `mood` drives the face. Both are needed,
 * because only the second one survives `prefers-reduced-motion` — someone who asked for less motion
 * still sees the creature settle from alert to patient across the wait, which is the part that was
 * carrying the information.
 */
const PHASES = [
    { at: 0, phase: 'wake', mood: 'curious', copy: 'thinkingStart' },
    { at: 3000, phase: 'search', mood: 'curious', copy: 'thinkingStart' },
    { at: 8000, phase: 'waiting', mood: 'focus', copy: 'thinkingWaiting' },
    { at: 15_000, phase: 'patient', mood: 'sleepy', copy: 'thinkingPatient' },
];

export function createThinking(log, translations) {
    let el = null;
    let timers = [];

    function start() {
        stop();

        el = document.createElement('div');
        el.className = 'swag-assistant-thinking';
        el.dataset.phase = 'wake';
        el.dataset.mood = 'curious';
        // Its own blink phase, so it does not blink in lockstep with the orb in the corner.
        el.style.setProperty('--swag-assistant-blink-delay', `${Math.floor(Math.random() * 3000)}ms`);

        const stage = document.createElement('span');
        stage.className = 'swag-assistant-thinking__stage';
        stage.setAttribute('aria-hidden', 'true');

        const orb = document.createElement('span');
        orb.className = 'swag-assistant-thinking__orb';
        orb.appendChild(buildFace());

        const sweep = document.createElement('span');
        sweep.className = 'swag-assistant-thinking__sweep';

        stage.appendChild(orb);
        stage.appendChild(sweep);
        el.appendChild(stage);

        log.appendChild(el);
        log.scrollTop = log.scrollHeight;

        say(PHASES[0].copy);

        timers = PHASES.map(({ at, phase, mood, copy }) => window.setTimeout(() => {
            if (!el) {
                return;
            }

            el.dataset.phase = phase;
            el.dataset.mood = mood;

            if (at > 0) {
                say(copy);
            }
        }, at));
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
