<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Tool;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Commerce\Dto\ProductDocument;
use Swag\AssistantStarterKit\Core\Tool\DocumentLanguageSuffix;
use Swag\AssistantStarterKit\Core\Tool\DocumentTitles;

/**
 * Eight languages of one datasheet, and the words that must survive being mistaken for one.
 *
 * The stripping is safe only while every token in the list is a word no document is named after.
 * The collision cases below are the ones that would break that, and they are the reason this is a
 * closed list rather than "anything that looks like a language".
 */
#[CoversClass(DocumentLanguageSuffix::class)]
#[CoversClass(DocumentTitles::class)]
final class DocumentLanguageSuffixTest extends TestCase
{
    #[DataProvider('titles')]
    public function testTheTrailingLanguageIsRemoved(string $title, string $expected): void
    {
        self::assertSame($expected, DocumentLanguageSuffix::strip($title));
    }

    /** @return iterable<string, array{string, string}> */
    public static function titles(): iterable
    {
        // The forms this shop actually uses, all four of them.
        yield 'an iso code' => ['Sicherheitsdatenblatt EN', 'Sicherheitsdatenblatt'];
        yield 'a language name' => ['Produktdatenblatt BS-BATTERY German', 'Produktdatenblatt BS-BATTERY'];
        yield 'in brackets' => ['Technisches Datenblatt (DE)', 'Technisches Datenblatt'];
        yield 'after a dash' => ['Montageanleitung - Dutch', 'Montageanleitung'];

        yield 'no language at all' => ['Konformitätserklärung', 'Konformitätserklärung'];

        // The live defect of 2026-09-14, verbatim: rendered on a card as "Installation gui",
        // because `DE` matched the last two letters of "guide". The separator before the token is
        // required for exactly this, and these are the words that find it.
        yield 'a word merely ending in a language code' => ['Installation guide', 'Installation guide'];
        yield 'a title that is one such word' => ['Guide', 'Guide'];
        yield 'a language name inside a longer word' => ['Sweden', 'Sweden'];
        yield 'a word ending in a language name' => ['Kurzanleitung Polish', 'Kurzanleitung'];
        yield 'a language in the middle stays' => [
            'German Standard Datenblatt',
            'German Standard Datenblatt',
        ];
    }

    /**
     * A title that is ONLY a language is a poor label, but an empty one is worse — and an empty
     * title would render a link with no text at all.
     */
    public function testATitleThatIsNothingButALanguageSurvives(): void
    {
        self::assertSame('EN', DocumentLanguageSuffix::strip('EN'));
    }

    /**
     * The real shape, from the product that made this necessary: eleven files, four documents.
     */
    public function testElevenFilesCollapseToTheFourDocumentsTheyAre(): void
    {
        $titles = [
            'Sicherheitsdatenblatt EN',
            'Sicherheitsdatenblatt EN',
            'Produktdatenblatt BS-BATTERY Batterie BTX7L Danish',
            'Produktdatenblatt BS-BATTERY Batterie BTX7L German',
            'Produktdatenblatt BS-BATTERY Batterie BTX7L English',
            'Produktdatenblatt BS-BATTERY Batterie BTX7L Spanish',
            'Produktdatenblatt BS-BATTERY Batterie BTX7L French',
            'Produktdatenblatt BS-BATTERY Batterie BTX7L Italian',
            'Produktdatenblatt BS-BATTERY Batterie BTX7L Dutch',
            'Technisches Datenblatt EN',
            'Konformitätserklärung',
        ];

        $documents = array_map(
            static fn(string $title): ProductDocument => new ProductDocument($title, '/media/x.pdf', 'pdf'),
            $titles,
        );

        self::assertSame(
            [
                'Sicherheitsdatenblatt',
                'Produktdatenblatt BS-BATTERY Batterie BTX7L',
                'Technisches Datenblatt',
                'Konformitätserklärung',
            ],
            DocumentTitles::of($documents),
        );
    }

    /**
     * Two documents that differ by more than a language must stay two.
     */
    public function testDocumentsThatDifferInSubstanceAreNotMerged(): void
    {
        $documents = array_map(
            static fn(string $t): ProductDocument => new ProductDocument($t, '/media/x.pdf', 'pdf'),
            ['Sicherheitsdatenblatt Motor EN', 'Sicherheitsdatenblatt Getriebe EN'],
        );

        self::assertSame(
            ['Sicherheitsdatenblatt Motor', 'Sicherheitsdatenblatt Getriebe'],
            DocumentTitles::of($documents),
        );
    }
}
