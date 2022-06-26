import Plugin from 'src/plugin-system/plugin.class';
import HttpClient from 'src/service/http-client.service';

export default class AfterPayPaymentSelection extends Plugin {
    static options = {
        /**
         * @param string
         */
        pluginName: 'afterPayPaymentSelection',

        /**
         * @param string
         */
        paymentMethodInputSelector: '.payment-method-input',

        /**
         * @param string
         */
        additionalFieldsSelector: '.payment-method-additional-fields',

        /**
         * @param string
         */
        confirmPaymentFormSelector: '#confirmPaymentForm, .account-payment form',

        /**
         * @param string
         */
        afterpayPaymentFormSelector: '*[data-afterpay-payment-form="true"]',

        /**
         * @param string
         */
        installmentsContainerSelector: '*[data-installments="true"]',

        /**
         * @param string
         */
        installmentOptionSelector: '.installment--plan',

        /**
         * @param string
         */
        installmentInformationSelector: '.installment--information',

        /**
         * @param string
         */
        customControlInputSelector: '.custom-control-input',

        /**
         * @param string
         */
        requiredFieldSelector: '.is-required',

        /**
         * @param string
         */
        isInvalidClassName: 'is-invalid'
    };

    /**
     * Initialize the plugin
     */
    init() {
        let me = this;
        let $el = $(me.el);
        let opts = me.options;

        if ($el.hasClass("initialized")) {
            return;
        }
        $el.addClass("initialized");

        me._client = new HttpClient();

        me._registerEvents();

        me._showHideFields();

        me._loadInstallments();

        me.$emitter.publish(opts.pluginName + '/init', {plugin: me});
    }

    _registerEvents() {
        let me = this;
        let opts = me.options;

        $(opts.confirmPaymentFormSelector).on('submit', $.proxy(me._onPaymentFormSubmit, me))
        $(opts.paymentMethodInputSelector).on('change', $.proxy(me._onPaymentMethodChange, me));

        me.$emitter.publish(opts.pluginName + '/onRegisterEvents', {plugin: me});
    }

    _registerInstallmentEvents() {
        let me = this;
        let $el = $(me.el);
        let opts = me.options;

        $el.find(opts.installmentsContainerSelector).find(opts.customControlInputSelector).on('change', $.proxy(me._onInstallmentChange, me))
    }

    _onPaymentFormSubmit(ev) {
        let me = this;
        let $el = $(me.el);
        let opts = me.options;

        if (me._isSelected() && $el.find(opts.afterpayPaymentFormSelector).length > 0) {
            ev.preventDefault();

            let valid = me._validateForm();
            if (!valid) {
                return false;
            }

            let $form = $el.find(opts.afterpayPaymentFormSelector);

            let params = {};
            let paramsArray = $form.find("select, textarea, input").serializeArray();
            $.each(paramsArray, function () {
                if (params[this.name]) {
                    if (!params[this.name].push) {
                        params[this.name] = [params[this.name]];
                    }
                    params[this.name].push(this.value || '');
                } else {
                    params[this.name] = this.value || '';
                }
            });

            me._client.post($form.attr('data-save-url'), JSON.stringify(params), function (response) {
                response = JSON.parse(response);
                if (response.success) {
                    ev.currentTarget.submit();
                } else {
                    // handle error
                }
            });

            return false;
        }
    }

    _onPaymentMethodChange() {
        let me = this;

        me._showHideFields();
    }

    _onInstallmentChange(ev) {
        let me = this;
        let $el = $(me.el);
        let opts = me.options;
        let $input = $(ev.currentTarget);

        let plan = $input.parents(opts.installmentOptionSelector);
        $el.find(opts.installmentOptionSelector).removeClass("active");
        plan.addClass('active');

        $el.find(opts.installmentInformationSelector).removeClass('d-none');
        $el.find(opts.installmentInformationSelector).find(".interest-rate").html(plan.attr("data-interest-rate"));
        $el.find(opts.installmentInformationSelector).find(".effective-rate").html(plan.attr("data-effective-rate"));
        $el.find(opts.installmentInformationSelector).find(".total-amount").html(plan.attr("data-total-amount"));
        $el.find(opts.installmentInformationSelector).find(".installments-amount").html(plan.attr("data-installments-amount"));
        $el.find(opts.installmentInformationSelector).find(".read-more").attr('href', plan.attr("data-read-more"))
    }

    _validateForm() {
        let me = this;
        let $el = $(me.el);
        let opts = me.options;
        let valid = true;

        $.each($el.find(opts.requiredFieldSelector), function (index, item) {
            if ($(item).val() === '') {
                $(item).addClass(opts.isInvalidClassName);
                valid = false;
            } else {
                $(item).removeClass(opts.isInvalidClassName);
            }
        });
        return valid;
    }

    _loadInstallments() {
        let me = this;
        let $el = $(me.el);
        let opts = me.options;

        if ($el.find(opts.installmentsContainerSelector).length > 0) {
            let installmentsContainer = $el.find(opts.installmentsContainerSelector);
            me._client.post(installmentsContainer.attr('data-get-url'), JSON.stringify({}), function (response) {
                if (response === '') {
                    $el.find(opts.paymentMethodInputSelector).attr('disabled', 'disabled');
                } else {
                    installmentsContainer.html(response);

                    me._registerInstallmentEvents();
                }
            });
        }
    }

    _showHideFields() {
        let me = this;
        let $el = $(me.el);
        let opts = me.options;

        if (me._isSelected()) {
            $el.find(opts.additionalFieldsSelector).show();
        } else {
            $el.find(opts.additionalFieldsSelector).hide();
        }
    }

    _isSelected() {
        let me = this;
        let $el = $(me.el);
        let opts = me.options;

        return $el.find(opts.paymentMethodInputSelector).is(':checked');
    }
}