/**
 * Whether the panel was open, so a page navigation does not close it.
 *
 * `sessionStorage`, like the conversation token and for the same reason: it belongs to one tab, and a
 * new tab should start closed. It holds a bare flag and nothing about the shopper. Every access,
 * including acquiring the storage object, sits inside the try — a widget that throws on a private
 * window is worse than one that forgets it was open.
 */

const KEY = 'swagAssistantPanelOpen';

/**
 * @param {Function} getStorage - a thunk returning `sessionStorage`-shaped storage; see `token-store.js`.
 */
export function createOpenState(getStorage) {
    return {
        wasOpen() {
            try {
                return getStorage().getItem(KEY) === '1';
            } catch {
                return false;
            }
        },

        set(open) {
            try {
                if (open) {
                    getStorage().setItem(KEY, '1');
                } else {
                    getStorage().removeItem(KEY);
                }
            } catch {
                // Forgetting is the correct degradation; failing to open or close is not.
            }
        },
    };
}
