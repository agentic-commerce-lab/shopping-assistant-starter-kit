import assert from 'node:assert/strict';
import { test } from 'node:test';

import { createOpenState } from '../../src/Resources/app/storefront/src/assistant/open-state.js';

function fakeStorage() {
    const data = {};

    return {
        getItem: (key) => (key in data ? data[key] : null),
        setItem: (key, value) => {
            data[key] = String(value);
        },
        removeItem: (key) => {
            delete data[key];
        },
    };
}

test('starts closed', () => {
    assert.equal(createOpenState(() => fakeStorage()).wasOpen(), false);
});

test('remembers open across instances, and forgets on close', () => {
    const storage = fakeStorage();

    createOpenState(() => storage).set(true);
    assert.equal(createOpenState(() => storage).wasOpen(), true);

    createOpenState(() => storage).set(false);
    assert.equal(createOpenState(() => storage).wasOpen(), false);
});

test('unavailable storage degrades to closed without throwing', () => {
    const state = createOpenState(() => {
        throw new Error('SecurityError');
    });

    state.set(true);
    assert.equal(state.wasOpen(), false);
});
