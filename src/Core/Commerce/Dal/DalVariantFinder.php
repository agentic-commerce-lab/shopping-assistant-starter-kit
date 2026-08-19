<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Commerce\Dal;

use Shopware\Core\Content\Product\SalesChannel\SalesChannelProductEntity;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\System\SalesChannel\Entity\SalesChannelRepository;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Swag\AssistantStarterKit\Core\Commerce\Dto\CatalogScope;
use Swag\AssistantStarterKit\Core\Commerce\Dto\ProductCard;
use Swag\AssistantStarterKit\Core\Commerce\Dto\ProductQuery;
use Swag\AssistantStarterKit\Core\Commerce\Dto\StockSource;
use Swag\AssistantStarterKit\Core\Commerce\VariantSelectionMatcher;

/**
 * Resolves a parent product plus chosen options to the one concrete variant — the method D4 exists
 * for, and the last line of defence against reporting a parent's aggregate stock in answer to a
 * variant question.
 *
 * Three decisions here are load-bearing:
 *
 * 1. **No availability or stock filter on the children.** A sold-out variant must stay findable, or
 *    "is the blue M in stock?" gets answered with "no such product" — a different lie from the one
 *    we are avoiding, but still a lie. Against the seeded catalogue this is the difference between
 *    reporting Blue/M's real stock of 0 and denying it exists.
 * 2. **Matching happens in PHP, not in the query.** The comparison rules are shared with the
 *    fixture gateway via {@see VariantSelectionMatcher}, because `VariantResolver` cannot tell the
 *    two apart and must not get a different answer from each.
 * 3. **Exactly one match, or null.** Never the first of several. An under-specified selection
 *    ("the blue one") legitimately matches three variants, and after ruling R47 a guess here would
 *    render a real price for the wrong variant — confidently wrong, which is worse than the empty
 *    answer the old behaviour gave.
 */
final readonly class DalVariantFinder
{
    /**
     * A variant family's realistic upper bound, with headroom. Spec fixture `fx-030` describes 40
     * variants; a real matrix (colour x size x length) reaches similar numbers. A cap rather than
     * an unbounded read because this runs per turn.
     */
    private const MAX_VARIANTS = 100;

    public function __construct(
        private SalesChannelRepository $productRepository,
        private DalCriteriaBuilder $criteriaBuilder,
        private DalProductCardMapper $mapper,
    ) {}

    /**
     * @param list<\Swag\AssistantStarterKit\Core\Commerce\Dto\VariantSelection> $selections
     */
    public function find(
        string $parentId,
        array $selections,
        CatalogScope $scope,
        SalesChannelContext $context,
    ): ?ProductCard {
        if ($selections === []) {
            // Every variant matches an empty selection, so continuing would return a match only
            // when the family happens to have exactly one member — an accident, not a resolution.
            return null;
        }

        $criteria = $this->criteriaBuilder->build(
            new ProductQuery(limit: self::MAX_VARIANTS),
            $scope,
            $context->getSalesChannelId(),
        );
        $criteria->addFilter(new EqualsFilter('parentId', $parentId));

        $currency = $context->getCurrency()->getIsoCode();
        $matches = [];

        foreach ($this->productRepository->search($criteria, $context)->getElements() as $entity) {
            if (!$entity instanceof SalesChannelProductEntity) {
                continue;
            }

            // StockSource::Variant unconditionally: every row here is a child of $parentId, so
            // every card carries its own stock and price by construction.
            $card = $this->mapper->map($entity, StockSource::Variant, $currency);

            if (VariantSelectionMatcher::matchesAll($card, $selections)) {
                $matches[] = $card;
            }
        }

        return \count($matches) === 1 ? $matches[0] : null;
    }
}
