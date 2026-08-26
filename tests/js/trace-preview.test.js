import assert from 'node:assert/strict';
import { test } from 'node:test';

import {
    conversationPreview,
} from '../../src/Resources/app/administration/src/module/swag-assistant-trace/page/swag-assistant-trace-list/preview.js';

/*
 * Which turn the grid's two prose columns show, and what they do when there is no such turn.
 *
 * The selection rule is the whole of it: both halves come from the **last** exchange, because the
 * `outcome` column beside them is the last turn's outcome. A first-question/last-reply pairing
 * would render two cells that read as one exchange and belong to turns eight apart.
 */
const user = (prose) => ({ role: 'user', prose });
const assistant = (prose) => ({ role: 'assistant', prose });

test('a single exchange shows its question and its reply', () => {
    const preview = conversationPreview([user('do you have the trail jersey?'), assistant('Yes, in blue and black.')]);

    assert.equal(preview.question, 'do you have the trail jersey?');
    assert.equal(preview.reply, 'Yes, in blue and black.');
});

test('a long conversation shows the last exchange, not the first', () => {
    // The assertion the design turns on. With the first question here, the row would read
    // "asked about the jersey → replied about socks", which is a conversation that never happened.
    const preview = conversationPreview([
        user('do you have the trail jersey?'),
        assistant('Yes, in blue and black.'),
        user('what socks go with it?'),
        assistant('The merino crew is the usual pairing.'),
    ]);

    assert.equal(preview.question, 'what socks go with it?');
    assert.equal(preview.reply, 'The merino crew is the usual pairing.');
});

test('a question with no reply yet reports the question and no reply', () => {
    // A turn that failed before the assistant produced prose. The question is still the most useful
    // thing on the row, and inventing a reply for it would be the failure this whole product is about.
    const preview = conversationPreview([user('where is my order?')]);

    assert.equal(preview.question, 'where is my order?');
    assert.equal(preview.reply, null);
});

test('empty prose is an absence, not an empty cell', () => {
    // A stored '' and a missing turn are the same thing to a reader, and only one of them would
    // otherwise get the em-dash that says so.
    const preview = conversationPreview([user('anything?'), assistant('')]);

    assert.equal(preview.reply, null);
});

test('a transcript that is not an array yields nothing rather than throwing', () => {
    // `transcript` is a JSON column. A row written by an older plugin version, or a null one from a
    // conversation that never got a turn, must not take the whole grid down.
    for (const value of [null, undefined, {}, 'not json', 42]) {
        assert.deepEqual(conversationPreview(value), { question: null, reply: null });
    }
});

test('a malformed turn is skipped rather than rendered thinly', () => {
    // readTurns defaults a missing role to 'unknown' and missing prose to '', so a bad entry
    // matches neither role and carries no text — it falls out here without special-casing.
    const preview = conversationPreview([{}, null, user('still readable'), { role: 'assistant' }]);

    assert.equal(preview.question, 'still readable');
    assert.equal(preview.reply, null);
});

test('an assistant turn is not mistaken for a question', () => {
    // The roles are the only thing separating the two columns; a loop that fell through to "the
    // last turn with any role" would put the reply in both cells.
    const preview = conversationPreview([assistant('Hi. I can look things up in this shop.')]);

    assert.equal(preview.question, null);
    assert.equal(preview.reply, 'Hi. I can look things up in this shop.');
});
