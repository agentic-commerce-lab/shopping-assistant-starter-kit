/**
 * One conversation token per shopping context, not one token for the whole tab.
 *
 * The panel element carries `data-swag-assistant-context-key`, an opaque key naming the shopper's
 * storage slot as resolved on the server (see `swag_assistant_context_key()`). Before this module
 * existed, the widget kept a single token under one global `sessionStorage` key, so logging out and
 * back in — or switching customer accounts in the same tab — handed the *previous* shopper's token to
 * the *next* one. The server no longer trusts a presented token blindly (`resumeOrStart()` re-checks
 * it against the resolved context and starts fresh if it does not match), so a wrongly-scoped token
 * only ever costs a conversation, never breaks anything. But sharing one slot across every context
 * would still throw away the whole point of scoping conversations to a shopper, so each context gets
 * its own slot here.
 *
 * All tokens live together under one `sessionStorage` key, `swagAssistantTokens`, as a JSON object
 * keyed by context key — not one `sessionStorage` key per context — because `sessionStorage` has no
 * "list keys matching a prefix" operation, and a single JSON blob is simpler to reason about than
 * scanning `sessionStorage.length` for stragglers.
 *
 * An empty context key (the Twig function degrades to `''` rather than throwing when no
 * sales-channel context exists) is used as-is, as one more entry in the map. It is not special-cased
 * to "no storage": a shopper genuinely browsing with no resolvable context still deserves a slot that
 * survives a page navigation within the same tab, and every shopper in that situation shares it —
 * which is only as wrong as the situation the server already tolerates for a stale or foreign token,
 * namely a conversation restart, never a leak of someone else's history (the server re-validates the
 * token against the actual context on every read).
 *
 * Every access is wrapped in try/catch, and the *acquisition* of the storage object is wrapped along
 * with it, not just the calls made on it. In some browsers and embedded contexts the property getter
 * `window.sessionStorage` itself throws — before `getItem`/`setItem`/`removeItem` is ever reached —
 * so `storage` is taken as a thunk (`getStorage()`) called freshly inside each try block rather than
 * a value resolved once by the caller. A widget that breaks the storefront page it is embedded in is
 * a worse outcome than a widget that simply forgets the conversation between turns.
 */

const MAP_KEY = 'swagAssistantTokens';

/** The key the widget used before contexts existed. Migrated once, then removed. */
const LEGACY_KEY = 'swagAssistantToken';

/**
 * @param {Function} getStorage - see {@see createTokenStore}.
 * @returns {Record<string, string>} the token map, or `{}` for "absent, corrupt, or unreadable" —
 *   those three are indistinguishable to a caller and are handled identically: start empty.
 */
function readMap(getStorage) {
    try {
        // `getStorage()` is inside the same try as `getItem`: acquiring the storage object can throw
        // exactly as reading from it can, and both degrade to the same empty result.
        const raw = getStorage().getItem(MAP_KEY);

        if (!raw) {
            return {};
        }

        const parsed = JSON.parse(raw);

        // A string that parses but is not an object (a bare number, `null`, an array) is exactly as
        // unusable as one that does not parse at all — discard it the same way rather than letting it
        // reach `map[contextKey]` and produce nonsense.
        if (typeof parsed !== 'object' || parsed === null || Array.isArray(parsed)) {
            return {};
        }

        return parsed;
    } catch {
        return {};
    }
}

function writeMap(getStorage, map) {
    try {
        getStorage().setItem(MAP_KEY, JSON.stringify(map));
    } catch {
        // Private browsing and full quotas both throw here, and so does acquiring `storage` itself in
        // some embedded contexts. Losing the token is the correct degradation — the next turn starts a
        // fresh conversation — not an exception on every send.
    }
}

/**
 * @param {Function} getStorage - returns anything `sessionStorage`-shaped
 *   (`{getItem, setItem, removeItem}`) each time it is called; a thunk rather than a resolved value so
 *   this module — not its caller — owns the hazard of *acquiring* storage throwing, not only the
 *   hazard of using it. In production this is `() => window.sessionStorage`. A test passes a thunk
 *   returning a plain object, or one that itself throws to stand in for the private-window case.
 * @param {string} contextKey - the panel's `data-swag-assistant-context-key`, possibly `''`.
 */
export function createTokenStore(getStorage, contextKey) {
    return {
        /**
         * Migrates the legacy single token exactly once, on the first read after this module shipped:
         * if the old key holds a string and this context's slot is still empty, the value moves into
         * the slot and the old key is removed. Adopting it into the *current* context even though it
         * was written under no particular context is safe precisely because the server re-validates —
         * worst case it is foreign and yields a fresh conversation, same as any other stale token.
         */
        get() {
            const map = readMap(getStorage);

            if (typeof map[contextKey] === 'string') {
                return map[contextKey];
            }

            let legacy;

            try {
                legacy = getStorage().getItem(LEGACY_KEY);
            } catch {
                legacy = null;
            }

            if (typeof legacy !== 'string') {
                return null;
            }

            map[contextKey] = legacy;
            writeMap(getStorage, map);

            try {
                getStorage().removeItem(LEGACY_KEY);
            } catch {
                // Leaving the legacy key behind is harmless — the map now takes priority — so a
                // throw here must not undo the adoption that already succeeded above.
            }

            return legacy;
        },

        set(token) {
            const map = readMap(getStorage);

            map[contextKey] = token;
            writeMap(getStorage, map);
        },

        /** Drops only this context's slot. Every other context's token is untouched. */
        clear() {
            const map = readMap(getStorage);

            delete map[contextKey];
            writeMap(getStorage, map);
        },
    };
}
