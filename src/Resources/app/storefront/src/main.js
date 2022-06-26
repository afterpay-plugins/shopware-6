// Import all necessary Storefront plugins and scss files
import AfterPayPaymentSelection from './js/afterpay-payment-selection';
import AfterPayCheckoutConfirm from './js/afterpay-checkout-confirm';

// Register them via the existing PluginManager
const PluginManager = window.PluginManager;
PluginManager.register('AfterPayPaymentSelection', AfterPayPaymentSelection, '[data-afterpay-payment]');
PluginManager.register('AfterPayCheckoutConfirm', AfterPayCheckoutConfirm, '.is-ctl-checkout.is-act-confirmpage');

// Necessary for the webpack hot module reloading server
if (module.hot) {
    module.hot.accept();
}
