import Plugin from 'src/plugin-system/plugin.class';
import HttpClient from 'src/service/http-client.service';

export default class AfterPayCheckoutConfirm extends Plugin {
    static options = {
        /**
         * @param string
         */
        pluginName: 'afterPayCheckoutConfirm',

        /**
         * @param string
         */
        privacyPolicyCheckboxSelector: '#afterpay_tos',

        /**
         * @param string
         */
        profileTrackingContainerSelector: '.afterpay--profile-tracking'
    };

    /**
     * Initialize the plugin
     */
    init() {
        let me = this;
        let $el = $(me.el);
        let opts = me.options;

        if ($el.find(opts.profileTrackingContainerSelector).length === 0 || $el.find(opts.privacyPolicyCheckboxSelector).length === 0) {
            return;
        }

        me._client = new HttpClient();

        me._handleAfterPayTosCheckbox($el.find(opts.privacyPolicyCheckboxSelector));
        me._registerEvents();

        me.$emitter.publish(opts.pluginName + '/init', {plugin: me});
    }

    _registerEvents() {
        let me = this;
        let $el = $(me.el);
        let opts = me.options;

        $el.find(opts.privacyPolicyCheckboxSelector).on('change', $.proxy(me._onPrivacyPolicyCheckboxChanged, me));

        me.$emitter.publish(opts.pluginName + '/onRegisterEvents', {plugin: me});
    }

    _onPrivacyPolicyCheckboxChanged(ev) {
        let me = this;
        let $checkbox = $(ev.currentTarget);

        me._handleAfterPayTosCheckbox($checkbox)
    }

    _handleAfterPayTosCheckbox($checkbox) {
        let me = this;
        let $el = $(me.el);
        let opts = me.options;

        if ($checkbox.is(":checked")) {
            me._client.get($checkbox.attr('data-tracking-url'), function (response) {
                $el.find(opts.profileTrackingContainerSelector).html(response);
            });
        } else {
            $el.find(opts.profileTrackingContainerSelector).html("");
        }
    }
}