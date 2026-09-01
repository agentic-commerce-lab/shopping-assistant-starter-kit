import template from './swag-assistant-shop-info-list.html.twig';
import {
    deleteRequest,
    errorDetail,
    indexPagesRequest,
    reindexAllRequest,
    reindexRequest,
    storeStatusRequest,
    uploadRequest,
} from './requests';
import './swag-assistant-shop-info-list.scss';

const { Criteria } = Shopware.Data;

/**
 * Shopware's built-in Storefront sales-channel type.
 *
 * A constant rather than a lookup by name: the type's *name* is translated and a merchant can rename
 * a channel, while this id is fixed platform data.
 */
const STOREFRONT_TYPE_ID = '8a243080f92e4c719546314b577cf82b';

/**
 * A stored boolean, read the way the server reads it.
 *
 * The config API returns `true` for a value written through the Administration and the STRING
 * `"true"` for one written by `system:config:set`, and `(Boolean) "false"` is `true` — the same trap
 * `StoredValueReader::bool()` exists for on the PHP side. A bare cast here would report a switched-off
 * feature as on.
 */
function isTrue(value) {
    return value === true || value === 'true' || value === 1 || value === '1';
}

/**
 * The documents the assistant may answer from, for one sales channel.
 *
 * **Scoped to a sales channel, always, with no "all channels" option.** Passages are stored per
 * channel because two channels can genuinely have different terms, and every retrieval filters on
 * it. A combined list would show a merchant rows they cannot act on coherently: uploading the same
 * file name into two channels produces two documents, and a single list would make that look like a
 * duplicate.
 */
Shopware.Component.register('swag-assistant-shop-info-list', {
    template,

    inject: ['repositoryFactory', 'acl', 'systemConfigApiService'],

    mixins: ['notification'],

    data() {
        return {
            documents: null,
            salesChannels: [],
            salesChannelId: null,
            // Read-only here. Both are plugin settings, and this page shows them because they
            // decide whether anything on this page does anything (spec R13) — not because they are
            // edited here.
            embeddingModel: '',
            shopKnowledgeEnabled: false,
            // Null until the first answer arrives, so the row can stay absent rather than flash a
            // wrong store name for one frame.
            storeStatus: null,
            isLoading: true,
            busyId: null,
            isUploading: false,
            uploadError: null,
            bulkAction: null,
        };
    },

    computed: {
        documentRepository() {
            return this.repositoryFactory.create('swag_assistant_document');
        },

        salesChannelRepository() {
            return this.repositoryFactory.create('sales_channel');
        },

        /**
         * Spec R13: an empty embedding model means the assistant has no shop-information tool at all.
         *
         * Worth its own state rather than an empty table, because the two look identical and mean
         * opposite things — "nothing uploaded yet" is a task, "the feature is off" is a setting.
         */
        isSwitchedOff() {
            return !this.shopKnowledgeEnabled || this.embeddingModel === '';
        },

        /**
         * WHICH kind of off, because the two need different sentences and only one of them is a task.
         *
         * `enableShopKnowledge` false is a decision the merchant made and can undo in one click; an
         * empty model on an enabled feature is an unfinished setup. Telling a merchant to "set an
         * embedding model" when they deliberately switched the whole feature off sends them to fix
         * something that is not broken.
         */
        offReason() {
            if (!this.shopKnowledgeEnabled) {
                return 'disabled';
            }

            return this.embeddingModel === '' ? 'noModel' : null;
        },

        offTitle() {
            return this.$tc(`swag-assistant-shop-info.list.off.${this.offReason}Title`);
        },

        offBody() {
            return this.$tc(`swag-assistant-shop-info.list.off.${this.offReason}Body`);
        },

        /**
         * Mirrors the server's own bound, which is the authority — see `ShopInfoUpload::MAX_BYTES`.
         *
         * Duplicated deliberately rather than fetched: a client-side limit that has to be loaded
         * before it applies is a limit that does not apply on the first upload.
         */
        maxFileSize() {
            return 8 * 1024 * 1024;
        },

        /**
         * The five formats spec R10 admits, as mime types, so the file picker filters to them.
         *
         * Markdown is listed twice because browsers disagree about `.md`: Chrome reports
         * `text/markdown`, others report `text/plain` or nothing at all. Omitting either makes a
         * perfectly indexable file unselectable.
         */
        allowedMimeTypes() {
            return [
                'application/pdf',
                'text/plain',
                'text/markdown',
                'text/html',
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            ];
        },

        /**
         * Where the model is actually configured.
         *
         * A route rather than a copied path: the extension config page owns its own URL, and a
         * hand-written `#/sw/extension/config/...` is a link that breaks silently on an upgrade.
         */
        pluginSettingsRoute() {
            return { name: 'sw.extension.config', params: { namespace: 'SwagAssistantStarterKit' } };
        },

        salesChannelOptions() {
            return this.salesChannels.map((channel) => ({ value: channel.id, label: channel.name }));
        },

        documentColumns() {
            return [
                { property: 'name', label: 'swag-assistant-shop-info.list.columnName', primary: true },
                { property: 'source', label: 'swag-assistant-shop-info.list.columnSource' },
                { property: 'status', label: 'swag-assistant-shop-info.list.columnStatus' },
                { property: 'chunkCount', label: 'swag-assistant-shop-info.list.columnChunks', align: 'right' },
                { property: 'dimension', label: 'swag-assistant-shop-info.list.columnDimension', align: 'right' },
                { property: 'updatedAt', label: 'swag-assistant-shop-info.list.columnUpdated' },
            ];
        },
    },

    created() {
        this.loadSalesChannels();
    },

    methods: {
        async loadSalesChannels() {
            const criteria = new Criteria(1, 100);
            criteria.addSorting(Criteria.sort('name', 'ASC'));
            criteria.addFilter(Criteria.equals('active', true));

            this.salesChannels = await this.salesChannelRepository.search(criteria, Shopware.Context.api);
            this.salesChannelId = this.defaultSalesChannelId();

            await this.onSalesChannelChange(this.salesChannelId);
        },

        /**
         * The channel to land on, which is deliberately not "the first one alphabetically".
         *
         * Measured the hard way on the lab shop: a demo install has a Headless channel and a
         * Storefront channel, "Headless" sorts first, and a document uploaded on arrival went to the
         * channel with no storefront on it. Everything reported success — indexed, twelve passages —
         * and the widget could not see any of it, because documents and the embedding model are both
         * per channel (spec R12). A wrong default here is invisible in exactly the way that costs an
         * hour.
         *
         * A storefront channel is the answer when there is one: it is the only kind that has a widget
         * for a shopper to type into. When there is not, the first active channel is as good a guess
         * as exists, and the dropdown is right there.
         */
        defaultSalesChannelId() {
            const storefront = this.salesChannels.find(
                (channel) => channel.typeId === STOREFRONT_TYPE_ID,
            );

            const chosen = storefront || this.salesChannels[0];

            return chosen ? chosen.id : null;
        },

        async onSalesChannelChange(salesChannelId) {
            this.salesChannelId = salesChannelId;
            this.uploadError = null;

            await Promise.all([this.loadShopKnowledgeConfig(), this.loadDocuments(), this.loadStoreStatus()]);
        },

        /**
         * **The config API does not inherit; the PHP side does.** `getValues(domain, salesChannelId)`
         * returns only the values overridden FOR that channel — a shop configured once, globally,
         * comes back empty. `SystemConfigService::getString()` in the storefront falls back to the
         * global row, so retrieval worked while this screen reported the feature switched off and
         * disabled every control on it. Measured on a live shop: eight indexed documents listed below
         * a notice saying no embedding model was configured.
         *
         * So both scopes are read and merged, channel over global — the same order the server
         * resolves them in. A key present in the channel response wins even when it is empty, because
         * clearing a value for one channel is a deliberate act and must not fall back.
         */
        async loadShopKnowledgeConfig() {
            if (!this.salesChannelId) {
                return;
            }

            const [global, channel] = await Promise.all([
                this.systemConfigApiService.getValues('SwagAssistantStarterKit.config', null),
                this.systemConfigApiService.getValues('SwagAssistantStarterKit.config', this.salesChannelId),
            ]);

            const config = { ...global, ...channel };

            this.embeddingModel = (config['SwagAssistantStarterKit.config.embeddingModel'] || '').trim();
            this.shopKnowledgeEnabled = isTrue(config['SwagAssistantStarterKit.config.enableShopKnowledge']);
        },

        /**
         * Spec D6's other half: the fallback is announced to the merchant, not only to the trace.
         *
         * Failure is silent on purpose. This row explains the page; it does not run it, and a shop
         * whose status cannot be read still has a working upload form above. Taking the screen down
         * over a diagnostic would be the opposite of what the diagnostic is for.
         */
        async loadStoreStatus() {
            const { url, options } = storeStatusRequest(Shopware.Context.api);

            try {
                const response = await fetch(url, options);

                this.storeStatus = response.ok ? await response.json() : null;
            } catch (failure) {
                this.storeStatus = null;
            }
        },

        async loadDocuments() {
            if (!this.salesChannelId) {
                return;
            }

            this.isLoading = true;

            const criteria = new Criteria(1, 50);
            criteria.addFilter(Criteria.equals('salesChannelId', this.salesChannelId));
            criteria.addSorting(Criteria.sort('name', 'ASC'));

            try {
                this.documents = await this.documentRepository.search(criteria, Shopware.Context.api);
            } finally {
                this.isLoading = false;
            }
        },

        async onFileSelected(file) {
            this.uploadError = null;
            this.isUploading = true;

            const { url, options } = uploadRequest(Shopware.Context.api, file, this.salesChannelId);

            try {
                const result = await this.send(url, options);

                this.createNotificationSuccess({
                    message: this.$tc('swag-assistant-shop-info.list.uploaded', 0, {
                        name: file.name,
                        count: result.chunkCount,
                    }),
                });

                await this.loadDocuments();
            } catch (error) {
                // Shown inline beside the upload control rather than as a toast: a toast for "this PDF
                // is a scan and has no text layer" disappears before the merchant has finished reading
                // which file it meant.
                this.uploadError = error.message;
            } finally {
                this.isUploading = false;
            }
        },

        /**
         * Index the shop's own legal pages, and index everything again.
         *
         * One handler, because the only difference is which request is sent and which sentence is
         * reported. Both report counts rather than "done": a merchant who sees "4 indexed, 1 failed"
         * knows to look at the table, and one who sees "done" beside a failed row does not.
         */
        async onBulk(action) {
            const build = action === 'pages' ? indexPagesRequest : reindexAllRequest;
            this.bulkAction = action;

            const { url, options } = build(Shopware.Context.api, this.salesChannelId);

            try {
                const result = await this.send(url, options);

                this.reportBulk(result);
                await this.loadDocuments();
            } catch (error) {
                this.createNotificationError({ message: error.message });
            } finally {
                this.bulkAction = null;
            }
        },

        /**
         * A count, and the reasons when there are any.
         *
         * Failures are named individually rather than counted, because each one has a different cause
         * and the merchant can only act on the specific reason. Skipped pages are said out loud too:
         * a configured page with nothing on it is not an error, but silently indexing four of five
         * pages would leave a merchant believing all five are searchable.
         */
        reportBulk(result) {
            const failed = result.failed || [];
            const skipped = result.skipped || [];

            if (failed.length) {
                this.createNotificationError({
                    message: failed.map((f) => `${f.name}: ${f.reason}`).join(' — '),
                });
            }

            if (skipped.length) {
                this.createNotificationWarning({
                    message: this.$tc('swag-assistant-shop-info.list.bulkSkipped', 0, {
                        names: skipped.join(', '),
                    }),
                });
            }

            if (!failed.length) {
                this.createNotificationSuccess({
                    message: this.$tc('swag-assistant-shop-info.list.bulkIndexed', 0, {
                        count: result.indexed || 0,
                    }),
                });
            }
        },

        sourceLabel(source) {
            return this.$tc(
                source === 'cms'
                    ? 'swag-assistant-shop-info.list.sourceCms'
                    : 'swag-assistant-shop-info.list.sourceUpload',
            );
        },

        async onReindex(document) {
            await this.act(document, reindexRequest, 'swag-assistant-shop-info.list.reindexed');
        },

        async onDelete(document) {
            await this.act(document, deleteRequest, 'swag-assistant-shop-info.list.deleted');
        },

        /**
         * One row action, with the row marked busy for exactly as long as the request runs.
         *
         * `busyId` is a single id and not a flag: disabling every row's buttons because one is
         * re-indexing would read as the page having frozen.
         */
        async act(document, buildRequest, successSnippet) {
            this.busyId = document.id;

            const { url, options } = buildRequest(Shopware.Context.api, document.id);

            try {
                const result = await this.send(url, options);

                this.createNotificationSuccess({
                    message: this.$tc(successSnippet, 0, {
                        name: document.name,
                        count: result.chunkCount || 0,
                    }),
                });

                await this.loadDocuments();
            } catch (error) {
                this.createNotificationError({ message: error.message });
            } finally {
                this.busyId = null;
            }
        },

        /**
         * Sends a write request and turns an API refusal into an Error carrying the reason.
         *
         * The controller answers 422 with a merchant-facing detail for an indexing failure, and that
         * sentence is the whole value of the response — a generic "request failed" would leave the
         * merchant with a failed row and no reason.
         */
        async send(url, options) {
            const response = await fetch(url, options);
            const payload = await response.json().catch(() => null);

            if (!response.ok) {
                throw new Error(errorDetail(payload) || this.$tc('global.notification.unspecifiedSaveErrorMessage'));
            }

            return payload || {};
        },

        statusVariant(status) {
            if (status === 'indexed') {
                return 'success';
            }

            return status === 'failed' ? 'danger' : 'neutral';
        },

        statusLabel(status) {
            const known = { indexed: 'statusIndexed', failed: 'statusFailed', pending: 'statusPending' };

            return this.$tc(`swag-assistant-shop-info.list.${known[status] || 'statusPending'}`);
        },
    },
});
