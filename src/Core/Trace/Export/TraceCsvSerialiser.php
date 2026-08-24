<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Trace\Export;

use Swag\AssistantStarterKit\Entity\Conversation\ConversationEntity;

/**
 * Conversations as one row each, for counting.
 *
 * `fputcsv` over a memory stream rather than `implode(',')`: a customer's name is arbitrary text
 * that will one day contain a comma or a quote, and a hand-rolled writer turns that into a row with
 * silently the wrong number of columns. The export carries real names, which is what makes the
 * quoting load-bearing rather than theoretical.
 *
 * The header is written even for an empty selection: a file with a header and no rows says "nothing
 * matched", where a zero-byte file says "the export is broken", and a merchant cannot tell those
 * apart by looking.
 */
final class TraceCsvSerialiser
{
    /**
     * The column order, which is also {@see TraceExportRow::of()}'s key order — this writes
     * `array_values()`, so the two must not drift. `TraceExportRowTest` pins that end.
     *
     * @var list<string>
     */
    private const HEADER = [
        'id',
        'createdAt',
        'salesChannel',
        'user',
        'turns',
        'outcome',
        'totalMs',
        'shopMs',
        'modelMs',
        'toolCalls',
    ];

    /**
     * RFC 4180 has no escape character — a quote inside a field is doubled, and that is all.
     *
     * PHP's default is a backslash, which is **not** CSV: a field ending in one would swallow the
     * closing quote and shift every later column. Passing this explicitly is also what PHP 8.4+
     * requires, but the deprecation is the smaller reason to do it.
     */
    private const NO_ESCAPE = '';

    private function __construct() {}

    /**
     * @param list<ConversationEntity> $conversations
     * @param array<string, string>    $salesChannelNames id => name; an id not present falls back to
     *                                                    itself, because a blank cell would read as
     *                                                    "no sales channel"
     */
    public static function serialise(array $conversations, array $salesChannelNames): string
    {
        $handle = fopen('php://memory', 'r+');

        if ($handle === false) {
            throw new \RuntimeException('Could not open a memory stream to write the export.');
        }

        fputcsv($handle, self::HEADER, escape: self::NO_ESCAPE);

        foreach ($conversations as $conversation) {
            $channelId = $conversation->getSalesChannelId();
            $row = TraceExportRow::of($conversation, $salesChannelNames[$channelId] ?? $channelId);

            fputcsv($handle, array_values($row), escape: self::NO_ESCAPE);
        }

        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return $csv === false ? '' : $csv;
    }
}
