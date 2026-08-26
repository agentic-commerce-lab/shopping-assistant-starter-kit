/**
 * Three roles, because the three actions carry different consequences.
 *
 * A viewer sees which documents the assistant can read. An editor uploads and re-indexes. A deleter
 * removes a document *and its passages* — the only one of the three that can silently narrow what the
 * assistant knows, which is why it is not folded into `editor`.
 */
Shopware.Service('privileges').addPrivilegeMappingEntry({
    category: 'permissions',
    parent: null,
    key: 'swag_assistant_document',
    roles: {
        viewer: {
            privileges: ['swag_assistant_document:read'],
            dependencies: [],
        },
        editor: {
            privileges: [
                'swag_assistant_document:update',
                'swag_assistant_document:create',
            ],
            dependencies: ['swag_assistant_document.viewer'],
        },
        deleter: {
            privileges: ['swag_assistant_document:delete'],
            dependencies: ['swag_assistant_document.viewer'],
        },
    },
});
