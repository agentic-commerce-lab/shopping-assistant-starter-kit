/*
 * Renders `src/Resources/config/plugin.svg` to the `plugin.png` Shopware reads.
 *
 * Playwright rather than a native converter: it is already a dependency of the end-to-end suite,
 * and rsvg/ImageMagick are not installed on this machine. Chrome renders the same SVG the browser
 * would, which is the renderer that matters for a mark shared with the storefront.
 *
 * `PluginService.php:80` resolves the icon at `src/Resources/config/plugin.png`, overridable via
 * `extra.plugin-icon` in composer.json. 256x256 because that is what the Extension Manager's
 * detail view uses; the list renders the same file at roughly 40px, which is the size the mark
 * actually has to survive — see scripts/render-plugin-icon.mjs --preview.
 */
import { chromium } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const svg = readFileSync(resolve(root, 'src/Resources/config/plugin.svg'), 'utf8');
const sizes = process.argv.includes('--preview') ? [256, 64, 40] : [256];

const browser = await chromium.launch();
const page = await browser.newPage();

for (const size of sizes) {
    await page.setViewportSize({ width: size, height: size });
    await page.setContent(
        `<body style="margin:0;background:transparent">${svg.replace(
            /width="256" height="256"/,
            `width="${size}" height="${size}"`,
        )}</body>`,
    );

    const out = size === 256
        ? resolve(root, 'src/Resources/config/plugin.png')
        : resolve(root, `.playwright-mcp/plugin-icon-${size}.png`);

    await page.screenshot({ path: out, omitBackground: true });
    console.log(`${size}x${size} -> ${out}`);
}

await browser.close();
