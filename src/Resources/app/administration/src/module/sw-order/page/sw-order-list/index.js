import template from './sw-order-list.html.twig';

const { Component, Mixin } = Shopware;

Component.override('sw-order-list', {
    template,

    inject: [
        'AfterPayService'
    ],

    mixins: [
        Mixin.getByName('notification')
    ],

    data () {
        return {
            showCaptureModal: false
        };
    },

    methods: {
        capturable (item) {
            return (item.customFields && typeof item.customFields['afterpay_orders_transaction_id'] !== 'undefined' && item.customFields['afterpay_orders_transaction_id'] && (typeof item.customFields['afterpay_orders_captured'] === 'undefined' || item.customFields['afterpay_orders_captured'] == 0));
        },

        onCapture(id) {
            this.showCaptureModal = id;
        },

        onCloseCaptureModal() {
            this.showCaptureModal = false;
        },

        onConfirmCapture(order) {
            this.showCaptureModal = false;

            return this.AfterPayService.capture(order.id).then((response) => {
                if (response.data.success) {
                    this.createNotificationSuccess({
                        title: this.$tc('global.default.success'),
                        message: this.$tc('afterpay.sw-order.list.messageCaptureSuccess', 0, {
                            orderNumber: order.orderNumber
                        })
                    });

                    this.getList();
                } else {
                    this.createNotificationError({
                        title: this.$tc('global.default.error'),
                        message: this.$tc('afterpay.sw-order.list.messageCaptureError', 0, {
                            orderNumber: order.orderNumber
                        })
                    });
                }
            }).catch((exception) => {
                this.createNotificationError({
                    title: this.$tc('global.default.error'),
                    message: this.$tc('afterpay.sw-order.list.messageCaptureError', 0, {
                        orderNumber: order.orderNumber
                    })
                });
                throw exception;
            });
        }
    }
});
