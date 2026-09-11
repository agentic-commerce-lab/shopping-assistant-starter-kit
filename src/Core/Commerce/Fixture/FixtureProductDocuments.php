<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Commerce\Fixture;

use Swag\AssistantStarterKit\Core\Commerce\Dto\ProductDocument;

/**
 * A fixture product's attached documents, so document behaviour is reachable without a real shop.
 *
 * The same reason {@see FixtureBundleItems} exists: the DAL path needs a Shopware installation with
 * media actually attached, and a feature only reachable that way is a feature with no eval. This is
 * what lets a journey assert that a datasheet is offered — and, more importantly, that the prose
 * says nothing about what is in it.
 *
 * **The extension is derived, never given.** A fixture that could declare `pdf` on a file called
 * `manual.docx` would let a test pass a combination the real reader cannot produce:
 * {@see \Swag\AssistantStarterKit\Core\Commerce\Dal\DalProductDocuments} reads the extension off the
 * media entity and filters on it, so the two can only ever agree. Deriving it here keeps the fixture
 * honest about that rather than trusting the catalogue author.
 *
 * **It reads the key off the product rather than taking the list**, so {@see FixtureIndex} needs no
 * null-coalesce of its own at either of its two call sites. That class sits on the complexity gate
 * {@see FixtureBundleItems} was already split out to relieve, and two more branches there put it
 * back over.
 *
 * @phpstan-import-type FixtureProduct from FixtureIndex
 */
final readonly class FixtureProductDocuments
{
    /**
     * @param FixtureProduct $product
     *
     * @return list<ProductDocument>
     */
    public static function ofProduct(array $product): array
    {
        return array_map(
            static fn(array $document): ProductDocument => new ProductDocument(
                title: $document['title'],
                url: $document['url'],
                extension: self::extensionOf($document['url']),
            ),
            $product['documents'] ?? [],
        );
    }

    /**
     * The URL's own extension, lower-cased, or an empty string when it carries none.
     *
     * An empty extension is left as it is rather than defaulted to `pdf`: a fixture URL with no
     * extension is a catalogue mistake, and quietly inventing the value the allowlist wants would
     * hide it behind a passing test — the fixture would claim a shape the DAL reader cannot produce.
     */
    private static function extensionOf(string $url): string
    {
        $path = parse_url($url, \PHP_URL_PATH);

        return strtolower(pathinfo(\is_string($path) ? $path : $url, \PATHINFO_EXTENSION));
    }
}
