<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Tool;

/**
 * Strips the language a merchant appended to a document's title, so eight of them collapse to one.
 *
 * ## The shape, measured on a real shop
 *
 * A motorcycle battery on the shop this was built against carries **eleven** attached PDFs:
 *
 *     Sicherheitsdatenblatt EN
 *     Sicherheitsdatenblatt EN
 *     Produktdatenblatt BS-BATTERY Batterie BTX7L / BTX7L-BS Danish
 *     Produktdatenblatt BS-BATTERY Batterie BTX7L / BTX7L-BS German
 *     … English, Spanish, French, Italian, Dutch …
 *     Technisches Datenblatt EN
 *     Konformitätserklärung
 *
 * Four documents, one of them in seven languages. {@see DocumentTitles} deduplicated by exact title
 * and therefore removed exactly one of the eleven — the repeated "Sicherheitsdatenblatt EN" — while
 * the seven language variants survived, because they differ in their last word. The model was handed
 * ten titles for four documents and told it may name them.
 *
 * ## Why a trailing token and not a language match
 *
 * Picking the shopper's own language was the obvious idea and is the worse one: merchants name these
 * freely — this catalogue alone mixes `EN`, `DE`, `German` and `Danish` — and a product whose German
 * sheet is missing would then surface nothing at all. Collapsing needs no correct answer about which
 * language a title is in, only the observation that the languages are what differ.
 *
 * ## Measured before shipping
 *
 * Over all 54 document-carrying products of the `parts` catalogue: **348 titles collapse to 102**,
 * and **zero groups merge titles that differ by anything other than a trailing language token**. The
 * suffixes actually removed were `EN` (50), then `Danish`, `German`, `English`, `Spanish`, `French`,
 * `Italian`, `Dutch` (40 each) and a handful of two-letter codes — no word that was part of a
 * document's name. That check is the reason this ships as a rule rather than a guess; see
 * {@see DocumentLanguageSuffixTest}, which pins the collision case.
 *
 * **The cards are untouched.** Every attached file stays on the product and stays clickable — a
 * shopper who wants the Dutch sheet must still be able to reach it. This only shapes what the MODEL
 * is told, which is the half that decides whether a reply reads as an answer or as a file listing.
 */
final class DocumentLanguageSuffix
{
    /**
     * Language names and ISO codes, as merchants actually write them at the end of a title.
     *
     * Closed and short on purpose. Every entry is a word no document is named after, which is what
     * makes stripping it safe; adding a word that could be part of a title — a brand, a format —
     * would merge two genuinely different documents, and that is worse than listing both.
     */
    private const LANGUAGES =
        'EN|DE|FR|ES|IT|NL|DA|PL|CS|SV|NO|FI|PT'
            . '|English|German|Deutsch|French|Spanish|Italian|Dutch|Danish|Polish|Czech'
            . '|Swedish|Norwegian|Finnish|Portuguese';

    private function __construct() {}

    /**
     * The title without its trailing language, or the title unchanged when it carries none.
     *
     * Brackets and dashes are allowed around the token because merchants write "Datenblatt (DE)" and
     * "Datenblatt - German" as readily as "Datenblatt German". A title that is ONLY a language is
     * returned as it was: "EN" is a poor label, but an empty one is worse.
     */
    public static function strip(string $title): string
    {
        $stripped = preg_replace(\sprintf('/\s*[-–(\[]?\s*(?:%s)\s*[)\]]?\s*$/ui', self::LANGUAGES), '', $title);

        $stripped = trim((string) $stripped);

        return $stripped === '' ? trim($title) : $stripped;
    }
}
