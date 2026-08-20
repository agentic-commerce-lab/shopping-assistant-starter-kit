/*
 * The only place that talks to the server.
 *
 * Errors carry the HTTP status, because a shopper must be told three situations apart: the shop has
 * no model configured (503), their message was rejected (400), and the request never arrived at all.
 * A single "something went wrong" would collapse all three into the least useful one.
 */

/**
 * @param {{chatUrl: string, historyUrl: string, cardsUrl: string, cartUrl: string}} urls
 */
export function createTransport({ chatUrl, historyUrl, cardsUrl, cartUrl }) {
    async function send(message, token) {
        const response = await fetch(chatUrl, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: JSON.stringify(token ? { message, token } : { message }),
        });

        if (!response.ok) {
            throw statusError(`The assistant responded ${response.status}`, response.status);
        }

        return response.json();
    }

    /**
     * A missing or unknown token yields an empty history rather than an error: a shopper with a
     * stale sessionStorage entry should get a fresh conversation, not a failure.
     */
    async function history(token) {
        if (!token) {
            return { messages: [] };
        }

        try {
            const response = await fetch(`${historyUrl}?token=${encodeURIComponent(token)}`, {
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
            });

            if (!response.ok) {
                return { messages: [] };
            }

            return await response.json();
        } catch {
            // Re-hydration is a convenience. Failing it must never stop the panel from opening.
            return { messages: [] };
        }
    }

    /**
     * Re-renders the cards a stored conversation referred to.
     *
     * `history` returns ids only, on purpose: replaying stored figures would show a shopper numbers
     * that were true when they were written. So the cards are asked for fresh, and the response may
     * legitimately be **shorter** than the request — a product blocked or deleted since that turn is
     * omitted rather than faked.
     */
    async function cards(ids) {
        if (!Array.isArray(ids) || ids.length === 0) {
            return new Map();
        }

        try {
            const response = await fetch(`${cardsUrl}?ids=${encodeURIComponent(ids.join(','))}`, {
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
            });

            if (!response.ok) {
                return new Map();
            }

            const payload = await response.json();

            return new Map((payload.cards ?? []).map((card) => [card.id, card]));
        } catch {
            // Re-hydration is a convenience; failing it must not stop the panel from opening.
            return new Map();
        }
    }

    /**
     * Shopware's own cart route, with Shopware's own field names — the shape
     * `buy-widget-form.html.twig` posts. The shop keeps ownership of cart rules, prices and stock
     * reservation; this plugin adds no cart logic of its own.
     *
     * 6.7 has no CSRF layer, so no token is needed.
     */
    async function addToCart(productId, quantity = 1) {
        const body = new FormData();
        const field = (name, value) => body.append(`lineItems[${productId}][${name}]`, value);

        field('id', productId);
        field('referencedId', productId);
        field('type', 'product');
        field('quantity', String(quantity));
        field('stackable', '1');
        field('removable', '1');

        const response = await fetch(cartUrl, {
            method: 'POST',
            body,
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
        });

        if (!response.ok) {
            throw statusError(`The cart responded ${response.status}`, response.status);
        }

        return response;
    }

    return { send, history, cards, addToCart };
}

function statusError(message, status) {
    const error = new Error(message);
    error.status = status;

    return error;
}
