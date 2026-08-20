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

    test('the orb clears whatever else occupies the corner', async ({ page }) => {
        await page.goto(SHOP);

        // The cookie-consent bar is fixed at z-index 1100 and covered 38px of the 60px orb before
        // orb.plugin.js began measuring it.
        const overlap = await page.evaluate(() => {
            const bar = document.querySelector('.cookie-permission-container');
            const orb = document.querySelector('.swag-assistant-orb');

            if (!bar || !orb) {
                return false;
            }

            const a = bar.getBoundingClientRect();
            const b = orb.getBoundingClientRect();

            return !(a.right < b.left || a.left > b.right || a.bottom < b.top || a.top > b.bottom);
        });

        expect(overlap).toBe(false);
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

    test('an over-long message is refused before any request goes out', async ({ page }) => {
        await openPanel(page);

        const requests = [];
        page.on('request', (request) => {
            if (request.url().includes('/assistant/chat')) {
                requests.push(request.url());
            }
        });

        // 2001 characters really arrive: the composer has no `maxlength`, deliberately, because the
        // browser enforces that by silently truncating.
        await page.locator('[data-swag-assistant-input]').fill('x'.repeat(2001));
        await expect(page.locator('[data-swag-assistant-input]')).toHaveValue(/^x{2001}$/);

        await expect(page.locator('[data-swag-assistant-send]')).toBeDisabled();
        await expect(page.locator('[data-swag-assistant-input]')).toHaveClass(/is-too-long/);
        expect(requests).toHaveLength(0);
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
