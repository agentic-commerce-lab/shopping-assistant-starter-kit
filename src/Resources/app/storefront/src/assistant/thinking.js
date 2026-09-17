/*
 * The wait.
 *
 * A real turn against the live shop was measured at **18.8 seconds**, so this is the single most
 * important element in the widget. A two-second loop played ten times is where "cute" goes to die, so
 * the indicator is *phased*: the copy changes as the wait goes on, and what a shopper perceives is
 * state changing — which reads as progress.
 *
 * ## The copy is paced, not read — and that is a deliberate, bounded lie
 *
 * There is still no progress signal: trace events are persisted once, after the run completes. So the
 * steps below are **not** read from the server. They are the pipeline every turn actually walks,
 * played back on a timer.
 *
 * That is a claim about server work, which this file previously refused to make, so the bound matters:
 * measured over 223 real turns (`assistant-traces-2026-09-16.json`), **91 % ran a tool call, 99 %
 * reached grounding and render**. The ordered steps are therefore true of almost every turn — what is
 * invented is the *timing*, never the work. Live stage data would not fix that: the same measurement
 * shows the tool calls bunch into 0.1 s of each other and then the model thinks alone, so a truthful
 * indicator would flicker four times in 1.4 s and then freeze for 65 % of the wait (98 % at p90).
 *
 * **The creature may be playful; the icon may not.** A shopper who was given the neutral entry point
 * was not given a personality, which is the same argument that keeps the face off the dots. So the
 * long-wait pool splits: the icon keeps naming the pipeline, the creature is allowed to be charming
 * about it.
 *
 * What is still forbidden here, unchanged: naming a product, a count, a price, or anything the
 * shopper could mistake for a result. The steps describe *activity*, never *findings*.
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
 * indicator that performs an act structure is not a neutral indicator. The step copy still runs on
 * both, because that is the part carrying information rather than personality.
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

/**
 * The steps every turn really walks, in the order it walks them.
 *
 * Five, because the median turn is 3.7 s and each step costs roughly 1.5 s — a sixth would only ever
 * be seen by the tail. They stop at "putting it together" rather than naming a result, because the
 * indicator is removed the moment a result exists.
 */
const STEPS = ['stepReading', 'stepSearching', 'stepVariants', 'stepAvailability', 'stepComposing'];

/**
 * What plays once the five are spent — p90 is 13.7 s and the maximum measured turn was 74.5 s, so
 * something has to follow them or the wait ends on a frozen line, which is the failure this whole
 * change exists to remove.
 *
 * Shuffled rather than looped: a shopper who sees the same three in the same order twice has learned
 * the indicator is a loop, and a loop tells them nothing is happening.
 */
const STEPS_LONG = ['stepNarrowing', 'stepChecking', 'stepTidying'];

/** Creature only. See the entry-point argument in this file's header. */
const STEPS_LONG_CREATURE = ['stepRummaging', 'stepPondering'];

/**
 * Typing speeds, in milliseconds per character, and the pause between.
 *
 * Out is faster than in: deleting a line the shopper has already read is dead time, while typing one
 * they have not is the part that reads as work. ~22 characters therefore costs about 1.5 s all in.
 */
const TYPE_IN_MS = 22;
const TYPE_OUT_MS = 12;
const HOLD_MS = 800;

/** With motion reduced nothing types, so the whole budget goes to the one thing left: reading time. */
const HOLD_STILL_MS = 2200;

/** Fisher-Yates. `sort(() => Math.random() - 0.5)` is not a shuffle and biases the first position. */
function shuffle(keys) {
    const out = keys.slice();

    for (let i = out.length - 1; i > 0; i -= 1) {
        const j = Math.floor(Math.random() * (i + 1));

        [out[i], out[j]] = [out[j], out[i]];
    }

    return out;
}

export function createThinking(log, translations) {
    let el = null;
    let timers = [];
    let ticker = null;

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

        // The typed line is decoration for the eye only. A screen reader following it character by
        // character would announce forty times what `say()` announces three times, so the visual
        // element is hidden from the tree and the announcement keeps its own node below.
        const copy = document.createElement('span');
        copy.className = 'swag-assistant-thinking__copy';
        copy.setAttribute('aria-hidden', 'true');
        el.appendChild(copy);

        log.appendChild(el);
        log.scrollTop = log.scrollHeight;

        say(PHASES[0].copy);
        runSteps(style, copy);

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
        el.querySelector('.swag-assistant-thinking__sr')?.remove();

        const spoken = document.createElement('span');
        spoken.className = 'swag-assistant-thinking__sr';
        spoken.textContent = translations[key] ?? '';
        el.appendChild(spoken);
    }

    /**
     * Types each step in, holds it, types it out, then takes the next one.
     *
     * A chained timeout rather than an interval: the cadence depends on the length of the line being
     * typed, and an interval that does not know that drifts out of step with the text it is driving.
     */
    function runSteps(style, copy) {
        const still = window.matchMedia?.('(prefers-reduced-motion: reduce)').matches ?? false;
        const pool = STEPS_LONG.concat(style === 'creature' ? STEPS_LONG_CREATURE : []);
        const queue = STEPS.slice();
        let spare = [];

        function nextText() {
            if (queue.length === 0) {
                if (spare.length === 0) {
                    spare = shuffle(pool);
                }

                queue.push(spare.shift());
            }

            return translations[queue.shift()] ?? '';
        }

        function at(delay, step) {
            ticker = window.setTimeout(() => {
                if (el) {
                    step();
                }
            }, delay);
        }

        function type(text, index, done) {
            copy.textContent = text.slice(0, index);

            if (index > text.length) {
                at(HOLD_MS, done);

                return;
            }

            at(TYPE_IN_MS, () => type(text, index + 1, done));
        }

        function untype(text, index, done) {
            copy.textContent = text.slice(0, index);

            if (index === 0) {
                done();

                return;
            }

            at(TYPE_OUT_MS, () => untype(text, index - 1, done));
        }

        function step() {
            const text = nextText();

            if (text === '') {
                return;
            }

            if (still) {
                copy.textContent = text;
                at(HOLD_STILL_MS, step);

                return;
            }

            type(text, 0, () => untype(text, text.length, step));
        }

        step();
    }

    function stop() {
        timers.forEach((timer) => window.clearTimeout(timer));
        timers = [];
        window.clearTimeout(ticker);
        ticker = null;
        el?.remove();
        el = null;
    }

    return { start, stop };
}
