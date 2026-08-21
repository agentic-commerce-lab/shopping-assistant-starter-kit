/*
 * The smallest markdown reader that keeps the model's formatting out of the shopper's face.
 *
 * **Why this exists.** The prose is model output, and the model formats it — measured on the live
 * shop, 2026-08-21: *"I found two 750 ml water bottle listings: 1. **Alloy Water Bottle 750 ml**
 * 2. **Alloy Water Bottle 750ml**"* arrived at the panel with the asterisks visible and both list
 * items on one line. Rendering it as `textContent` printed the syntax; the system prompt now asks
 * for restraint, but a prompt is a request, not a guarantee, so the client has to be able to read
 * what actually arrives.
 *
 * **Why this is not a markdown library.** Two reasons, in order:
 *
 * 1. **No HTML is ever parsed.** This produces a token tree and {@see toFragment} turns tokens into
 *    DOM nodes with `createElement`/`createTextNode`. There is no `innerHTML` anywhere on this
 *    path, so a reply containing `<script>` renders as the characters `<script>` — which is what
 *    `textContent` already guaranteed and what a markdown-to-HTML library would hand back for us
 *    to trust. In a product whose whole claim is that model output is never trusted, the parser
 *    must not be the exception.
 * 2. **An allowlist, so nothing leaks.** Exactly four inline forms and four block forms are
 *    understood (below). Everything else has its markers stripped and its text kept: an unmatched
 *    `*` stays an asterisk, a `# heading` becomes an emphasised line, a table row becomes a
 *    sentence. The failure mode is plainer text, never visible syntax.
 *
 * Inline: `code`, **strong**, *emphasis*, [link text](url) — the URL is dropped, deliberately. The
 * system prompt forbids the model from stating a URL, so a link it invented is exactly the thing
 * that must not become clickable. Product links live on the cards, from the shop's own records.
 */

/** Ordered by precedence: a code span wins over `**`, which wins over `*`. */
const INLINE_PATTERN = new RegExp([
    // `code`
    '`([^`\\n]+)`',
    // **strong** / __strong__
    '\\*\\*([^*\\n]+)\\*\\*',
    '__([^_\\n]+)__',
    // *emphasis* — never across a line, and never an unmatched marker
    '\\*([^*\\n]+)\\*',
    // _emphasis_, guarded on both sides so snake_case identifiers and ids survive intact
    '(?<![A-Za-z0-9])_([^_\\n]+)_(?![A-Za-z0-9])',
    // [text](url) — the text is kept, the url discarded
    '\\[([^\\]\\n]*)\\]\\([^)\\n]*\\)',
].join('|'), 'g');

const BULLET_PATTERN = /^\s*[-*+•]\s+(.*)$/;

const ORDERED_PATTERN = /^\s*\d+[.)]\s+(.*)$/;

const HEADING_PATTERN = /^\s*#{1,6}\s+(.*)$/;

const QUOTE_PATTERN = /^\s*>\s?(.*)$/;

/** `---`, `***`, `___` and longer. A rule carries no words, so it is simply dropped. */
const RULE_PATTERN = /^\s*([-*_])\s*\1\s*\1[\s\-*_]*$/;

/** A table's separator row — `|---|:--:|` — carries no words either. */
const TABLE_RULE_PATTERN = /^\s*\|?[\s:|-]*-[\s:|-]*\|?\s*$/;

const TABLE_ROW_PATTERN = /^\s*\|(.*)\|\s*$/;

/**
 * Splits prose into blocks. A blank line ends a block; a single newline is a line break *inside*
 * one, which is the second half of the measured bug — `buildProse` used to split on blank lines
 * only, so a two-item list separated by single newlines was concatenated into one unreadable line.
 *
 * @param {string} text
 * @returns {Array<
 *   {type: 'paragraph', lines: Array<Array<object>>}
 *   | {type: 'list', ordered: boolean, items: Array<Array<object>>}
 * >}
 */
export function parseBlocks(text) {
    const blocks = [];
    let paragraph = null;
    let list = null;

    const flush = () => {
        if (paragraph && paragraph.lines.length > 0) {
            blocks.push(paragraph);
        }

        if (list && list.items.length > 0) {
            blocks.push(list);
        }

        paragraph = null;
        list = null;
    };

    for (const raw of String(text ?? '').split('\n')) {
        const line = raw.trimEnd();

        if (line.trim() === '' || RULE_PATTERN.test(line) || TABLE_RULE_PATTERN.test(line)) {
            flush();
            continue;
        }

        const item = matchListItem(line);

        if (item) {
            if (paragraph) {
                flush();
            }

            // A change of marker starts a new list, so a bulleted list following a numbered one is
            // not silently renumbered.
            if (!list || list.ordered !== item.ordered) {
                flush();
                list = { type: 'list', ordered: item.ordered, items: [] };
            }

            list.items.push(parseInline(item.text));
            continue;
        }

        if (list) {
            flush();
        }

        if (!paragraph) {
            paragraph = { type: 'paragraph', lines: [] };
        }

        const spans = parseInline(stripBlockMarkers(line));

        if (spans.length > 0) {
            paragraph.lines.push(spans);
        }
    }

    flush();

    return blocks;
}

/**
 * @param {string} line
 * @returns {?{ordered: boolean, text: string}}
 */
function matchListItem(line) {
    const bullet = BULLET_PATTERN.exec(line);
    if (bullet) {
        return { ordered: false, text: bullet[1] };
    }

    const ordered = ORDERED_PATTERN.exec(line);
    if (ordered) {
        return { ordered: true, text: ordered[1] };
    }

    return null;
}

/**
 * Strips the markers of the block forms that are not rendered structurally, keeping their words.
 *
 * A heading becomes a strong line rather than an `<h*>`: a heading inside a chat reply is the
 * model over-formatting a sentence, and promoting it to a real document heading would inject a
 * bogus level into the page's outline that a screen reader then reads out as structure.
 */
function stripBlockMarkers(line) {
    const heading = HEADING_PATTERN.exec(line);
    if (heading) {
        return `**${heading[1]}**`;
    }

    const quote = QUOTE_PATTERN.exec(line);
    if (quote) {
        return quote[1];
    }

    const row = TABLE_ROW_PATTERN.exec(line);
    if (row) {
        return row[1].split('|').map((cell) => cell.trim()).filter(Boolean).join(' · ');
    }

    return line;
}

/**
 * @param {string} text
 * @returns {Array<{type: 'text'|'strong'|'em'|'code', text: string}>}
 */
export function parseInline(text) {
    const spans = [];
    let cursor = 0;

    // A fresh regex per call: the shared `g` flag carries `lastIndex` between calls otherwise, and
    // one stale index silently eats the front of the next line.
    const pattern = new RegExp(INLINE_PATTERN.source, 'g');
    let match = pattern.exec(text);

    const push = (type, value) => {
        if (value !== '') {
            spans.push({ type, text: value });
        }
    };

    while (match !== null) {
        push('text', text.slice(cursor, match.index));

        const [, code, strongStars, strongScores, emStars, emScores, linkText] = match;

        if (code !== undefined) {
            push('code', code);
        } else if (strongStars !== undefined || strongScores !== undefined) {
            push('strong', strongStars ?? strongScores);
        } else if (emStars !== undefined || emScores !== undefined) {
            push('em', emStars ?? emScores);
        } else {
            push('text', linkText);
        }

        cursor = match.index + match[0].length;
        match = pattern.exec(text);
    }

    push('text', text.slice(cursor));

    return spans;
}

const SPAN_TAGS = { strong: 'strong', em: 'em', code: 'code' };

/**
 * Turns the token tree into DOM nodes. `createTextNode` for every piece of model text — see the
 * file header for why this is not `innerHTML`.
 *
 * @param {string} text
 * @returns {DocumentFragment}
 */
export function toFragment(text) {
    const fragment = document.createDocumentFragment();

    for (const block of parseBlocks(text)) {
        fragment.appendChild(block.type === 'list' ? buildList(block) : buildParagraph(block));
    }

    return fragment;
}

function buildParagraph(block) {
    const p = document.createElement('p');

    block.lines.forEach((spans, index) => {
        if (index > 0) {
            p.appendChild(document.createElement('br'));
        }

        appendSpans(p, spans);
    });

    return p;
}

function buildList(block) {
    const list = document.createElement(block.ordered ? 'ol' : 'ul');
    list.className = 'swag-assistant-message__list';

    for (const spans of block.items) {
        const item = document.createElement('li');
        appendSpans(item, spans);
        list.appendChild(item);
    }

    return list;
}

function appendSpans(parent, spans) {
    for (const span of spans) {
        const tag = SPAN_TAGS[span.type];

        if (!tag) {
            parent.appendChild(document.createTextNode(span.text));
            continue;
        }

        const el = document.createElement(tag);
        el.textContent = span.text;
        parent.appendChild(el);
    }
}
