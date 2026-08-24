<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Controller;

use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Context;
use Swag\AssistantStarterKit\Controller\AssistantTraceExportController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Shared fixture for the export endpoint's tests.
 *
 * A base class rather than the same three helpers in two files: jscpd is part of the gate, and the
 * split below is by subject — what the endpoint refuses, and what it returns when it does not —
 * which is a reason to share a fixture rather than to copy one.
 */
abstract class TraceExportTestCase extends TestCase
{
    protected FakeTraceExportSource $source;

    /** @param non-empty-string $name */
    public function __construct(string $name)
    {
        parent::__construct($name);

        $this->source = new FakeTraceExportSource();
    }

    /** @return list<string> */
    protected static function ids(int $count): array
    {
        return array_map(static fn(int $i): string => \sprintf('%032x', $i), range(1, $count));
    }

    /** @param list<string> $ids */
    protected function export(array $ids, string $format): Response
    {
        $request = new Request(content: json_encode(['ids' => $ids, 'format' => $format], \JSON_THROW_ON_ERROR));

        return (new AssistantTraceExportController($this->source))->export($request, Context::createDefaultContext());
    }
}
