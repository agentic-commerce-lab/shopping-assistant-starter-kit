<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Tool;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Tool\SearchTermList;
use Swag\AssistantStarterKit\Core\Tool\ToolArgumentException;

/** Reject, never coerce — a silently truncated term list is a search the model believes it made. */
final class SearchTermListTest extends TestCase
{
    public function testNeitherArgumentGivenIsAnEmptyList(): void
    {
        self::assertSame([], SearchTermList::of(null, null, 'terms'));
    }

    public function testTheSingleTermComesFirst(): void
    {
        self::assertSame(['glove', 'bottle'], SearchTermList::of('glove', ['bottle'], 'terms'));
    }

    public function testItTrimsAndDropsEmptyTerms(): void
    {
        self::assertSame(['glove'], SearchTermList::of('  glove  ', ['', '   '], 'terms'));
    }

    public function testItDeduplicatesCaseInsensitivelyKeepingTheFirstSpelling(): void
    {
        self::assertSame(['Glove'], SearchTermList::of('Glove', ['glove', 'GLOVE'], 'terms'));
    }

    public function testTooManyTermsThrows(): void
    {
        $this->expectException(ToolArgumentException::class);
        $this->expectExceptionMessageMatches('/at most 3 terms/');

        SearchTermList::of('a', ['b', 'c', 'd'], 'terms');
    }

    /** Duplicates must not count toward the bound: they are not extra searches. */
    public function testDuplicatesDoNotConsumeTheBound(): void
    {
        self::assertSame(['a', 'b'], SearchTermList::of('a', ['a', 'b', 'b'], 'terms'));
    }

    public function testAnOverlongTermThrows(): void
    {
        $this->expectException(ToolArgumentException::class);
        $this->expectExceptionMessageMatches('/exceeds 200 characters/');

        SearchTermList::of(null, [str_repeat('x', 201)], 'terms');
    }

    public function testANonStringMemberThrows(): void
    {
        $this->expectException(ToolArgumentException::class);
        $this->expectExceptionMessageMatches('/strings only/');

        SearchTermList::of(null, [['glove']], 'terms');
    }
}
