<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Command;

use Swag\AssistantStarterKit\Core\Commerce\Dto\FacetSet;
use Swag\AssistantStarterKit\Core\Commerce\Dto\ProductCard;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Terminal output for {@see ProbeCommand}.
 *
 * Split out (cyclomatic-complexity) rather than suppressed, and the split is the natural one:
 * the command decides what to ask the gateway, this decides how the answer is shown.
 *
 * `stockSource` is printed as its own column on purpose. It is the field that says whether a stock
 * figure belongs to the variant asked about or to its parent, which is the difference between an
 * honest answer and the one that cancels orders (D4) — so it must be visible, not inferable.
 */
final readonly class ProbeRenderer
{
    private const MAX_FACET_VALUES = 12;

    /**
     * @param list<ProductCard> $cards
     */
    public function cards(SymfonyStyle $io, array $cards): void
    {
        $io->table(['id', 'name', 'price', 'stock', 'stockSource', 'options', 'url'], array_map(
            fn(ProductCard $card): array => [
                $card->id,
                $card->name,
                \sprintf('%.2f %s', $card->price, $card->currency),
                (string) $card->stock,
                $card->stockSource->value,
                $this->json($card->options),
                $card->url,
            ],
            $cards,
        ));
    }

    public function card(SymfonyStyle $io, ProductCard $card): void
    {
        $io->definitionList(
            ['id' => $card->id],
            ['parentId' => $card->parentId ?? '(none)'],
            ['name' => $card->name],
            ['price' => \sprintf('%.2f %s', $card->price, $card->currency)],
            ['stock' => (string) $card->stock],
            ['stockSource' => $card->stockSource->value],
            ['options' => $this->json($card->options)],
            ['url' => $card->url],
        );
    }

    public function facets(SymfonyStyle $io, FacetSet $facets): void
    {
        $rows = [];

        foreach ($facets->fields() as $field) {
            $facet = $facets->get($field);

            if ($facet === null) {
                continue;
            }

            $rows[] = [$field, $facet->type->value, $this->facetValues($facet->values, $facet->min, $facet->max)];
        }

        $io->table(['field', 'type', 'values'], $rows);
    }

    /**
     * @param list<string> $values
     */
    private function facetValues(array $values, ?float $min, ?float $max): string
    {
        if ($values === []) {
            return \sprintf('%s – %s', $min ?? '?', $max ?? '?');
        }

        $shown = \array_slice($values, 0, self::MAX_FACET_VALUES);
        $hidden = \count($values) - \count($shown);

        // Says how many it left out. A silently truncated facet list reads as the shop's complete
        // vocabulary, which is exactly the wrong impression when debugging a dropped filter.
        return implode(', ', $shown) . ($hidden > 0 ? \sprintf(' … (+%d more)', $hidden) : '');
    }

    /**
     * @param array<string, string> $options
     */
    private function json(array $options): string
    {
        return json_encode($options, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE) ?: '{}';
    }
}
