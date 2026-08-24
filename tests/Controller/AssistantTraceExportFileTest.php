<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Controller;

use Symfony\Component\HttpFoundation\Response;

/**
 * What the export endpoint hands back: a file that saves under its own name, and an honest account
 * of anything it could not include.
 */
final class AssistantTraceExportFileTest extends TraceExportTestCase
{
    public function testTheFileIsNamedSoTwoExportsDoNotOverwriteEachOther(): void
    {
        $response = $this->export(self::ids(1));

        self::assertMatchesRegularExpression(
            '/attachment; filename=assistant-traces-\d{4}-\d{2}-\d{2}-\d{6}\.json/',
            (string) $response->headers->get('Content-Disposition'),
        );
        self::assertStringContainsString('application/json', (string) $response->headers->get('Content-Type'));
    }

    public function testAnIdThatNoLongerResolvesIsReportedRatherThanSwallowed(): void
    {
        // One stale id must not cost the merchant the rest of the export — but "some rows are
        // missing" is not something to discover by counting lines afterwards.
        $this->source->dropCount = 2;

        $response = $this->export(self::ids(5));

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertSame('2', $response->headers->get('X-Swag-Assistant-Skipped'));
    }

    public function testNothingIsReportedSkippedWhenEverythingResolved(): void
    {
        self::assertNull($this->export(self::ids(3))->headers->get('X-Swag-Assistant-Skipped'));
    }
}
