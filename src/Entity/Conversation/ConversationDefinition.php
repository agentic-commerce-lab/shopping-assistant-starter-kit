<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Entity\Conversation;

use Shopware\Core\Framework\DataAbstractionLayer\EntityDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\ApiAware;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\PrimaryKey;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\Required;
use Shopware\Core\Framework\DataAbstractionLayer\Field\IdField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\IntField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\JsonField;
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
 * **`transcript` is closed with `removeFlag(ApiAware::class)`, and that call is load-bearing.**
 * `Field::__construct()` adds `new ApiAware(AdminApiSource::class)` to *every* field, and
 * `setFlags()` puts it back if you clear the list — so a field is admin-API readable unless it is
 * explicitly stripped. Declaring no flag does **not** close a field; it was believed to until
 * 2026-08-21, and `transcript` was readable over `/api/` that whole time.
 *
 * The transcript is the verbatim, complete, replayable conversation record and the widget's
 * re-hydration source. The merchant reads the associated trace events instead — the "one table, two
 * readers" split above. Note what closing it does *not* buy: the `understand` stage's payload
 * carries the shopper's message, so an admin with the ACL can still reconstruct most of a
 * conversation. That is the feature; hiding it would defeat the trace view.
 *
 * Removing the flag does not affect the widget: `read_protected` is enforced in the API layer, and
 * `DalConversationStore::history()` reads through the DAL directly.
 *
 * Everything else stays admin-only by the framework default. Nothing here is readable over
 * `/store-api/`, and nothing may become so — see `TraceEventApiExposureTest`.
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
            new StringField('locale', 'locale'),
            new IntField('turn_count', 'turnCount'),
            new StringField('outcome', 'outcome'),
            // Measured with a wall clock around the runner call, so it is honest. `first_token_ms`
            // has no equivalent here: it is a streaming metric and streaming is cut (R62).
            new IntField('total_ms', 'totalMs'),
            // Closed deliberately — see the class docblock. Without this call the field is
            // admin-API readable, because every Field is ApiAware(AdminApiSource) by default.
            (new JsonField('transcript', 'transcript'))->removeFlag(ApiAware::class),
            new OneToManyAssociationField('events', TraceEventDefinition::class, 'conversation_id'),
        ]);
    }
}
