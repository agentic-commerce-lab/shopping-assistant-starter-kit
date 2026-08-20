/*
 * The composer: everything that happens between a shopper starting to type and a valid message
 * leaving the panel.
 *
 * Extracted from the panel plugin because it is a self-contained control with four states of its own
 * — empty, armed, over-length, busy — and because the panel's job is orchestration, not managing a
 * textarea's height.
 */

/**
 * @param {{
 *   form: HTMLFormElement, input: HTMLTextAreaElement, button: HTMLButtonElement,
 *   maxLength: number, onSubmit: (message: string) => void,
 * }} parts
 */
export function createComposer({ form, input, button, maxLength, onSubmit }) {
    let busy = false;

    form?.addEventListener('submit', (event) => {
        event.preventDefault();
        submit();
    });

    // Enter sends, Shift+Enter makes a newline — the convention every chat interface uses.
    input?.addEventListener('keydown', (event) => {
        if (event.key === 'Enter' && !event.shiftKey) {
            event.preventDefault();
            submit();
        }
    });

    input?.addEventListener('input', reflect);

    function submit() {
        const message = input.value.trim();

        if (message === '' || busy || message.length > maxLength) {
            return;
        }

        onSubmit(message);
    }

    /**
     * Reflects the field's content in three places at once: its own height, the send button's
     * armed-ness, and the over-length refusal.
     *
     * **The refusal is deliberate and it happens here, before any request.** `ChatRequest`'s server
     * limit rejects rather than truncates — because truncating sends the model half a question, which
     * it will then answer confidently — so spending a round trip to be told that is a waste of the
     * shopper's time. There is no `maxlength` on the field for the same reason: the browser enforces
     * that by silently swallowing the tail of a pasted question.
     */
    function reflect() {
        const value = input.value;
        const tooLong = value.length > maxLength;

        input.classList.toggle('is-too-long', tooLong);
        button.classList.toggle('is-armed', value.trim() !== '' && !tooLong && !busy);
        button.disabled = tooLong || busy;

        grow();
    }

    /**
     * A textarea cannot size itself to its content in CSS, so this is the one place the widget
     * measures and writes a height. `auto` first, because `scrollHeight` on an element already
     * stretched to fit reports the stretched height and the field would then never shrink again.
     *
     * The 120px ceiling is in the stylesheet, not here: past it `max-height` clamps and the field
     * scrolls, which is the behaviour a long paste should get.
     */
    function grow() {
        input.style.height = 'auto';
        input.style.height = `${input.scrollHeight}px`;
    }

    function setBusy(next) {
        busy = next;
        reflect();
    }

    function clear() {
        input.value = '';
        input.classList.remove('is-too-long');
        // Back to one line, or the field keeps the height of the message that just left it.
        input.style.height = '';
        reflect();
    }

    /**
     * Fills the field without sending, and puts the caret at the end.
     *
     * A suggestion chip is a prompt, not a shortcut: the shopper still presses send, and can edit the
     * question first. Sending on their behalf would put words in the transcript they never wrote.
     */
    function fill(value) {
        input.value = value;
        input.focus();
        input.setSelectionRange(value.length, value.length);
        reflect();
    }

    /** Used when the shop reports it cannot answer at all: 503 and 429 close the composer for good. */
    function close() {
        input.disabled = true;
        button.disabled = true;
        button.classList.remove('is-armed');
    }

    return { setBusy, clear, fill, close, focus: () => input?.focus() };
}
