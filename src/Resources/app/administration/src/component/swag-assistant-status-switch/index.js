import template from './swag-assistant-status-switch.html.twig';
import './swag-assistant-status-switch.scss';

/**
 * The assistant's off switch, rendered by `config.xml` in place of a plain `bool` field.
 *
 * Two things a plain field could not do, and both are the reason this component exists.
 *
 * **It states the direction the switch is drawn in.** The stored setting used to be `killSwitch`,
 * where ON meant stopped — so the blue, active-looking position was the one that had killed the
 * product. The value is now `assistantEnabled` and blue means running, which is what every other
 * switch in the Administration means.
 *
 * **It asks before it acts.** Turning this off stops every reply in every sales channel this
 * setting covers, and the effect is immediate and invisible from the settings page — a merchant who
 * flips it by accident finds out from their shoppers. Everything else in this form is a preference
 * that changes the next reply; this is the one that decides whether there is one.
 *
 * The switch is *controlled*: `model-value` is read from the prop and nothing is emitted until the
 * dialog is confirmed, so cancelling leaves both the stored value and the rendered switch where they
 * were. `switchKey` forces a remount on cancel, because a component that kept internal state would
 * otherwise sit in the position the merchant just backed out of.
 */
Shopware.Component.register('swag-assistant-status-switch', {
    template,

    props: {
        value: {
            type: Boolean,
            required: false,
            // Absent means enabled, matching SystemConfigAssistantConfig's `boolOr` default. A
            // `false` here would show a shop that has never opened this form as switched off.
            default: true,
        },
        disabled: {
            type: Boolean,
            required: false,
            default: false,
        },
    },

    emits: ['update:value'],

    data() {
        return {
            // `null` when no dialog is open. Deliberately not a boolean pair of "modal open" and
            // "target value": one field cannot disagree with itself about which way it is going.
            pending: null,
            switchKey: 0,
        };
    },

    computed: {
        running() {
            return this.value !== false;
        },

        confirming() {
            return this.pending !== null;
        },

        /**
         * Stopping and starting are not the same question, so they do not get the same dialog. The
         * suffix picks a title, a body and a button label from the snippet file at once, which keeps
         * the three of them from drifting into describing different actions.
         */
        dialogSuffix() {
            return this.pending === false ? 'Stop' : 'Start';
        },
    },

    methods: {
        onToggle(next) {
            if (next === this.running) {
                return;
            }

            this.pending = next;
        },

        onConfirm() {
            this.$emit('update:value', this.pending);
            this.pending = null;
        },

        onCancel() {
            this.pending = null;
            this.switchKey += 1;
        },
    },
});
