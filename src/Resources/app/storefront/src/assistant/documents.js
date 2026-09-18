/*
 * The downloadable-document rows, shared by the product card and the order card.
 *
 * Extracted when the order card needed them: a product's datasheet and an order's invoice are the
 * same row — a titled link to a file the shop already decided this shopper may have — and two copies
 * of it would be two places to get the cap, the target and the "+n more" line wrong. The duplication
 * gate would have caught the copy; this is the version that does not need catching.
 *
 * **Which documents exist is never this file's decision.** For a product they are what the merchant
 * attached; for an order they are what Shopware already released to the account
 * (`displayInCustomerAccount` and `sent`), narrowed to the shopper's own orders by the route the
 * server read. Nothing here filters, and nothing here may start to.
 */

/**
 * How many rows fit before the list stops being an index.
 *
 * Three, measured against the surface rather than chosen: a card in the row is 176px wide and its
 * other rows — name, department, price, stock — come to about the same height again, and four links
 * already made the card taller than the product photograph beside it. A real product also carries
 * more files than that holds: the shop this was built for attaches up to eleven, most of them one
 * datasheet in eight languages. An order carries fewer, and the same cap keeps the two cards the
 * same shape.
 */
export const MAX_DOCUMENTS = 3;

/**
 * @param {Array<{title?: string, url?: string, extension?: string}>} documents
 * @param {Object} translations
 * @returns {HTMLUListElement|null} null when there is nothing to show, so a caller can skip the slot
 */
export function buildDocumentList(documents, translations) {
    const usable = Array.isArray(documents) ? documents.filter((doc) => doc && doc.url) : [];

    if (usable.length === 0) {
        return null;
    }

    const list = document.createElement('ul');
    list.className = 'swag-assistant-card__documents';

    const shown = usable.slice(0, MAX_DOCUMENTS);

    shown.forEach((doc) => {
        const item = document.createElement('li');
        const link = document.createElement('a');

        link.className = 'swag-assistant-card__document';
        link.href = doc.url;
        link.target = '_blank';
        link.rel = 'noopener';
        // The extension is the format badge, so a title that already carries it is not repeated.
        link.textContent = doc.title || translations.document || '';

        item.appendChild(link);
        list.appendChild(item);
    });

    const hidden = usable.length - shown.length;

    if (hidden > 0) {
        const more = document.createElement('li');
        more.className = 'swag-assistant-card__documents-more';
        more.textContent = (translations.documentsMore ?? '+%count% more').replace('%count%', hidden);
        list.appendChild(more);
    }

    return list;
}
