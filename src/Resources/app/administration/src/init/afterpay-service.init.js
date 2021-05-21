import AfterPayService from '../service/afterpay.service.js';

Shopware.Application.addServiceProvider('AfterPayService', container => {
    return new AfterPayService(
        Shopware.Application.getContainer('init').httpClient,
        Shopware.Service('loginService')
    );
});