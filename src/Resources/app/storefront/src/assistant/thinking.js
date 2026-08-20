/*
 * The wait.
 *
 * A real turn against the live shop was measured at **18.8 seconds**, so this is the single most
 * important element in the widget. A two-second loop played ten times is where "cute" goes to die,
 * so the indicator is *phased*: the orb changes what it is doing, and the copy changes with it. What
 * a shopper perceives is state changing, which reads as progress.
 *
 * **The copy deliberately claims nothing about what the server is doing.** Trace events are
 * persisted once, after the run completes, so there is no progress signal to read — a line like
 * "searching the catalogue…" would be invented. That would put an unbacked claim about server work
 * into a product whose entire thesis is that the interface never states what the server did not
 * produce. So the copy only ever says how long it has been, which is something we actually know.
 */

/**
 * Thresholds in milliseconds. If a turn finishes early the later phases simply never fire, which is
 * correct rather than a missing feature.
 */
const PHASES = [
    { at: 0, phase: 'wake', copy: 'thinkingStart' },
    { at: 3000, phase: 'search', copy: 'thinkingStart' },
    { at: 8000, phase: 'waiting', copy: 'thinkingWaiting' },
    { at: 15000, phase: 'patient', copy: 'thinkingPatient' },
];

export function createThinking(log, translations) {
    let el = null;
    let copyEl = null;
    let timers = [];

    function start() {
        stop();

        el = document.createElement('div');
        el.className = 'swag-assistant-thinking';
        el.dataset.phase = 'wake';

        const orb = document.createElement('span');
        orb.className = 'swag-assistant-thinking__orb';
        orb.setAttribute('aria-hidden', 'true');

        const face = document.createElement('span');
        face.className = 'swag-assistant-thinking__face';
        face.appendChild(eye());
        face.appendChild(eye());
        orb.appendChild(face);

        const sweep = document.createElement('span');
        sweep.className = 'swag-assistant-thinking__sweep';
        sweep.setAttribute('aria-hidden', 'true');

        copyEl = document.createElement('span');
        copyEl.className = 'swag-assistant-thinking__copy';

        el.appendChild(orb);
        el.appendChild(sweep);
        el.appendChild(copyEl);

        log.appendChild(el);
        log.scrollTop = log.scrollHeight;

        // Four aria-live updates across ~19s, not a stream. The copy changes are the reassurance,
        // and they must survive prefers-reduced-motion — only the motion is optional.
        timers = PHASES.map(({ at, phase, copy }) => window.setTimeout(() => {
            if (!el) {
                return;
            }

            el.dataset.phase = phase;
            copyEl.textContent = translations[copy] ?? '';
        }, at));
    }

    function stop() {
        timers.forEach((timer) => window.clearTimeout(timer));
        timers = [];
        el?.remove();
        el = null;
        copyEl = null;
    }

    return { start, stop };
}

function eye() {
    const el = document.createElement('span');
    el.className = 'swag-assistant-thinking__eye';

    return el;
}
