/**
 * Read-only by construction: there is no editor or creator role, because the view has no write
 * path and a trace is a record of what happened, not a document.
 */
Shopware.Service('privileges').addPrivilegeMappingEntry({
    category: 'permissions',
    parent: null,
    key: 'swag_assistant_conversation',
    roles: {
        viewer: {
            privileges: [
                'swag_assistant_conversation:read',
                'swag_assistant_trace_event:read',
            ],
            dependencies: [],
        },
    },
});
