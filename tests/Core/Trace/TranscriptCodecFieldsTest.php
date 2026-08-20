<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Trace;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Trace\ConversationTurn;
use Swag\AssistantStarterKit\Core\Trace\TranscriptCodec;

/**
 * Decoding the two fields a stored turn gained.
 *
 * The codec's rule is that stored JSON is **untrusted** — written by an earlier version of this
 * plugin, possibly with a different shape — and that values are narrowed rather than cast, because a
 * cast turns a wrong shape into a plausible-looking value and a plausible-looking value is the one
 * that gets believed. A timestamp is the sharpest case: `new DateTimeImmutable()` on junk throws, and
 * `strtotime()` on junk returns a real-looking date.
 */
#[CoversClass(TranscriptCodec::class)]
final class TranscriptCodecFieldsTest extends TestCase
{
    public function testItRoundTripsATimestamp(): void
    {
        $written = new \DateTimeImmutable('2026-08-20T09:41:07+00:00');

        $turn = $this->roundTrip(new ConversationTurn(role: 'assistant', prose: 'ok', createdAt: $written));

        self::assertSame($written->format(\DATE_ATOM), $turn->createdAt?->format(\DATE_ATOM));
    }

    public function testItRoundTripsWarnings(): void
    {
        $turn = $this->roundTrip(new ConversationTurn(
            role: 'assistant',
            prose: 'Yes, it is available.',
            warnings: ['unbackedAvailabilityClaims' => ['is available']],
        ));

        self::assertSame(['unbackedAvailabilityClaims' => ['is available']], $turn->warnings);
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function unusableTimestamps(): iterable
    {
        yield 'absent' => [null];
        yield 'empty string' => [''];
        yield 'whitespace' => ['   '];
        yield 'not a date' => ['not-a-date'];
        yield 'a number' => [1755680467];
        yield 'an array' => [['2026-08-20']];
    }

    #[DataProvider('unusableTimestamps')]
    public function testAnUnusableTimestampDecodesToNullRatherThanThrowing(mixed $stored): void
    {
        $turn = $this->decodeOne(['role' => 'assistant', 'prose' => 'ok', 'createdAt' => $stored]);

        self::assertNull($turn->createdAt);
    }

    public function testMalformedWarningsDecodeToAnEmptyArray(): void
    {
        $turn = $this->decodeOne(['role' => 'assistant', 'prose' => 'ok', 'warnings' => 'unbackedPrices']);

        self::assertSame([], $turn->warnings);
    }

    public function testAnEmptyWarningListIsNotStored(): void
    {
        // `{"unbackedPrices": []}` carries no information, and keeping it would push an
        // empty-versus-absent distinction onto every reader.
        $turn = $this->decodeOne([
            'role' => 'assistant',
            'prose' => 'ok',
            'warnings' => ['unbackedPrices' => [], 'unbackedAvailabilityClaims' => ['is available']],
        ]);

        self::assertSame(['unbackedAvailabilityClaims' => ['is available']], $turn->warnings);
    }

    private function roundTrip(ConversationTurn $turn): ConversationTurn
    {
        return $this->decodeOne((new TranscriptCodec())->encode($turn));
    }

    /**
     * Asserts the entry decoded at all before returning it.
     *
     * The codec skips a malformed entry rather than decoding it into a turn with empty everything, so
     * "did it decode" and "what did it decode to" are two different questions — and a test that
     * indexed straight into the result would silently report the first as the second.
     *
     * @param array<string, mixed> $entry
     */
    private function decodeOne(array $entry): ConversationTurn
    {
        $turn = (new TranscriptCodec())->decodeAll([$entry])[0] ?? null;

        // `self::fail()` returns `never`, so this narrows for the analyzer as well as asserting for
        // the reader. `assertCount()` would do neither.
        if (!$turn instanceof ConversationTurn) {
            self::fail('the entry should have decoded into exactly one turn');
        }

        return $turn;
    }
}
