/*
 * The only place that talks to the server.
 *
 * Errors carry the HTTP status, because a shopper must be told three situations apart: the shop has
 * no model configured (503), their message was rejected (400), and the request never arrived at all.
 * A single "something went wrong" would collapse all three into the least useful one.
 */

/**
 * `CardIdList::MAX_IDS`. The server caps one request at this many ids and **drops the rest
 * silently** — it returns 12 cards for 15 ids with no indication that three are missing.
 *
 * That cap is right: `/assistant/cards` is public and does one catalogue lookup per id, so an
 * unbounded list is an unbounded query. What was wrong was asking past it. A transcript is capped at
 * 20 turns, and a turn can render a shortlist, so the union across a real conversation goes past 12
 * routinely — measured live: 10 turns, 15 distinct cards, and the three newest simply vanished from
 * the panel on the next page load. Batching respects the server's per-request bound instead of
 * raising it.
 */
const MAX_IDS_PER_REQUEST = 12;

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
     *
     * **Asked for in batches**, because the endpoint's cap is a silent truncation rather than an
     * error: one request for 15 ids returns the first 12 and says nothing about the other three. See
     * `MAX_IDS_PER_REQUEST`. Batches go out together — they are independent reads, and serialising
     * them would make a long transcript's panel open one round trip at a time.
     *
     * A batch that fails contributes nothing rather than failing the whole re-hydration: some cards
     * beat none, and the caller already renders only what it receives.
     */
    async function cards(ids) {
        if (!Array.isArray(ids) || ids.length === 0) {
            return new Map();
        }

        const batches = [];
        for (let offset = 0; offset < ids.length; offset += MAX_IDS_PER_REQUEST) {
            batches.push(ids.slice(offset, offset + MAX_IDS_PER_REQUEST));
        }

        const results = await Promise.all(batches.map(fetchCardBatch));

        return new Map(results.flat().map((card) => [card.id, card]));
    }

    /**
     * @param {Array<string>} batch
     * @returns {Promise<Array<object>>} the cards this batch resolved, or none if it failed
     */
    async function fetchCardBatch(batch) {
        try {
            const response = await fetch(`${cardsUrl}?ids=${encodeURIComponent(batch.join(','))}`, {
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
            });

            if (!response.ok) {
                return [];
            }

            const payload = await response.json();

            return payload.cards ?? [];
        } catch {
            // Re-hydration is a convenience; failing it must not stop the panel from opening.
            return [];
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
