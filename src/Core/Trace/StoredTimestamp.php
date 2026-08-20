<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Trace;

/**
 * Reads a timestamp out of stored JSON, or gives up.
 *
 * Its own class rather than a method on {@see JsonShape}: that class narrows JSON *shapes* — strings,
 * lists, maps — and this parses a date, which is a different job with a different failure mode. It
 * also has the sharpest version of the "narrow, never cast" rule the transcript is built on:
 * `new DateTimeImmutable()` on a junk string **throws**, and `strtotime()` on one returns a
 * real-looking date. A plausible date is exactly the value that gets believed.
 *
 * Null is a normal answer, not an error: every turn written before turns carried a timestamp has
 * none, and a shopper holding one of those tokens must get a message with no time rather than an
 * exception — or worse, the current time presented as the message's.
 */
final readonly class StoredTimestamp
{
    public function orNull(mixed $value): ?\DateTimeImmutable
    {
        if (!\is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return new \DateTimeImmutable($value);
        } catch (\Exception) {
            return null;
        }
    }
}
