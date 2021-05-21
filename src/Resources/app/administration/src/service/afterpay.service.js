import ApiService from 'src/core/service/api.service';

/**
 * Gateway for the API end point "afterpay"
 * @class
 * @extends ApiService
 */
export default class AfterPayService extends ApiService {
    constructor(httpClient, loginService, apiEndpoint = 'afterpay') {
        super(httpClient, loginService, apiEndpoint);
        this.name = 'AfterPayService';
    }

    capture(id, additionalParams = {}, additionalHeaders = {}) {
        const headers = this.getBasicHeaders(additionalHeaders);
        let params = {};

        params = Object.assign(params, additionalParams);

        return this.httpClient
            .post(`/_action/${this.getApiBasePath()}/capture`, {
                    id
                },
                {
                    params,
                    headers
                });
    }
};