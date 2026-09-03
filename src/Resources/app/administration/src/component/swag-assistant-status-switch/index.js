import template from './swag-assistant-status-switch.html.twig';
import './swag-assistant-status-switch.scss';
import { statusState } from './state';

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
 *
 * **It reports three states, and it used to report two.** `running` was `this.value !== false` and
 * nothing else, while `assistantEnabled` defaults to on — so a shop with no model configured showed
 * a green "Running" and *"each reply spends model credit on your account"* with `/assistant/chat`
 * answering 503 and no orb in the storefront. Measured 2026-09-03; the worst audience for it is a
 * fresh install, where every word of that card is wrong. Whether a model exists is asked of the
 * server, because environment variables beat stored config and appear in no field of this form —
 * see {@see AssistantReadiness} on the PHP side. {@see statusState} owns the three-way choice.
 *
 * The answer is fetched once, on mount. It does not follow the fields as the merchant types, and
 * deliberately: an unsaved model is not a configured one, and a card that went green before Save
 * would promise what the next shopper cannot get. Saving reloads the settings page.
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
            // `null` until the check answers, and `null` again if it cannot: an unknown reads as
            // Running rather than inventing a worse state than the merchant configured.
            configured: null,
            // `null` when no dialog is open. Deliberately not a boolean pair of "modal open" and
            // "target value": one field cannot disagree with itself about which way it is going.
            pending: null,
            switchKey: 0,
        };
    },

    created() {
        this.loadReadiness();
    },

    computed: {
        state() {
            return statusState(this.value, this.configured);
        },

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
        /**
         * Failure is silent, the same rule the shop-info screen's store status follows: this is a
         * diagnostic, and a shop whose diagnostic cannot run still has a working settings form.
         */
        async loadReadiness() {
            const api = Shopware.Context.api;

            try {
                const response = await fetch(`${api.apiPath}/_action/swag-assistant/status`, {
                    method: 'GET',
                    headers: { Authorization: `Bearer ${api.authToken.access}` },
                });

                const body = response.ok ? await response.json() : null;

                this.configured = typeof body?.configured === 'boolean' ? body.configured : null;
            } catch (failure) {
                this.configured = null;
            }
        },

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
