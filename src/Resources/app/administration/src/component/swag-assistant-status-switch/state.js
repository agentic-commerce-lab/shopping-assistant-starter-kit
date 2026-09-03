/*
 * Which of three things the status card says, kept out of the component so it can be tested without
 * a browser — the same reasoning the trace module's `phases.js` and the shop-info module's
 * `requests.js` give for their own extraction.
 */

/**
 * The card's state, as a snippet suffix.
 *
 * Three states, because the card used to have two and one of them was a lie. `assistantEnabled`
 * defaults to on, so a shop with no model configured reported "Running" — with a green dot and
 * "each reply spends model credit on your account" — while `/assistant/chat` answered 503 and the
 * storefront rendered no orb. Measured 2026-09-03.
 *
 * `Stopped` wins over `Unconfigured` when both are true. A merchant who switched the assistant off
 * has said the more decisive thing about it, and reporting a missing model to someone who has
 * already stopped it answers a question they did not ask.
 *
 * A `configured` of `null` means the check could not be made, and reads as `Running`: the endpoint
 * behind it is a diagnostic, and a diagnostic that cannot run must not invent a worse state than
 * the one the merchant actually configured. Same rule as the shop-info screen's store status, which
 * fails silently rather than taking a working form down.
 *
 * @param {boolean} enabled `assistantEnabled`; absent upstream means true
 * @param {boolean|null} configured whether a model is set anywhere, or null if unknown
 * @returns {'Running'|'Stopped'|'Unconfigured'}
 */
export function statusState(enabled, configured) {
    if (enabled === false) {
        return 'Stopped';
    }

    return configured === false ? 'Unconfigured' : 'Running';
}
