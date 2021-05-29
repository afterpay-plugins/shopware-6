// Import all necessary Storefront plugins and scss files
import AfterPay from './js/afterpay';

// Register them via the existing PluginManager
const PluginManager = window.PluginManager;
PluginManager.register('AfterPay', AfterPay, '[data-afterpay-payment]');

// Necessary for the webpack hot module reloading server
if (module.hot) {
    module.hot.accept();
}
