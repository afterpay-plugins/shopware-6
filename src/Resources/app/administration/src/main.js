import './init/afterpay-service.init.js';
import './module/sw-order/page/sw-order-list';
import './component/form/select/entity/sw-entity-multi-select/index';

import deDE from './snippet/de-DE.json';
import enGB from './snippet/en-GB.json';

Shopware.Locale.extend('de-DE', deDE);
Shopware.Locale.extend('en-GB', enGB);