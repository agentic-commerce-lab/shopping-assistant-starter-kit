<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\ShopInfo;

/**
 * One uploaded shop document, as anything above the database sees it (spec R9).
 *
 * The identity spec R8 needs: without it, a merchant who uploads a corrected revocation notice has
 * both versions in the store and retrieval can serve either.
 *
 * **The extracted text is stored, not the bytes.** Re-indexing then needs no file access, and the
 * question of where uploaded files live never has to be answered. The cost is that the original is
 * not recoverable from here, which is the right trade: the original is on the merchant's disk, and
 * what retrieval works from is the text.
 */
final readonly class ShopInfoDocument
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_INDEXED = 'indexed';

    public const STATUS_FAILED = 'failed';

    /** A file a merchant uploaded. Re-indexed from {@see self::$text}, because the file is not kept. */
    public const SOURCE_UPLOAD = 'upload';

    /**
     * One of the shop's own CMS pages — imprint, privacy, revocation, terms, shipping.
     *
     * Re-indexed by reading the page again rather than from {@see self::$text}, and that difference is
     * the whole reason this field exists: a merchant who edits their revocation page in the CMS and
     * presses re-index expects the new wording, not the wording that was extracted last week.
     */
    public const SOURCE_CMS = 'cms';

    // @mago-expect lint:excessive-parameter-list
    // A record, not a behaviour: every field is an independent column that the admin list in Part 2
    // shows in its own right, and grouping them behind a sub-object would move the same flat fields
    // one layer further from the reader without removing one of them. Call sites use named
    // arguments, which is the condition ruling R17 attaches to this pragma.
    public function __construct(
        public string $id,
        public string $name,
        public string $extension,
        public string $salesChannelId,
        public string $status,
        public string $statusReason = '',
        public int $chunkCount = 0,
        public int $dimension = 0,
        public string $text = '',
        public string $source = self::SOURCE_UPLOAD,
    ) {}
}
