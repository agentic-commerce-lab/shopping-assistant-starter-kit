import assert from 'node:assert/strict';
import { test } from 'node:test';

import { parseBlocks, parseInline } from '../../src/Resources/app/storefront/src/assistant/markdown.js';

/*
 * `tests/e2e/README.md` says there are deliberately no unit tests for the rendering functions,
 * because they would pass by restating the code they test. A parser is the exception it names
 * around: its inputs are the strings a model actually emitted, its outputs are what a shopper
 * reads, and the interesting cases — an unmatched marker, an id containing an underscore, a code
 * span holding an asterisk — are precisely the ones a browser check would never think to type.
 *
 * These run on the pure half of the module (`parseBlocks` / `parseInline`), which needs no DOM:
 * `node --test tests/js`, no dependencies. `toFragment` is DOM assembly and is covered where it
 * belongs, in the end-to-end suite.
 */

/** @param {Array<object>} spans */
const plain = (spans) => spans.map((span) => `${span.type}:${span.text}`).join('|');

test('strong emphasis becomes a span, not asterisks', () => {
    assert.equal(
        plain(parseInline('the **Trail Jersey** is here')),
        'text:the |strong:Trail Jersey|text: is here',
    );
});

test('underscore emphasis is understood, but not inside an identifier', () => {
    assert.equal(plain(parseInline('_quietly_')), 'em:quietly');
    assert.equal(plain(parseInline('__loudly__')), 'strong:loudly');
    // The shape of a fixture id. Italicising the middle of it would corrupt a product reference.
    assert.equal(plain(parseInline('product_number_one')), 'text:product_number_one');
});

test('an unmatched marker stays a literal character', () => {
    assert.equal(plain(parseInline('2 * 3 = 6')), 'text:2 * 3 = 6');
    assert.equal(plain(parseInline('a lone ** here')), 'text:a lone ** here');
});

test('a code span wins over emphasis inside it', () => {
    assert.equal(plain(parseInline('use `a * b` for that')), 'text:use |code:a * b|text: for that');
});

test('a link keeps its words and drops its url', () => {
    // The system prompt forbids the model from stating a URL, so a link it wrote is by definition
    // one the shop did not supply. Product links come from the cards.
    assert.equal(
        plain(parseInline('see [the jersey](https://evil.example/x) here')),
        'text:see |text:the jersey|text: here',
    );
});

test('angle brackets are characters, never markup', () => {
    assert.equal(plain(parseInline('<script>alert(1)</script>')), 'text:<script>alert(1)</script>');
});

test('a single newline is a line break inside one paragraph', () => {
    const blocks = parseBlocks('first line\nsecond line');

    assert.equal(blocks.length, 1);
    assert.equal(blocks[0].type, 'paragraph');
    assert.equal(blocks[0].lines.length, 2);
});

test('a blank line starts a new paragraph', () => {
    const blocks = parseBlocks('one\n\ntwo');

    assert.deepEqual(blocks.map((block) => block.type), ['paragraph', 'paragraph']);
});

/** The measured failure: two numbered items rendered as one line with the asterisks showing. */
test('the numbered list from the live shop becomes two items', () => {
    const blocks = parseBlocks(
        'I found two 750 ml water bottle listings in the catalogue:\n'
        + '1. **Alloy Water Bottle 750 ml**\n'
        + '2. **Alloy Water Bottle 750ml**',
    );

    assert.deepEqual(blocks.map((block) => block.type), ['paragraph', 'list']);
    assert.equal(blocks[1].ordered, true);
    assert.deepEqual(
        blocks[1].items.map(plain),
        ['strong:Alloy Water Bottle 750 ml', 'strong:Alloy Water Bottle 750ml'],
    );
});

test('bulleted and numbered lists do not merge into one', () => {
    const blocks = parseBlocks('- one\n- two\n1. three');

    assert.deepEqual(blocks.map((block) => `${block.type}:${block.ordered}`), ['list:false', 'list:true']);
});

test('a heading becomes an emphasised line rather than a document heading', () => {
    const blocks = parseBlocks('## Bottles');

    assert.equal(blocks.length, 1);
    assert.equal(plain(blocks[0].lines[0]), 'strong:Bottles');
});

test('a horizontal rule and a table separator carry no words and are dropped', () => {
    assert.deepEqual(parseBlocks('---'), []);
    assert.deepEqual(parseBlocks('|---|---|'), []);
});

test('a table row becomes a readable sentence', () => {
    const blocks = parseBlocks('| Bottle | 750 ml |');

    assert.equal(plain(blocks[0].lines[0]), 'text:Bottle · 750 ml');
});

test('a blockquote marker is stripped and its words kept', () => {
    assert.equal(plain(parseBlocks('> mind the gap')[0].lines[0]), 'text:mind the gap');
});

test('empty and absent prose produce no blocks at all', () => {
    assert.deepEqual(parseBlocks(''), []);
    assert.deepEqual(parseBlocks(null), []);
    assert.deepEqual(parseBlocks(undefined), []);
});
