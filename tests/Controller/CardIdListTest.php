<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Controller;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Controller\CardIdList;
use Symfony\Component\HttpFoundation\Request;

/**
 * Parsing the id list for `GET /assistant/cards`.
 *
 * The endpoint is public and the ids arrive from a client rather than from our own transcript, so
 * most of what matters here is what gets refused.
 */
#[CoversClass(CardIdList::class)]
final class CardIdListTest extends TestCase
{
    private const BLUE_M = 'a2a2a2a2a2a2a2a2a2a2a2a2a2a2a2a2';

    private const BLACK_M = 'a5a5a5a5a5a5a5a5a5a5a5a5a5a5a5a5';

    public function testItReadsACommaSeparatedListInOrder(): void
    {
        // The order is the order the turn rendered them in, which is the order a shopper saw.
        self::assertSame([self::BLACK_M, self::BLUE_M], CardIdList::parse(self::BLACK_M . ',' . self::BLUE_M));
    }

    public function testItTrimsWhitespaceAroundEachId(): void
    {
        self::assertSame([self::BLUE_M, self::BLACK_M], CardIdList::parse(' ' . self::BLUE_M . ' , ' . self::BLACK_M));
    }

    public function testItDropsAMalformedIdRatherThanFailingTheRequest(): void
    {
        // One bad entry must not cost a shopper the cards that are fine.
        self::assertSame([self::BLUE_M], CardIdList::parse('not-an-id,' . self::BLUE_M));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function rejectedInput(): iterable
    {
        yield 'uppercase hex — Shopware ids are lowercase' => [strtoupper(self::BLUE_M)];
        yield 'too short' => ['a2a2a2'];
        yield 'too long' => [self::BLUE_M . 'ff'];
        yield 'non-hex characters' => [str_repeat('z', 32)];
        yield 'sql injection attempt' => ["' OR 1=1 --"];
        yield 'path traversal attempt' => ['../../etc/passwd'];
        yield 'empty' => ['   '];
    }

    #[DataProvider('rejectedInput')]
    public function testItRejects(string $raw): void
    {
        self::assertSame([], CardIdList::parse($raw));
    }

    public function testItDeduplicates(): void
    {
        // A repeated id would otherwise cost a second catalogue lookup for the same answer.
        self::assertSame([self::BLUE_M], CardIdList::parse(self::BLUE_M . ',' . self::BLUE_M));
    }

    public function testItCapsTheList(): void
    {
        $ids = [];
        for ($i = 0; $i < (CardIdList::MAX_IDS + 8); $i++) {
            $ids[] = str_pad(dechex($i + 16), 32, '0', \STR_PAD_LEFT);
        }

        self::assertCount(CardIdList::MAX_IDS, CardIdList::parse(implode(',', $ids)));
    }

    public function testAnAbsentParameterYieldsNothing(): void
    {
        self::assertSame([], CardIdList::fromRequest(Request::create('/assistant/cards')));
    }

    public function testItReadsFromTheQueryString(): void
    {
        $request = Request::create('/assistant/cards', 'GET', ['ids' => self::BLUE_M]);

        self::assertSame([self::BLUE_M], CardIdList::fromRequest($request));
    }
}
