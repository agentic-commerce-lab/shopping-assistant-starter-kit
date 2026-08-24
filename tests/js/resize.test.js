import assert from 'node:assert/strict';
import { test } from 'node:test';

import {
    MAX_WIDTH,
    MIN_HEIGHT,
    MIN_WIDTH,
    clampSize,
} from '../../src/Resources/app/storefront/src/assistant/resize.js';

/*
 * The clamp is the part worth testing: a drag can ask for any number, and a panel wider than the
 * viewport or shorter than its own header is not a preference, it is a broken layout.
 */
test('a size within bounds is returned unchanged', () => {
    assert.deepEqual(clampSize({ width: 500, height: 500 }, { width: 1440, height: 900 }), { width: 500, height: 500 });
});

test('below the floor is raised to it', () => {
    const size = clampSize({ width: 10, height: 10 }, { width: 1440, height: 900 });

    assert.equal(size.width, MIN_WIDTH);
    assert.equal(size.height, MIN_HEIGHT);
});

test('above the ceiling is capped', () => {
    assert.equal(clampSize({ width: 99999, height: 500 }, { width: 1440, height: 900 }).width, MAX_WIDTH);
});

test('a narrow viewport wins over the configured ceiling', () => {
    // On a 500px-wide window the 720px ceiling is not reachable, and a panel wider than the window is
    // a horizontal scrollbar on the whole page.
    const size = clampSize({ width: 720, height: 400 }, { width: 500, height: 900 });

    assert.ok(size.width < 500, `expected under the viewport width, got ${size.width}`);
});

test('a short viewport wins over the height floor', () => {
    // The floor cannot be honoured on a 200px-tall window, and a panel taller than the window cannot
    // be closed. The viewport is the harder constraint.
    const size = clampSize({ width: 420, height: 640 }, { width: 1440, height: 200 });

    assert.ok(size.height <= 200, `expected within the viewport, got ${size.height}`);
});

test('a non-numeric size yields finite defaults rather than NaN', () => {
    // localStorage is a string store and anyone can edit it. NaN written to a width is a panel with
    // no size at all.
    const size = clampSize({ width: 'wide', height: null }, { width: 1440, height: 900 });

    assert.equal(Number.isFinite(size.width), true);
    assert.equal(Number.isFinite(size.height), true);
});

test('a missing size object is handled', () => {
    const size = clampSize(undefined, { width: 1440, height: 900 });

    assert.equal(Number.isFinite(size.width), true);
    assert.equal(Number.isFinite(size.height), true);
});
