import template from './swag-assistant-shop-info-list.html.twig';
import { deleteRequest, errorDetail, reindexRequest, uploadRequest } from './requests';
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
            // The saved value, which is what decides whether the feature is on. Kept apart from
            // `modelDraft` so the "switched off" notice does not flicker while a merchant types.
            embeddingModel: '',
            modelDraft: '',
            isSavingModel: false,
            isLoading: true,
            busyId: null,
            isUploading: false,
            uploadError: null,
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
            return this.embeddingModel === '';
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

        /** Whether the field holds something other than what is saved. Drives the save button. */
        isModelDirty() {
            return (this.modelDraft || '').trim() !== this.embeddingModel;
        },

        salesChannelOptions() {
            return this.salesChannels.map((channel) => ({ value: channel.id, label: channel.name }));
        },

        documentColumns() {
            return [
                { property: 'name', label: 'swag-assistant-shop-info.list.columnName', primary: true },
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

            await Promise.all([this.loadEmbeddingModel(), this.loadDocuments()]);
        },

        async loadEmbeddingModel() {
            if (!this.salesChannelId) {
                return;
            }

            const config = await this.systemConfigApiService.getValues(
                'SwagAssistantStarterKit.config',
                this.salesChannelId,
            );

            this.embeddingModel = (config['SwagAssistantStarterKit.config.embeddingModel'] || '').trim();
            this.modelDraft = this.embeddingModel;
        },

        /**
         * Saves the embedding model for this channel, immediately.
         *
         * **Immediately, rather than behind a smart-bar Save.** Everything else on this page acts at
         * once — an upload indexes, a delete deletes — and mixing "this happened" with "this will
         * happen when you save" on one screen is how a merchant ends up uploading against a model
         * they thought they had changed.
         *
         * Changing it while documents exist is the one case that needs saying out loud: their vectors
         * were produced by the old model and the store refuses to mix widths, so they have to be
         * indexed again. The alternative to warning here is a merchant discovering it from a failed
         * re-index later.
         */
        async onEmbeddingModelChange() {
            const next = (this.modelDraft || '').trim();

            if (next === this.embeddingModel) {
                return;
            }

            this.isSavingModel = true;

            try {
                await this.systemConfigApiService.saveValues(
                    { 'SwagAssistantStarterKit.config.embeddingModel': next === '' ? null : next },
                    this.salesChannelId,
                );

                const had = this.embeddingModel;
                this.embeddingModel = next;

                if (had !== '' && next !== '' && this.documents && this.documents.total) {
                    this.createNotificationWarning({
                        message: this.$tc('swag-assistant-shop-info.list.modelChangedReindex'),
                    });
                } else {
                    this.createNotificationSuccess({
                        message: this.$tc('swag-assistant-shop-info.list.modelSaved'),
                    });
                }
            } catch (error) {
                this.modelDraft = this.embeddingModel;
                this.createNotificationError({
                    message: this.$tc('global.notification.unspecifiedSaveErrorMessage'),
                });
            } finally {
                this.isSavingModel = false;
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
