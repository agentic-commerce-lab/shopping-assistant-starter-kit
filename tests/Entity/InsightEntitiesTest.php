<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Entity;

use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\DataAbstractionLayer\EntityDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Field;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\Required;
use Shopware\Core\Framework\DataAbstractionLayer\FieldCollection;
use Swag\AssistantStarterKit\Entity\InsightFinding\InsightFindingDefinition;
use Swag\AssistantStarterKit\Entity\InsightRun\InsightRunDefinition;

/**
 * The two tables differ in one respect that D23 makes load-bearing: a run row holds counts and
 * survives retention, a finding row quotes a shopper and does not. These assertions pin the shape
 * that makes the distinction possible, not the DAL itself.
 *
 * Read through `defineFields()` by reflection rather than `getFields()`, matching
 * {@see \Swag\AssistantStarterKit\Tests\Core\Trace\ConversationScopeTypeNotRequiredTest}:
 * `getFields()` needs a compiled `DefinitionInstanceRegistry` because both definitions carry
 * associations, while flags are attached at definition time and readable without one.
 *
 * The prune that acts on the split is asserted separately against a real database — a pruner that
 * satisfies a mock and then violates a foreign key has proven nothing.
 */
final class InsightEntitiesTest extends TestCase
{
    public function testTheRunCarriesItsWindowAndItsCounts(): void
    {
        self::assertNotNull(self::field(new InsightRunDefinition(), 'windowStart'));
        self::assertNotNull(self::field(new InsightRunDefinition(), 'windowEnd'));
        self::assertNotNull(self::field(new InsightRunDefinition(), 'metrics'));
        self::assertNotNull(self::field(new InsightRunDefinition(), 'sampleSeed'));
    }

    public function testTheRunKeepsSearchTermsSeparateFromItsCounts(): void
    {
        // Separate columns because the terms are shopper-authored and get emptied on prune while
        // the counts stay. One JSON blob holding both could not be pruned by half.
        self::assertNotNull(self::field(new InsightRunDefinition(), 'searchTerms'));
        self::assertNotNull(self::field(new InsightRunDefinition(), 'metrics'));
    }

    public function testTheCountsAreRequiredAndTheTermsAreNot(): void
    {
        // A run without counts is not a run. A run without terms is a run whose conversations have
        // been pruned, which is the normal end state of every row in this table.
        self::assertTrue(self::field(new InsightRunDefinition(), 'metrics')?->is(Required::class));
        self::assertFalse(self::field(new InsightRunDefinition(), 'searchTerms')?->is(Required::class));
    }

    public function testAFindingPointsAtItsRunAndCarriesItsEvidence(): void
    {
        self::assertNotNull(self::field(new InsightFindingDefinition(), 'runId'));
        self::assertNotNull(self::field(new InsightFindingDefinition(), 'type'));
        self::assertNotNull(self::field(new InsightFindingDefinition(), 'quote'));
        self::assertNotNull(self::field(new InsightFindingDefinition(), 'suggestion'));
    }

    public function testAFindingMayOutliveItsConversationLink(): void
    {
        // Nullable on purpose. The pruner deletes findings with their conversations, but a row that
        // outlives its link through a delete order nobody anticipated must still read as a finding.
        // A dead link degrades; a missing row is a silent loss.
        $field = self::field(new InsightFindingDefinition(), 'conversationId');

        self::assertNotNull($field);
        self::assertFalse($field->is(Required::class));
    }

    public function testBothEntitiesNameTheirTables(): void
    {
        self::assertSame('swag_assistant_insight_run', InsightRunDefinition::ENTITY_NAME);
        self::assertSame('swag_assistant_insight_finding', InsightFindingDefinition::ENTITY_NAME);
    }

    private static function field(EntityDefinition $definition, string $propertyName): ?Field
    {
        $method = new \ReflectionMethod($definition::class, 'defineFields');
        /** @var FieldCollection $fields */
        $fields = $method->invoke($definition);

        foreach ($fields as $field) {
            if ($field->getPropertyName() === $propertyName) {
                return $field;
            }
        }

        return null;
    }
}
