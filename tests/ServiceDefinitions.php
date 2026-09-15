<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests;

/**
 * Reads `services.xml` into the one shape {@see ServiceArgumentOrderTest} needs: a service class
 * and the ids of its positional arguments, in order.
 *
 * Its own class because parsing XML and comparing types against reflection are two jobs, and holding
 * both put the test over this project's complexity gate. `DOMDocument` rather than `simplexml`, for a
 * second reason that is not style: SimpleXML's nodes are opaque to the analyzer — every attribute
 * read comes back `mixed` — and a test that has to be exempted from the analyzer to exist is a test
 * nobody will trust when it fails.
 */
final class ServiceDefinitions
{
    private function __construct() {}

    /**
     * @return array<string, list<string>> service class => positional argument ids, in order
     */
    public static function withPositionalArguments(string $path): array
    {
        $document = new \DOMDocument();
        $document->load($path);

        $services = [];

        foreach ($document->getElementsByTagName('service') as $service) {
            $id = $service->getAttribute('id');

            // An alias carries no arguments of its own, and an id that is not a class of ours is a
            // name in Shopware's container with nothing here to reflect on.
            if ($service->getAttribute('alias') !== '' || !class_exists($id)) {
                continue;
            }

            $arguments = self::argumentsOf($service);

            if ($arguments !== []) {
                $services[$id] = $arguments;
            }
        }

        return $services;
    }

    /**
     * @return list<string> empty when any argument is named — PHP checks those itself, and only
     *         positional ones can silently shift when a parameter is inserted
     */
    private static function argumentsOf(\DOMElement $service): array
    {
        $arguments = [];

        // DIRECT children only. `getElementsByTagName()` also returns the arguments nested inside
        // `<call>` elements — Symfony's `setContainer` injection among them — which made
        // AssistantController look as though it were given eleven constructor arguments for ten
        // parameters. Found by this test's first run against the whole file.
        foreach ($service->childNodes as $argument) {
            if (!$argument instanceof \DOMElement || $argument->tagName !== 'argument') {
                continue;
            }

            if ($argument->getAttribute('key') !== '') {
                return [];
            }

            $arguments[] = $argument->getAttribute('id');
        }

        return $arguments;
    }
}
