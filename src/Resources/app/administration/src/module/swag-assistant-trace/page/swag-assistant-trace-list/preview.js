// **The `.js` is required, and it is the only import in this module carrying one.**
//
// Every other file here imports extensionlessly, which the Administration's bundler resolves
// happily. Node's ESM loader does not, and `tests/js` runs under plain Node — see its
// `package.json`, which scopes `"type": "module"` to that folder precisely because the root
// setting broke the storefront's webpack build over the same disagreement. The two other modules
// `tests/js` covers are leaves with no imports of their own, so this is the first place the
// conflict has come up. An explicit extension resolves under both, which is why it wins over
// consistency with the four imports above it in `index.js`.
import { readTurns } from '../swag-assistant-trace-detail/payload.js';

/**
 * The one exchange a row shows, from the transcript it already carries.
 *
 * The list used to say only *how* a conversation went — `tool_limit_exceeded`, eight turns, 41
 * seconds — and never what it was about. Reaching that meant opening the row, which is fine once
 * and useless when triaging a page of them.
 *
 * ## Why both halves come from the **last** turn
 *
 * `outcome` on the conversation is the last turn's outcome — {@see DalConversationStore}, which
 * records it to answer *"how did this conversation end"*. Pairing that with the **first** question
 * would put three columns side by side describing two different moments, and worse, the question
 * and the reply would read as one exchange while belonging to turns eight apart. A merchant
 * scanning the grid would see an answer that has nothing to do with the question beside it and
 * reasonably conclude the assistant was incoherent.
 *
 * So the row is one consistent snapshot of the end: the question that produced the recorded
 * outcome, and the reply it produced. `turnCount` beside it is what says there was more, and the
 * detail page is what shows it.
 *
 * Nothing is truncated here. Clipping is the stylesheet's job — `text-overflow: ellipsis` cuts at
 * the width the column actually has, so it stays right when the column is resized, and the whole
 * string stays in the DOM where it can be selected and copied. A `substring()` would throw the text
 * away and be correct at exactly one width.
 *
 * @param {unknown} transcript the raw `swag_assistant_conversation.transcript` JSON column
 *
 * @returns {{question: string|null, reply: string|null}} `null` where the transcript has no such turn
 */
export function conversationPreview(transcript) {
    // `readTurns` rather than a second reader: the transcript is a JSON column written by
    // TranscriptCodec, a row from an older plugin version may be missing keys, and the detail page
    // already owns shaping it defensively. Two shapers is how one of them quietly stops matching.
    const turns = readTurns(transcript);

    return {
        question: lastProse(turns, 'user'),
        reply: lastProse(turns, 'assistant'),
    };
}

/**
 * The prose of the last turn with this role, or `null`.
 *
 * Empty prose is `null` too, not an empty cell: a turn that stored no text and a turn that stored
 * `''` are the same absence to a reader, and only one of them would otherwise get the em-dash that
 * says so.
 */
function lastProse(turns, role) {
    for (let index = turns.length - 1; index >= 0; index -= 1) {
        const turn = turns[index];

        if (turn.role === role && turn.prose !== '') {
            return turn.prose;
        }
    }

    return null;
}
