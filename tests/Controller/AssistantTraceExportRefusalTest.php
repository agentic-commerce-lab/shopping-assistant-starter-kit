<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Controller;

use Symfony\Component\HttpFoundation\Response;

/**
 * What the export endpoint will not do. Every case here must refuse **before** anything is loaded:
 * the bound exists to stop work, not to report on it afterwards.
 */
final class AssistantTraceExportRefusalTest extends TraceExportTestCase
{
    public function testTooManyIdsAreRefusedWithTheCount(): void
    {
        $response = $this->export(self::ids(1001));

        self::assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
        self::assertStringContainsString('1001', (string) $response->getContent());
        self::assertStringContainsString('1000', (string) $response->getContent(), 'the bound must be named');
        self::assertSame([], $this->source->lastIds, 'nothing may be loaded once the bound is exceeded');
    }

    public function testExactlyTheBoundIsAllowed(): void
    {
        // Off-by-one on a refusal is the difference between a bound and a bug.
        self::assertSame(Response::HTTP_OK, $this->export(self::ids(1000))->getStatusCode());
    }

    public function testAnEmptyListIsRefused(): void
    {
        self::assertSame(Response::HTTP_BAD_REQUEST, $this->export([])->getStatusCode());
    }

    public function testIdsThatAreNotIdsAreDroppedBeforeAnythingIsLoaded(): void
    {
        // The endpoint is authenticated but its body is still client input, and a malformed id has
        // no business reaching a repository lookup — the same rule ChatRequest applies.
        $response = $this->export(['../../etc/passwd', 'NOTHEX', str_repeat('a', 32)]);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertSame([str_repeat('a', 32)], $this->source->lastIds);
    }
}
