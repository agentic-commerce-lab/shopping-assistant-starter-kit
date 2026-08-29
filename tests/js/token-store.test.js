import assert from 'node:assert/strict';
import { test } from 'node:test';

import { createTokenStore } from '../../src/Resources/app/storefront/src/assistant/token-store.js';

/*
 * `tests/e2e/README.md` keeps DOM assembly out of unit tests. This module has no DOM in it at all —
 * the only side effect is a storage object, and that is injected — so it is exactly the pure logic
 * that belongs here rather than in the e2e suite.
 */

/** A plain object standing in for `sessionStorage`, so a test never touches the real thing. */
function fakeStorage(initial = {}) {
    const data = { ...initial };

    return {
        getItem: (key) => (Object.prototype.hasOwnProperty.call(data, key) ? data[key] : null),
        setItem: (key, value) => {
            data[key] = String(value);
        },
        removeItem: (key) => {
            delete data[key];
        },
        // Exposed for assertions only; a real Storage object has no such property.
        _data: data,
    };
}

/** A storage stand-in that throws on every access, as a real one does in a private window. */
function throwingStorage() {
    return {
        getItem: () => {
            throw new Error('SecurityError');
        },
        setItem: () => {
            throw new Error('SecurityError');
        },
        removeItem: () => {
            throw new Error('SecurityError');
        },
    };
}

test('a token stored for one context is not visible from another', () => {
    const storage = fakeStorage();
    const storeA = createTokenStore(storage, 'context-a');
    const storeB = createTokenStore(storage, 'context-b');

    storeA.set('token-a');

    assert.equal(storeA.get(), 'token-a');
    assert.equal(storeB.get(), null);
});

test('reset clears only the current context slot', () => {
    const storage = fakeStorage();
    const storeA = createTokenStore(storage, 'context-a');
    const storeB = createTokenStore(storage, 'context-b');

    storeA.set('token-a');
    storeB.set('token-b');

    storeA.clear();

    assert.equal(storeA.get(), null);
    assert.equal(storeB.get(), 'token-b');
});

test('a legacy single token is adopted once into the current slot', () => {
    const storage = fakeStorage({ swagAssistantToken: 'legacy-token' });
    const store = createTokenStore(storage, 'context-a');

    assert.equal(store.get(), 'legacy-token');
    // Adopted, not merely read: the old key is gone and the value now lives under the map.
    assert.equal(storage.getItem('swagAssistantToken'), null);

    const map = JSON.parse(storage.getItem('swagAssistantTokens'));
    assert.equal(map['context-a'], 'legacy-token');

    // A second read must not re-adopt into some other context or duplicate work.
    assert.equal(store.get(), 'legacy-token');
});

test('a legacy token is not adopted over an existing slot value', () => {
    const storage = fakeStorage({ swagAssistantToken: 'legacy-token' });
    const store = createTokenStore(storage, 'context-a');

    store.set('fresh-token');

    // Re-create the store as the plugin would on the next page load, legacy key still present
    // because a first read never happened to consume it.
    const storeAgain = createTokenStore(storage, 'context-a');

    assert.equal(storeAgain.get(), 'fresh-token');
});

test('a storage that throws degrades to no token rather than breaking the widget', () => {
    const storage = throwingStorage();
    const store = createTokenStore(storage, 'context-a');

    assert.equal(store.get(), null);
    assert.doesNotThrow(() => store.set('token'));
    assert.doesNotThrow(() => store.clear());
});

test('a corrupt map value is discarded rather than parsed into nonsense', () => {
    const storage = fakeStorage({ swagAssistantTokens: 'not json' });
    const store = createTokenStore(storage, 'context-a');

    assert.equal(store.get(), null);

    // Writing afterwards must still work rather than staying wedged on the corrupt value.
    store.set('token-a');
    assert.equal(store.get(), 'token-a');
});
