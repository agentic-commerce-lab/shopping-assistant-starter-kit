import { defineConfig, devices } from '@playwright/test';

/*
 * The end-to-end checks run against a *running shop*, so there is no `webServer` here to start one —
 * the shop is a Docker stack with a database and a configured model, and standing it up per run is
 * neither fast nor free.
 */
export default defineConfig({
    testDir: './tests/e2e',

    // A real turn takes 16-19s, measured, and some tests hold two of them.
    timeout: 120_000,
    expect: { timeout: 10_000 },

    // Serial by design: every test shares one shop, one cart and one catalogue. Parallel workers
    // would have them adding to each other's carts.
    workers: 1,
    fullyParallel: false,

    // No retries. A flaky assertion here is information about the widget, not noise to paper over.
    retries: 0,

    reporter: [['list']],

    use: {
        baseURL: process.env.SHOP_URL ?? 'http://127.0.0.1:8000',

        // Required, not stylistic: Playwright will not click the orb while its breathe animation
        // runs — "element is not stable". This works because the widget's prefers-reduced-motion
        // support is real. See tests/e2e/README.md.
        reducedMotion: 'reduce',

        screenshot: 'only-on-failure',
        trace: 'retain-on-failure',
        ...devices['Desktop Chrome'],
    },
});
