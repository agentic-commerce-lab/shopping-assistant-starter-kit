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
 *   maxLength: number, tooLongTemplate?: string, onSubmit: (message: string) => void,
 * }} parts
 */
export function createComposer({ form, input, button, maxLength, tooLongTemplate = '', onSubmit }) {
    let busy = false;
    let notice = null;

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

    /**
     * The message as the server will see it, measured the way the server measures it.
     *
     * `trim()` because `ChatRequest` trims before counting, and spread rather than `.length` because
     * PHP's `mb_strlen` counts characters while JS `.length` counts UTF-16 units — one 😀 is 1 to the
     * server and 2 to `.length`. Both differences used to make the client refuse messages the server
     * would have accepted: three trailing spaces, or half a field of emoji. Refusing early is the
     * design; refusing something valid is not.
     */
    function measured() {
        return [...input.value.trim()].length;
    }

    function submit() {
        const message = input.value.trim();

        if (message === '' || busy || measured() > maxLength) {
            return;
        }

        onSubmit(message);
    }

    /**
     * Reflects the field's content in four places at once: its own height, the send button's
     * armed-ness, the over-length refusal, and the reason for it.
     *
     * **The refusal is deliberate and it happens here, before any request.** `ChatRequest`'s server
     * limit rejects rather than truncates — because truncating sends the model half a question, which
     * it will then answer confidently — so spending a round trip to be told that is a waste of the
     * shopper's time. There is no `maxlength` on the field for the same reason: the browser enforces
     * that by silently swallowing the tail of a pasted question.
     *
     * **The reason is shown, which it was not before.** An orange border and a dead Send button say
     * that something is wrong and nothing about what — a shopper's likeliest reading is that the
     * assistant broke. That was survivable while the limit was 2000 characters and effectively
     * unreachable; at 500 it is a state real messages hit, so it has to explain itself.
     */
    function reflect() {
        const length = measured();
        const tooLong = length > maxLength;

        input.classList.toggle('is-too-long', tooLong);
        button.classList.toggle('is-armed', input.value.trim() !== '' && !tooLong && !busy);
        button.disabled = tooLong || busy;

        showNotice(tooLong, length);
        grow();
    }

    /**
     * The one message in this widget that is not the shop talking — it is the field explaining
     * itself, so it lives in the composer rather than in the log.
     *
     * `role="status"`, not `alert`: someone typing past the limit is mid-action, and an assertive
     * region would interrupt their own keystrokes to tell them so. It is created on demand and
     * removed again, so the composer has no permanent empty row waiting to be filled.
     */
    function showNotice(tooLong, length) {
        if (!tooLong) {
            notice?.remove();
            notice = null;

            return;
        }

        if (!notice) {
            notice = document.createElement('p');
            notice.className = 'swag-assistant-composer__notice';
            notice.setAttribute('role', 'status');
            form?.appendChild(notice);
        }

        notice.textContent = tooLongTemplate
            .replace('%count%', String(length))
            .replace('%limit%', String(maxLength));
    }

    /**
     * A textarea cannot size itself to its content in CSS, so this is the one place the widget
     * measures and writes a height. `auto` first, because `scrollHeight` on an element already
     * stretched to fit reports the stretched height and the field would then never shrink again.
     *
     * The 120px ceiling is in the stylesheet, not here: past it `max-height` clamps and the field
     * scrolls, which is the behaviour a long paste should get.
     *
     * **The borders have to be added back.** `scrollHeight` is content plus padding; the field is
     * `box-sizing: border-box`, so writing that number as `height` makes the border eat two pixels
     * of it. Measured on the live shop: the moment the first keystroke ran this, `scrollHeight`
     * stayed 42 while `clientHeight` dropped to 40 — a permanent two-pixel overflow, which is a
     * scrollbar sitting in an empty single-line field at every size it ever takes.
     */
    function grow() {
        input.style.height = 'auto';
        input.style.height = `${input.scrollHeight + borderHeight()}px`;
    }

    /**
     * Read from the computed style rather than hardcoded, because the border width is the
     * stylesheet's decision and a `1px` that becomes `2px` there must not silently reintroduce the
     * overflow. Cached: this runs on every keystroke, and `getComputedStyle` forces layout.
     */
    let cachedBorderHeight = null;

    function borderHeight() {
        if (cachedBorderHeight === null) {
            const style = window.getComputedStyle(input);
            cachedBorderHeight = (parseFloat(style.borderTopWidth) || 0)
                + (parseFloat(style.borderBottomWidth) || 0);
        }

        return cachedBorderHeight;
    }

    function setBusy(next) {
        busy = next;
        reflect();
    }

    function clear() {
        input.value = '';
        input.classList.remove('is-too-long');
        notice?.remove();
        notice = null;
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

    /**
     * Used when the shop reports it cannot answer at all — a model the merchant has not configured.
     *
     * **Reversible, which it was not.** This disabled the textarea and nothing ever re-enabled it:
     * `clear()` runs `reflect()`, which re-arms the *button* and never touches `input.disabled`, so
     * "start a new conversation" produced a fresh greeting above a field nobody could type in. One
     * transient failure and the widget was dead for the rest of that page's life. Reported from the
     * deployed shop, 2026-08-21.
     */
    function close() {
        input.disabled = true;
        button.disabled = true;
        button.classList.remove('is-armed');
    }

    /** The other half of `close()`. Anything that starts over has to be able to undo it. */
    function open() {
        input.disabled = false;
        reflect();
    }

    return { setBusy, clear, fill, close, open, focus: () => input?.focus() };
}
