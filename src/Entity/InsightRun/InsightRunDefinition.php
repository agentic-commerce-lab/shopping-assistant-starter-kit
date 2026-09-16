<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Entity\InsightRun;

use Shopware\Core\Framework\DataAbstractionLayer\EntityDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\Field\DateTimeField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\PrimaryKey;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\Required;
use Shopware\Core\Framework\DataAbstractionLayer\Field\IdField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\JsonField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\OneToManyAssociationField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\StringField;
use Shopware\Core\Framework\DataAbstractionLayer\FieldCollection;
use Swag\AssistantStarterKit\Entity\InsightFinding\InsightFindingDefinition;

/**
 * `swag_assistant_insight_run` — one nightly run: its window, its counts, its sample seed.
 *
 * **`metrics` and `searchTerms` are two columns on purpose** (D23). The counts name nobody and
 * survive retention, because a trend a merchant can read over months is the whole reason this table
 * exists. The terms are what shoppers typed, and they are emptied when their conversations are
 * pruned. A single blob could not be pruned by half.
 *
 * `sampleSeed` is stored so a surprising night can be judged again over the same conversations,
 * with a different model or after a change to the prompt. A sample nobody can redraw is a finding
 * nobody can check.
 *
 * `judgeError` is nullable and its presence is the difference between "the judge found nothing" and
 * "the judge did not answer" — two states that look identical in a dashboard and mean the opposite.
 */
class InsightRunDefinition extends EntityDefinition
{
    public const ENTITY_NAME = 'swag_assistant_insight_run';

    public function getEntityName(): string
    {
        return self::ENTITY_NAME;
    }

    public function getEntityClass(): string
    {
        return InsightRunEntity::class;
    }

    public function getCollectionClass(): string
    {
        return InsightRunCollection::class;
    }

    protected function defineFields(): FieldCollection
    {
        return new FieldCollection([
            (new IdField('id', 'id'))->addFlags(new PrimaryKey(), new Required()),
            (new DateTimeField('window_start', 'windowStart'))->addFlags(new Required()),
            (new DateTimeField('window_end', 'windowEnd'))->addFlags(new Required()),
            (new JsonField('metrics', 'metrics'))->addFlags(new Required()),
            new JsonField('search_terms', 'searchTerms'),
            new StringField('sample_seed', 'sampleSeed'),
            new StringField('judge_error', 'judgeError'),
            new OneToManyAssociationField('findings', InsightFindingDefinition::class, 'run_id'),
        ]);
    }
}
