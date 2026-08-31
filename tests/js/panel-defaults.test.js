import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';

import {
    DEFAULT_HEIGHT,
    DEFAULT_WIDTH,
    MAX_HEIGHT,
    MAX_WIDTH,
} from '../../src/Resources/app/storefront/src/assistant/resize.js';

/*
 * The panel's opening size has two owners, and this file is the only thing stopping them from
 * drifting.
 *
 * The stylesheet sizes the panel for the first paint — it has to, because the panel must be correct
 * before the lazily-loaded chunk runs — and `resize.js` carries the same numbers as the defaults it
 * falls back to when `localStorage` holds nothing. Changing one and not the other produces a panel
 * that silently resizes itself on load, which is invisible in every unit test and obvious to a
 * shopper.
 *
 * Read as text rather than compiled: the point is that the two literals agree, and a Sass build would
 * only tell us what the stylesheet says, not that anybody kept it in step.
 */
const tokens = readFileSync('src/Resources/app/storefront/src/scss/components/_tokens.scss', 'utf8');
const panel = readFileSync('src/Resources/app/storefront/src/scss/components/_panel.scss', 'utf8');

function scssWidth() {
    const match = tokens.match(/\$swag-assistant-panel-width:\s*(\d+)px/);
    assert.ok(match, '_tokens.scss no longer declares $swag-assistant-panel-width in px');

    return Number(match[1]);
}

function scssHeightCeiling() {
    // The first literal inside the panel's `max-height: min(...)` is the ceiling; the rest of the
    // expression is the viewport clamp, which has no JS counterpart.
    const match = panel.match(/max-height:\s*min\(\s*(\d+)px/);
    assert.ok(match, '_panel.scss no longer opens its max-height with a px ceiling');

    return Number(match[1]);
}

test('the stylesheet and the resize module agree on the opening width', () => {
    assert.equal(scssWidth(), DEFAULT_WIDTH);
});

test('the stylesheet and the resize module agree on the opening height', () => {
    assert.equal(scssHeightCeiling(), DEFAULT_HEIGHT);
});

test('the opening size is the one this project ships', () => {
    // Pinned, not derived: these are the numbers a reviewer approved, and a silent change to either
    // is what this test exists to report. Raised from 420x640 on 2026-08-31 — a long answer did not
    // fit and the panel had to be scrolled to read one reply.
    assert.equal(DEFAULT_WIDTH, 480);
    assert.equal(DEFAULT_HEIGHT, 760);
});

test('the opening size stays inside the bounds a drag is allowed to reach', () => {
    // A default outside the clamp would be silently corrected on first load, which looks like the
    // panel ignoring its own stylesheet.
    assert.ok(DEFAULT_WIDTH <= MAX_WIDTH, `default width ${DEFAULT_WIDTH} exceeds MAX_WIDTH ${MAX_WIDTH}`);
    assert.ok(DEFAULT_HEIGHT <= MAX_HEIGHT, `default height ${DEFAULT_HEIGHT} exceeds MAX_HEIGHT ${MAX_HEIGHT}`);
});
