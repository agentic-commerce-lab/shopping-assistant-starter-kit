import template from './swag-assistant-shop-info-list.html.twig';
import { deleteRequest, errorDetail, reindexRequest, uploadRequest } from './requests';
import './swag-assistant-shop-info-list.scss';

const { Criteria } = Shopware.Data;

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
            embeddingModel: '',
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

            this.salesChannels = await this.salesChannelRepository.search(criteria, Shopware.Context.api);
            this.salesChannelId = this.salesChannels[0] ? this.salesChannels[0].id : null;

            await this.onSalesChannelChange(this.salesChannelId);
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
