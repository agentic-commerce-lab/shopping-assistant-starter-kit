import { expect, test } from '@playwright/test';

/*
 * Does the assistant quote the price the shop quotes?
 *
 * This is the only layer that can answer that. The PHP suite proves the mapper picks the tier it was
 * told to pick; it cannot prove that a real Shopware populates `calculatedPrices` the way we think,
 * because the fixture gateway builds its cards from JSON and never runs the price calculator. So
 * this spec asks the two sources the shopper can compare and requires them to agree:
 *
 * - `GET /assistant/cards?ids=…` — the plugin's own card endpoint, the exact production path
 *   (CardResolver → gateway → DalProductCardMapper), with no model involved.
 * - `GET /detail/{id}` — Shopware's own product page, read through the `itemprop="price"` metadata
 *   the buy widget emits.
 *
 * **No model call, so this costs nothing and takes seconds.** Unlike `widget.spec.js` it can be run
 * on every change, and it should be.
 *
 * ## Baseline, measured 2026-08-28 against the local demo shop, before the fix
 *
 * Six of six diverged, by between 1.7x and 4.8x:
 *
 * | product                                | page    | card   |
 * |----------------------------------------|---------|--------|
 * | Aerodynamic Bronze Just For Her        | 2216.66 | 578.09 |
 * | Aerodynamic Bronze LimberUp            |  954.22 | 437.71 |
 * | Aerodynamic Concrete Candy Palanquin   |  432.04 | 265.04 |
 * | Aerodynamic Concrete Collarit ImPetus  | 2966.33 | 612.15 |
 * | Aerodynamic Concrete PortGear          | 2008.09 | 434.73 |
 * | Aerodynamic Granite Lifestyle Tailoring| 1900.59 | 371.46 |
 *
 * The card was reading `calculatedPrice` — the product's own price at quantity one — while the shop
 * showed the advanced price that actually applies. See
 * `docs/superpowers/plans/2026-08-28-price-and-cart-truth.md`.
 *
 * ## Choosing products
 *
 * The defaults are ids from that shop, and they only prove anything where the product carries
 * advanced prices — for a product without them both sources read `calculatedPrice` and the test
 * passes for the wrong reason. Override for another shop:
 *
 * ```bash
 * PRICING_PRODUCT_IDS=aaaa…,bbbb… npx playwright test tests/e2e/pricing.spec.js
 * ```
 *
 * Find candidates in any Shopware database with:
 *
 * ```sql
 * SELECT LOWER(HEX(p.id)), pt.name
 * FROM product p
 * JOIN product_translation pt ON pt.product_id = p.id AND pt.product_version_id = p.version_id
 * JOIN product_visibility pv ON pv.product_id = p.id AND pv.product_version_id = p.version_id
 * WHERE p.active = 1 AND p.child_count = 0
 *   AND EXISTS (SELECT 1 FROM product_price pp
 *               WHERE pp.product_id = p.id AND pp.product_version_id = p.version_id)
 * GROUP BY p.id, pt.name LIMIT 6;
 * ```
 */

const DEFAULT_IDS = [
    '01a01b4f9baf73b0b11b98edf65b2ca7',
    '01a01b4f972772aea99a843cbc2aca2c',
    '01a01b4f9bb270cdae5f117369257b0f',
    '01a01b4f98f77217a29f3a2cc954c595',
    '01a01b4f981c70eabd51f14e553875ec',
    '01a01b4f99e270db9782b7b75bcb30e7',
];

const productIds = (process.env.PRICING_PRODUCT_IDS ?? DEFAULT_IDS.join(','))
    .split(',')
    .map((id) => id.trim())
    .filter((id) => /^[0-9a-f]{32}$/.test(id));

/**
 * The first price Shopware's buy widget states for this product.
 *
 * A graduated product emits one `itemprop="price"` per tier, in tier order, so the first is the one
 * that applies to the smallest order — which is what a card with no stated quantity must show.
 *
 * @param {string} html
 * @returns {number|null}
 */
function pagePrice(html) {
    const match = /itemprop="price"\s+content="([0-9.]+)"/.exec(html);

    return match ? Number.parseFloat(match[1]) : null;
}

test.describe('the card price agrees with the product page', () => {
    test('every sampled product quotes one price, not two', async ({ request }) => {
        test.skip(productIds.length === 0, 'PRICING_PRODUCT_IDS held no valid 32-hex ids.');

        const response = await request.get(`/assistant/cards?ids=${productIds.join(',')}`);
        expect(response.ok(), 'the card endpoint answered').toBeTruthy();

        const { cards } = await response.json();
        expect(cards.length, 'the shop returned a card for every sampled id').toBe(productIds.length);

        /** @type {Array<{name: string, card: number, page: number}>} */
        const disagreements = [];

        for (const card of cards) {
            const detail = await request.get(`/detail/${card.id}`);
            expect(detail.ok(), `the product page for ${card.id} answered`).toBeTruthy();

            const shown = pagePrice(await detail.text());

            // A product page with no price metadata tells us nothing either way — record it rather
            // than passing quietly, so a sample that has stopped proving anything is visible.
            expect(shown, `the product page for ${card.name} stated a price`).not.toBeNull();

            if (Math.abs(shown - card.price) >= 0.01) {
                disagreements.push({ name: card.name, card: card.price, page: shown });
            }
        }

        expect(
            disagreements,
            `the assistant quoted a price the shop contradicts:\n${disagreements
                .map((d) => `  ${d.name}: card ${d.card}, page ${d.page}`)
                .join('\n')}`,
        ).toEqual([]);
    });

    test('a card states the quantity its price assumes', async ({ request }) => {
        // The seeded product carries minPurchase 4, so its price is only obtainable at four units
        // and the card has to say so. Without `priceQuantity` a correct figure is still a
        // misleading one. See spec section 7.1.
        const seeded = '01a01b4f981c70eabd51f14e553875ec';
        test.skip(!productIds.includes(seeded), 'the seeded minimum-purchase product is not in this sample.');

        const response = await request.get(`/assistant/cards?ids=${seeded}`);
        const { cards } = await response.json();
        const card = cards[0];

        expect(card, 'the seeded product resolved to a card').toBeTruthy();
        expect(card.priceQuantity, 'the card states the quantity behind its price').toBe(4);
    });
});
