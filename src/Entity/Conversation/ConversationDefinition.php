<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Entity\Conversation;

use Shopware\Core\Checkout\Customer\CustomerDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\EntityDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\Field\FkField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\PrimaryKey;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\Required;
use Shopware\Core\Framework\DataAbstractionLayer\Field\IdField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\IntField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\JsonField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\ManyToOneAssociationField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\OneToManyAssociationField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\StringField;
use Shopware\Core\Framework\DataAbstractionLayer\FieldCollection;
use Swag\AssistantStarterKit\Entity\TraceEvent\TraceEventDefinition;

/**
 * `swag_assistant_conversation` — the conversation memory and the trace's parent, in one table.
 *
 * **One table, two readers — do not build a second one** (`ARCHITECTURE.md`). The widget re-hydrates
 * from `transcript`; the merchant reads the associated trace events.
 *
 * A written `EntityDefinition` rather than a custom entity, and that is a correction rather than a
 * preference (ruling R78): `entities.xml` custom entities are registered exclusively by
 * `AppManager`, so they are an **App** feature. D1 chose a plugin, so this is the only path that
 * exists — and it restores the table names `ARCHITECTURE.md` documented from the start.
 *
 * **`transcript` is readable over the admin API, and that is the point.** Every field here is
 * `ApiAware(AdminApiSource::class)` by the framework default — `Field::__construct()` applies it,
 * and `setFlags()` re-adds it if the list is cleared, so declaring no flag closes nothing. Nothing
 * is readable over `/store-api/`.
 *
 * This class previously claimed "No field declared here carries `ApiAware`" and concluded the
 * entities were closed. They never were. On 2026-08-21 the transcript was briefly stripped with
 * `removeFlag()` on the strength of that docblock, and the Administration trace view immediately
 * proved it wrong in the other direction: **the trace events do not contain the conversation.**
 * `understand` records the parsed search term, `render` records rendered ids — nowhere is the
 * shopper's question or the assistant's reply. The transcript is the only record of the dialogue,
 * and "the merchant sees every conversation in the Administration" is the product promise.
 *
 * What actually protects a shopper here is the source scope (admin only, never the storefront), the
 * ACL on the module, and `PruneConversationsTask` bounding how long any of it is kept — not hiding
 * the record from the merchant whose shop produced it.
 */
class ConversationDefinition extends EntityDefinition
{
    public const ENTITY_NAME = 'swag_assistant_conversation';

    public function getEntityName(): string
    {
        return self::ENTITY_NAME;
    }

    public function getEntityClass(): string
    {
        return ConversationEntity::class;
    }

    public function getCollectionClass(): string
    {
        return ConversationCollection::class;
    }

    protected function defineFields(): FieldCollection
    {
        return new FieldCollection([
            (new IdField('id', 'id'))->addFlags(new PrimaryKey(), new Required()),
            (new StringField('sales_channel_id', 'salesChannelId'))->addFlags(new Required()),
            // A real foreign key, unlike `sales_channel_id` above — that column is a plain string
            // the DAL cannot join, which is why the Administration resolves channel names through a
            // client-side map. This one can be joined, so the list and the export read the
            // customer's name through the association instead.
            //
            // The constraint is `ON DELETE SET NULL`: a customer who deletes their account takes
            // their link with them, and the conversation stays. See the migration.
            new FkField('customer_id', 'customerId', CustomerDefinition::class),
            new StringField('locale', 'locale'),
            new IntField('turn_count', 'turnCount'),
            new StringField('outcome', 'outcome'),
            // Accumulated by `DalConversationStore::append()` from the recorder's own last offset,
            // so it always agrees with the last row of the Administration's timeline. It claimed to
            // be "measured with a wall clock around the runner call" while `start()` wrote a literal
            // 0 that nothing ever updated — every conversation read 0ms until 2026-08-21.
            // `first_token_ms` still has no equivalent: that is a streaming metric, and streaming is
            // cut (R62).
            new IntField('total_ms', 'totalMs'),
            new JsonField('transcript', 'transcript'),
            new ManyToOneAssociationField('customer', 'customer_id', CustomerDefinition::class, 'id', false),
            new OneToManyAssociationField('events', TraceEventDefinition::class, 'conversation_id'),
        ]);
    }
}
