/**
 * Its own right, deliberately not folded into `swag_assistant_conversation`.
 *
 * A run row holds counts and names nobody; a finding quotes a shopper. A shop may well want its
 * merchandising staff reading the numbers and the suggestions while raw conversations stay with
 * whoever administers the plugin — one right for both would force that choice the wrong way, and
 * the wrong way is the permissive one.
 *
 * Read-only by construction, like the trace view: there is no editor or creator role because the
 * page has no write path. A finding is a record of what a judge said, not a document.
 */
Shopware.Service('privileges').addPrivilegeMappingEntry({
    category: 'permissions',
    parent: null,
    key: 'swag_assistant_insight',
    roles: {
        viewer: {
            privileges: [
                'swag_assistant_insight_run:read',
                'swag_assistant_insight_finding:read',
            ],
            /*
             * The conversation read right is a dependency rather than a bundled privilege: a
             * finding links into the trace detail, and a viewer who cannot open that link gets a
             * dead end. Declaring it as a dependency lets an administrator see WHY the second
             * right is needed instead of granting it silently.
             */
            dependencies: [
                'swag_assistant_conversation',
            ],
        },
    },
});
