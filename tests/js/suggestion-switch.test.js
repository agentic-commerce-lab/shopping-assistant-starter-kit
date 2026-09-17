import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';

/*
 * The suggestion switch has two owners and no compiler between them.
 *
 * `showSuggestions` is read in PHP, written into `orb.html.twig` as a data attribute, and read back
 * out of `dataset` by the panel. Nothing checks that the two spellings agree: rename the attribute on
 * one side and the panel reads `undefined`, which is not an error anywhere — it is simply a switch
 * that stopped working, in the direction nobody tests.
 *
 * Read as text rather than imported, for the same reason `panel-defaults.test.js` reads the
 * stylesheet: `panel.plugin.js` extends Shopware's `Plugin` base class and cannot be loaded outside a
 * storefront bundle. What can be checked here is that the two files name the same thing, and that the
 * default the JavaScript falls back to is the default the plugin documents.
 */
const orb = readFileSync('src/Resources/views/storefront/component/assistant/orb.html.twig', 'utf8');
const panel = readFileSync('src/Resources/app/storefront/src/assistant/panel.plugin.js', 'utf8');

test('the template emits the attribute the panel reads', () => {
    assert.match(
        orb,
        /data-suggestions-enabled="/,
        'orb.html.twig no longer emits data-suggestions-enabled',
    );
    assert.match(
        panel,
        /dataset\.suggestionsEnabled/,
        'panel.plugin.js no longer reads dataset.suggestionsEnabled',
    );
});

test('a missing attribute leaves the chips on', () => {
    // `!== 'false'`, never `=== 'true'`. The sibling flag beside it uses `=== 'true'` and is right to:
    // add-to-cart is a capability, so an absent attribute should withhold it. This one is a default-on
    // presentation choice, and a theme carrying an older copy of `orb.html.twig` would otherwise lose
    // the chips with nothing said — the plugin's documented default, silently inverted by a template
    // override.
    const read = panel.match(/this\.suggestionsEnabled = (.+);/);
    assert.ok(read, 'panel.plugin.js no longer assigns this.suggestionsEnabled');
    assert.equal(read[1], "this.el.dataset.suggestionsEnabled !== 'false'");
});

test('the chip row is skipped when the switch is off', () => {
    assert.match(
        panel,
        /if \(!this\.suggestionsEnabled\) \{\s*return \[\];/,
        '_suggestions() no longer short-circuits on the switch',
    );
});
