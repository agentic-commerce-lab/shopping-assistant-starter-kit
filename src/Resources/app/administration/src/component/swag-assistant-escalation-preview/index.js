import template from './swag-assistant-escalation-preview.html.twig';
import './swag-assistant-escalation-preview.scss';

/**
 * Shows what "hand a conversation to a human" actually looks like to a shopper.
 *
 * The setting was the hardest one in the form to picture, and the help text could not fix that on
 * its own: the behaviour has **two** outcomes that differ entirely depending on whether a
 * destination is set, and the difference is invisible until a real shopper hits it. Both are shown
 * side by side, so the URL field above stops being a blank box and becomes a choice between two
 * replies the merchant can read.
 *
 * ## It also corrects a reasonable wrong assumption
 *
 * "Hand a conversation to a human" sounds like something is sent. Nothing is. No ticket is opened,
 * no email goes out, nobody is notified — the assistant declines and shows a link, and the shopper
 * is the one who follows it. A merchant who believes otherwise will leave a support queue unwatched.
 *
 * That is not a hypothetical. `EscalateTool`'s prompt spells out the prohibition — do not say you
 * have passed this on, flagged it, forwarded it, or that someone will follow up — because a live
 * model produced exactly those claims in six of six runs of `order_status_escalates`. The banner
 * here tells the merchant the same thing the model is told, so the two cannot disagree.
 *
 * ## Static on purpose
 *
 * It renders neither the merchant's own URL nor their own message. Reading live form state means
 * reaching into `sw-system-config`'s internals, and the point being made is about *behaviour*, not
 * about the current field values — a preview showing one state, the one already configured, would
 * teach the merchant less than one showing both.
 */
Shopware.Component.register('swag-assistant-escalation-preview', {
    template,
});
