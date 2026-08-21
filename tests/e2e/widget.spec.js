import { expect, test } from '@playwright/test';

/*
 * End-to-end checks against a running shop.
 *
 * This is the only layer that can catch what actually breaks. Everything below it — the Twig gate,
 * the transcript codec, the id parser — is covered by PHPUnit; nothing below it can tell you that
 * the orb is hidden behind a cookie bar, that the panel never opened, or that a card rendered with
 * no price.
 *
 * There are deliberately **no unit tests for the rendering functions**. They would pass by restating
 * the code they test.
 */

const SHOP = process.env.SHOP_URL ?? 'http://127.0.0.1:8000';

/**
 * A real turn against a live model was measured at 16–19 seconds in isolation.
 *
 * 90, not 60. The suite fires three live turns, and **the server finishes a turn even when the client
 * has gone** — measured — so a test that navigated away leaves work in flight and the next model call
 * queues behind it. At 60 s the card assertion passed in isolation (27.5 s) and flaked in the full
 * run, which is the worst kind of green.
 */
const TURN_TIMEOUT = 90_000;

/**
 * `ChatRequest::MAX_MESSAGE_LENGTH`, mirrored in `panel.plugin.js`. Lowered from 2000 on
 * 2026-08-21: a stored turn is re-sent on every later turn while it stays in the history window, so
 * a long message is paid for up to eleven times rather than once.
 */
const LIMIT = 500;

const OVER_LIMIT = LIMIT + 1;

/**
 * **Required, not a preference.** Playwright refuses to click the orb while its `breathe` animation
 * runs — the element is never "stable" — so without this every click here fails on a timeout. It
 * works because the widget's `prefers-reduced-motion` support is real: the same media query that
 * makes the widget usable for someone who asked for less motion is what makes it testable.
 */
test.use({ reducedMotion: 'reduce' });

async function openPanel(page) {
    await page.goto(SHOP);
    // Each test starts a fresh conversation; sessionStorage is what ties a tab to one.
    await page.evaluate(() => window.sessionStorage.removeItem('swagAssistantToken'));
    await page.locator('[data-swag-assistant-orb]').click();
    await expect(page.locator('[data-swag-assistant-panel]')).toBeVisible();
}

test.describe('assistant widget', () => {
    test('the orb renders and opens the panel', async ({ page }) => {
        await page.goto(SHOP);

        const orb = page.locator('[data-swag-assistant-orb]');
        await expect(orb).toBeVisible();
        await expect(orb).toHaveAttribute('aria-expanded', 'false');

        await orb.click();

        await expect(page.locator('[data-swag-assistant-panel]')).toBeVisible();
        await expect(orb).toHaveAttribute('aria-expanded', 'true');
    });

    /**
     * The consent bar is fixed at z-index 1100 — above the widget's 1035 — so wherever they overlap
     * the bar wins, and the orb is what a shopper has to click.
     *
     * **This test used to be able to pass without testing anything.** It returned `false` for
     * "no bar found", which is the same value as "no overlap", so a run where the consent bar never
     * appeared was indistinguishable from a run where the offset worked. It did not appear: measured
     * on the deployed shop the bar covered 37 of the orb's 60 pixels while this was green. The
     * assertions below fail if the bar is missing, because a green result then means nothing.
     */
    test('the orb clears whatever else occupies the corner', async ({ page }) => {
        // A consent decision persists, and a decided bar is never rendered. Clear it first, or this
        // test measures an empty corner.
        await page.context().clearCookies();
        await page.goto(SHOP);

        const geometry = await page.evaluate(() => {
            const bar = document.querySelector('.cookie-permission-container');
            const orb = document.querySelector('.swag-assistant-orb');
            const root = document.querySelector('.swag-assistant');

            if (!bar || !orb || !root) {
                return { barPresent: false };
            }

            const a = bar.getBoundingClientRect();
            const b = orb.getBoundingClientRect();

            return {
                barPresent: a.height > 0,
                offset: getComputedStyle(root).getPropertyValue('--swag-assistant-obstruction').trim(),
                overlap: !(a.right < b.left || a.left > b.right || a.bottom < b.top || a.top > b.bottom),
            };
        });

        // The precondition, asserted rather than assumed.
        expect(geometry.barPresent).toBe(true);
        // The offset is the mechanism, and a measured zero is the shape the live bug took.
        expect(geometry.offset).not.toBe('0px');
        expect(geometry.overlap).toBe(false);
    });

    test('escape closes the panel and returns focus to the orb', async ({ page }) => {
        await openPanel(page);
        await page.keyboard.press('Escape');

        await expect(page.locator('[data-swag-assistant-panel]')).toBeHidden();
        await expect(page.locator('[data-swag-assistant-orb]')).toBeFocused();
    });

    test('tab stays inside the open panel', async ({ page }) => {
        await openPanel(page);

        // Focus starts in the composer. Tabbing off the last control must wrap, not escape into the
        // page's navigation mid-question.
        await page.locator('[data-swag-assistant-send]').focus();
        await page.keyboard.press('Tab');

        const stillInside = await page.evaluate(
            () => !!document.activeElement?.closest('[data-swag-assistant-panel]'),
        );

        expect(stillInside).toBe(true);
    });

    test('a real turn answers with a card carrying a price and a readable stock label', async ({ page }) => {
        await openPanel(page);
        await page.locator('[data-swag-assistant-input]').fill('show me the trail jersey in blue, size M');
        await page.locator('[data-swag-assistant-send]').click();

        // The wait must be visible immediately. A silent nineteen seconds is the failure the phased
        // indicator exists to prevent, so this asserts it appears before the answer does.
        await expect(page.locator('.swag-assistant-thinking')).toBeVisible();

        const card = page.locator('.swag-assistant-card').first();
        await expect(card).toBeVisible({ timeout: TURN_TIMEOUT });
        await expect(page.locator('.swag-assistant-thinking')).toHaveCount(0);

        await expect(card.locator('.swag-assistant-card__price')).not.toBeEmpty();
        // Stock is never signalled by colour alone, so there must be a label, not just a dot.
        await expect(card.locator('.swag-assistant-card__stock')).not.toBeEmpty();
    });

    test('the conversation and its cards survive a reload', async ({ page }) => {
        await openPanel(page);
        await page.locator('[data-swag-assistant-input]').fill('show me the trail jersey in blue, size M');
        await page.locator('[data-swag-assistant-send]').click();
        await expect(page.locator('.swag-assistant-card').first()).toBeVisible({ timeout: TURN_TIMEOUT });

        await page.reload();
        await page.locator('[data-swag-assistant-orb]').click();

        // The card is re-rendered from the catalogue, not replayed from the transcript, so this also
        // asserts GET /assistant/cards is reachable and returns something.
        await expect(page.locator('.swag-assistant-message--user')).toHaveCount(1);
        await expect(page.locator('.swag-assistant-card').first()).toBeVisible({ timeout: 15_000 });

        // A restored message shows the time it happened. It must never show the current time.
        const stamped = page.locator('.swag-assistant-message__time').first();
        await expect(stamped).toHaveAttribute('datetime', /^\d{4}-\d{2}-\d{2}T/);
    });

    test('an over-long message is refused before any request goes out, and says why', async ({ page }) => {
        await openPanel(page);

        const requests = [];
        page.on('request', (request) => {
            if (request.url().includes('/assistant/chat')) {
                requests.push(request.url());
            }
        });

        const input = page.locator('[data-swag-assistant-input]');

        // One over the limit. All 501 characters really arrive: the composer has no `maxlength`,
        // deliberately, because the browser enforces that by silently truncating.
        await input.fill('x'.repeat(OVER_LIMIT));
        await expect(input).toHaveValue(new RegExp(`^x{${OVER_LIMIT}}$`));

        await expect(page.locator('[data-swag-assistant-send]')).toBeDisabled();
        await expect(input).toHaveClass(/is-too-long/);
        // An orange border and a dead Send button say something is wrong and nothing about what.
        // The count is what makes the state actionable rather than mysterious.
        await expect(page.locator('.swag-assistant-composer__notice'))
            .toContainText(String(OVER_LIMIT));
        expect(requests).toHaveLength(0);

        // Exactly at the limit is fine, and the explanation goes away with the problem.
        await input.fill('x'.repeat(LIMIT));
        await expect(page.locator('[data-swag-assistant-send]')).toBeEnabled();
        await expect(page.locator('.swag-assistant-composer__notice')).toHaveCount(0);
    });

    /**
     * The client measures what it sends the way the server measures what it receives. It used not to:
     * `.length` counts UTF-16 units where `mb_strlen` counts characters, and trailing whitespace was
     * counted here but trimmed there — so a field of emoji, or a message with three spaces after it,
     * was refused locally even though the endpoint would have taken it.
     */
    test('emoji and trailing whitespace are counted the way the server counts them', async ({ page }) => {
        await openPanel(page);

        const input = page.locator('[data-swag-assistant-input]');
        const send = page.locator('[data-swag-assistant-send]');

        // `.length` sees 2 per emoji, so this is 1000 UTF-16 units — twice the limit — but 500
        // characters, which is exactly the limit.
        await input.fill('\u{1F600}'.repeat(LIMIT));
        await expect(send).toBeEnabled();

        await input.fill(`${'x'.repeat(LIMIT)}   `);
        await expect(send).toBeEnabled();
    });

    test('a card can be added to the cart', async ({ page }) => {
        await openPanel(page);
        // Black / M is the fixture's in-stock variant. Blue / M is deliberately sold out.
        await page.locator('[data-swag-assistant-input]').fill('show me the trail jersey in black, size M');
        await page.locator('[data-swag-assistant-send]').click();

        const add = page.locator('.swag-assistant-card__add').first();
        await expect(add).toBeVisible({ timeout: TURN_TIMEOUT });
        await expect(add).toBeEnabled();
        await add.click();

        // The shop's own cart is the assertion, not our button's label.
        await page.goto(`${SHOP}/checkout/cart`);
        await expect(page.locator('body')).toContainText('Trail Jersey');
    });
});

/*
 * The three defects a shopper reported on the deployed shop, 2026-08-21.
 *
 * These stub `POST /assistant/chat` instead of spending a live turn, and that is the point rather
 * than a shortcut: each one is about how the widget reads a *particular response shape*, and asking
 * a model nicely for markdown or for a `cart_added` verdict is exactly the kind of "usually works"
 * setup that makes a regression test flake. The shapes below are copied from responses the live
 * endpoint actually produced.
 */
test.describe('reading what the server actually sends', () => {
    const CHAT = '**/assistant/chat';

    /** @param {import('@playwright/test').Page} page */
    async function stubTurn(page, body) {
        await page.route(CHAT, (route) => route.fulfill({
            status: 200,
            contentType: 'application/json',
            body: JSON.stringify({
                token: 'e2e-stub',
                prose: '',
                cards: [],
                outcome: 'product_shown',
                warnings: { unbackedPrices: [], unbackedAvailabilityClaims: [] },
                handoff: null,
                ...body,
            }),
        }));
    }

    /** @param {import('@playwright/test').Page} page */
    async function ask(page, text) {
        await page.locator('[data-swag-assistant-input]').fill(text);
        await page.locator('[data-swag-assistant-send]').click();
    }

    const card = (overrides) => ({
        id: 'e2ee2ee2ee2ee2ee2ee2ee2ee2ee2ee2',
        name: 'Alloy Water Bottle 750ml',
        description: 'Insulated alloy bottle.',
        price: 19.9,
        currency: 'EUR',
        stock: 12,
        inStock: true,
        deliveryTime: '1-3 days',
        url: '/detail/e2e',
        imageUrl: null,
        options: [],
        ...overrides,
    });

    test('a formatted reply is rendered, never printed as syntax', async ({ page }) => {
        await openPanel(page);
        // Measured on the live shop: asterisks visible, and both list items on one line because a
        // single newline used to carry no meaning here.
        await stubTurn(page, {
            prose: 'I found two listings:\n1. **Alloy Water Bottle 750 ml**\n2. **Alloy Water Bottle 750ml**'
                + '\n\nSee [the offer](https://example.invalid/x). 2 * 3 = 6.',
        });
        await ask(page, 'do you have a 750ml bottle?');

        const body = page.locator('.swag-assistant-message--assistant .swag-assistant-message__body').last();
        await expect(body).toBeVisible();

        await expect(body).not.toContainText('**');
        await expect(body.locator('ol li')).toHaveCount(2);
        await expect(body.locator('strong').first()).toHaveText('Alloy Water Bottle 750 ml');
        // A URL in the prose is one the shop did not supply — the system prompt forbids the model
        // from stating one — so its words survive and its link does not.
        await expect(body).toContainText('the offer');
        await expect(body.locator('a')).toHaveCount(0);
        // An unmatched marker is arithmetic, not emphasis.
        await expect(body).toContainText('2 * 3 = 6');
    });

    test('the header cart count follows a cart the assistant filled itself', async ({ page }) => {
        await openPanel(page);

        // The shop's own cart-widget endpoint. Counting requests to it is how "the header was told"
        // becomes observable without asserting on a rendered number.
        const refreshes = [];
        page.on('request', (request) => {
            if (request.url().includes('/widgets/checkout/info')) {
                refreshes.push(request.url());
            }
        });

        const before = refreshes.length;
        await stubTurn(page, { prose: 'Added it.', outcome: 'cart_added' });
        await ask(page, 'add the bottle cage');

        await expect(page.locator('.swag-assistant-message--assistant').last()).toContainText('Added it.');
        await expect.poll(() => refreshes.length).toBeGreaterThan(before);
    });

    test('a product with no variants is addable; a product family is not', async ({ page }) => {
        await openPanel(page);
        await stubTurn(page, {
            prose: 'Here they are.',
            cards: [
                // `product`: a plain product. Its stock is its own and there is nothing to choose.
                card({ id: 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', stockSource: 'product' }),
                // `parent`: a family standing in for variants nobody has picked from.
                card({ id: 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb', stockSource: 'parent', name: 'Trail Jersey' }),
            ],
        });
        await ask(page, 'what have you got');

        const cards = page.locator('.swag-assistant-card');
        await expect(cards).toHaveCount(2);

        // Both are in stock. Only the family withholds one-click purchase, and only the family
        // explains why — this is the defect that cost every simple product its button.
        await expect(cards.nth(0).locator('.swag-assistant-card__add')).toHaveCount(1);
        await expect(cards.nth(0).locator('.swag-assistant-card__note')).toHaveCount(0);
        await expect(cards.nth(1).locator('.swag-assistant-card__add')).toHaveCount(0);
        await expect(cards.nth(1).locator('.swag-assistant-card__note')).toHaveCount(1);
    });

    /**
     * `CardIdList::MAX_IDS` caps ONE request at 12 ids and drops the rest without saying so.
     *
     * Measured live: ten turns produced 15 distinct cards, `GET /assistant/cards` answered with 12,
     * and the three newest silently never came back after a page load. Twenty-two turns produced 21.
     * The cap is correct — the endpoint is public and does a catalogue lookup per id — so the client
     * batches instead of asking past it.
     */
    /**
     * **A known, unfixed defect.** A reply taller than the panel opens at its own end, so the answer
     * is above the fold and what the shopper lands on is the card row. Reported from the deployed
     * shop with a screenshot: "the text about jerseys is pushed so far up it isn't visible".
     *
     * `test.fixme` rather than deleted, because five attempts at placing the message failed and the
     * measurements are worth keeping: `scrollTop` adjustments, absolute `scrollTo` on `offsetTop`,
     * the same deferred a frame, and `scrollIntoView({block: 'start'})` all landed between 52 and
     * 112 pixels too high, and instrumenting every scroll on the log proved nothing moves it
     * afterwards. See `scrollToLatest()` in render.js for the table and the conclusion: the
     * message's offset is not stable at append time, because the log's first child carries
     * `margin-top: auto` and the panel is still resolving its height, so free space decides the
     * transcript's position while free space is still changing.
     *
     * Fixing it means anchoring the transcript to the top of the log, which changes how a
     * one-message conversation looks. That is a design decision, so it is not taken here.
     */
    test.fixme('a reply taller than the panel opens at its first line', async ({ page }) => {
        await openPanel(page);

        const opening = 'Here is what is actually available:';
        await stubTurn(page, {
            prose: `${opening}\n\n${Array.from({ length: 14 }, (_, i) => `- line number ${i} of a reply that does not fit`).join('\n')}`,
            cards: [card({ id: 'c'.repeat(32), stockSource: 'product' })],
        });
        await ask(page, 'which should I get?');

        const reply = page.locator('.swag-assistant-message--assistant').last();
        await expect(reply).toContainText(opening);

        // Measured after the opening transition, because rects are transform-affected while the
        // panel is still scaling up from its `bottom right` origin.
        await page.waitForTimeout(700);

        const geometry = await reply.evaluate((el) => {
            const log = el.closest('[data-swag-assistant-log]');

            return {
                tallerThanLog: el.offsetHeight > log.clientHeight,
                offsetFromTop: Math.round(el.getBoundingClientRect().top - log.getBoundingClientRect().top),
            };
        });

        expect(geometry.tallerThanLog).toBe(true);
        expect(geometry.offsetFromTop).toBeGreaterThanOrEqual(-2);
        expect(geometry.offsetFromTop).toBeLessThan(4);
    });

    test('a transcript with more cards than one request allows re-hydrates whole', async ({ page }) => {
        const ids = Array.from({ length: 15 }, (_, i) => String(i).padStart(32, 'a'));

        await page.route('**/assistant/history*', (route) => route.fulfill({
            status: 200,
            contentType: 'application/json',
            body: JSON.stringify({
                messages: [
                    { role: 'user', prose: 'show me everything', cardIds: [], createdAt: null, warnings: {} },
                    { role: 'assistant', prose: 'Here they are.', cardIds: ids, createdAt: null, warnings: {} },
                ],
            }),
        }));

        // Stands in for the real endpoint, cap included: it answers with the first 12 ids it was
        // given and nothing else. A client that asks for all 15 at once therefore loses three — the
        // exact failure, reproduced without needing a 15-card conversation.
        const requestedCounts = [];
        await page.route('**/assistant/cards*', (route) => {
            const asked = new URL(route.request().url()).searchParams.get('ids').split(',');
            requestedCounts.push(asked.length);

            return route.fulfill({
                status: 200,
                contentType: 'application/json',
                body: JSON.stringify({
                    cards: asked.slice(0, 12).map((id) => card({ id, stockSource: 'product' })),
                }),
            });
        });

        // sessionStorage must hold a token, or the panel never asks for a history at all.
        await page.goto(SHOP);
        await page.evaluate(() => window.sessionStorage.setItem('swagAssistantToken', 'a'.repeat(32)));
        await page.locator('[data-swag-assistant-orb]').click();

        await expect(page.locator('.swag-assistant-card')).toHaveCount(15);
        // Batched, not asked past: no single request may exceed the server's own cap.
        expect(Math.max(...requestedCounts)).toBeLessThanOrEqual(12);
    });

    test('an escalated reply offers a way to reach a human', async ({ page }) => {
        // The shape the endpoint sends when `escalate` ran and the merchant configured a contact
        // route. Stubbed rather than prompted for: whether a model chooses to escalate is the eval
        // suite's job (`tests/Journeys/order_status_escalates.php`), and what the browser does with
        // the answer is this layer's.
        await openPanel(page);
        await stubTurn(page, {
            prose: 'I cannot look up orders, but the shop team can.',
            outcome: 'escalated',
            handoff: { message: 'Our team can help with orders.', url: '/contact' },
        });
        await ask(page, 'where is my order?');

        const handoff = page.locator('.swag-assistant-handoff');
        await expect(handoff).toBeVisible();
        await expect(handoff).toContainText('Our team can help with orders.');
        await expect(handoff.locator('a')).toHaveAttribute('href', '/contact');
        // A shopper reads a label, not a URL.
        await expect(handoff.locator('a')).not.toContainText('/contact');
    });

    test('an ordinary reply offers no handoff', async ({ page }) => {
        await openPanel(page);
        await stubTurn(page, { prose: 'Here is what I found.', outcome: 'product_shown' });
        await ask(page, 'a water bottle please');

        await expect(page.locator('.swag-assistant-message--assistant').last()).toBeVisible();
        await expect(page.locator('.swag-assistant-handoff')).toHaveCount(0);
    });
});

/*
The reset button is the one control here whose glyph nobody can guess, and it throws the
conversation away. It used to rely on the native `title`: about a second of delay, positioned at the
cursor rather than at the button, and absent entirely for anyone who arrived with Tab.
*/
test.describe('the header controls say what they do', () => {
    test('a label appears on hover and on keyboard focus, and does not eat the click',
        async ({ page }) => {
            await openPanel(page);

            const reset = page.locator('[data-swag-assistant-reset]');
            const tooltip = reset.locator('.swag-assistant-tooltip');

            // Present in the DOM but not visible, so it is never announced as content and never
            // occupies space.
            await expect(tooltip).toHaveCSS('opacity', '0');

            await reset.hover();
            await expect(tooltip).toHaveCSS('opacity', '1');
            await expect(tooltip).not.toBeEmpty();

            // Two tooltips for one control is worse than either, so the native one is gone.
            await expect(reset).not.toHaveAttribute('title', /./);
            // The accessible name still carries the same words.
            await expect(reset).toHaveAttribute('aria-label', /./);

            // Keyboard reaches it too — the whole reason the native tooltip was not enough. Tabbed
            // to rather than focused programmatically, because `:focus-visible` is exactly the
            // distinction between "arrived by keyboard" and "arrived by click", and only the real
            // keypress makes it true.
            await page.locator('[data-swag-assistant-input]').focus();
            await page.mouse.move(0, 0);

            for (let step = 0; step < 12; step += 1) {
                await page.keyboard.press('Tab');

                const onReset = await page.evaluate(
                    () => document.activeElement?.hasAttribute('data-swag-assistant-reset') === true,
                );

                if (onReset) {
                    break;
                }
            }

            await expect(page.locator('[data-swag-assistant-reset]')).toBeFocused();
            await expect(tooltip).toHaveCSS('opacity', '1');

            // `pointer-events: none`: the label sits under the cursor, and the click must still land
            // on the button beneath it.
            await reset.click();
            await expect(page.locator('.swag-assistant-message--user')).toHaveCount(0);
        });
});
