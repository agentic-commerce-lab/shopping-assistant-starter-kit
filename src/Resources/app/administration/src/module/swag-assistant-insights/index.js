import './acl';

/*
 * Only the ACL so far. `Shopware.Module.register()` arrives with the dashboard page in the next
 * slice — registering a route now would point the navigation at a component that does not exist
 * yet and break the administration build, and a privilege mapping is useful on its own: an
 * administrator can grant the right before there is a page behind it, which is the order a shop
 * with a review process actually works in.
 */
