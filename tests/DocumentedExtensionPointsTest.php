<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Pins `docs/extending.md` to the code it describes.
 *
 * ## Why this is a test and not a review habit
 *
 * The guide went 159 commits without an update. In that window the shop-information feature shipped
 * an entire retrieval architecture with its own tagged extension point, four opt-in gateway
 * capability interfaces landed, and the guide's "not extensible yet" table went on claiming there
 * was no retrieval architecture to hang a knowledge source on. Nothing failed, because nothing
 * checked — a merchant swapping the gateway got a silently worse assistant, and the one honest
 * signal that they should implement `MatchCountReader` existed only in a docblock nobody reads
 * before writing their own class.
 *
 * These checks are deliberately shallow. They cannot tell whether the guide's *prose* is true,
 * and nothing here substitutes for reading it. What they catch is the failure that actually
 * happened: a seam added to the code and not to the guide. The third check of that set lives in
 * {@see ApiSymbolsAreDocumentedTest}.
 */
final class DocumentedExtensionPointsTest extends TestCase
{
    private const GUIDE = __DIR__ . '/../docs/extending.md';

    /**
     * A service-container tag is the most public thing this plugin has: a third party writes it into
     * their own `services.xml`, so it cannot be renamed or added quietly.
     */
    public function testEveryContainerTagIsInTheExtensionGuide(): void
    {
        $services = file_get_contents(__DIR__ . '/../src/Resources/config/services.xml');
        self::assertIsString($services);

        $matches = [];
        preg_match_all('/tag name="(swag_assistant\.[a-z_]+)"/', $services, $matches);

        $captured = $matches[1] ?? [];
        self::assertIsArray($captured);

        $tags = array_unique($captured);

        self::assertNotEmpty($tags, 'no swag_assistant.* tags found - has services.xml moved?');

        foreach ($tags as $tag) {
            self::assertStringContainsString(
                $tag,
                $this->guide(),
                \sprintf('services.xml defines "%s" but docs/extending.md never mentions it.', $tag),
            );
        }
    }

    /**
     * The guide's examples are copied verbatim by whoever reads them, so a class that moved namespace
     * has to fail here rather than in their editor.
     */
    public function testEveryClassTheGuideImportsStillExists(): void
    {
        preg_match_all('/^use (Swag\\\\AssistantStarterKit\\\\[A-Za-z0-9_\\\\]+);/m', $this->guide(), $matches);

        self::assertNotEmpty($matches[1], 'the guide should import the interfaces it documents');

        foreach ($matches[1] as $class) {
            self::assertTrue(
                interface_exists($class) || class_exists($class) || enum_exists($class),
                \sprintf('docs/extending.md imports "%s", which does not exist.', $class),
            );
        }
    }

    private function guide(): string
    {
        $guide = file_get_contents(self::GUIDE);
        self::assertIsString($guide);

        return $guide;
    }
}
