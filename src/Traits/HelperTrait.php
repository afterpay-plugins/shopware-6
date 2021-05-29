<?php

namespace Colo\AfterPay\Traits;

use Shopware\Core\System\SystemConfig\SystemConfigService;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

trait HelperTrait
{

    /** @var SystemConfigService */
    protected $systemConfigService;

    /**
     * @param ?string $salesChannelId
     * @param string $name
     * @param mixed $default
     * @return mixed
     */
    public function getPluginConfig(string $salesChannelId = null, string $name = '', $default = null)
    {
        if (!empty($salesChannelId)) {
            $domain = $this->systemConfigService->getDomain('AfterPay', $salesChannelId, true);
        } else {
            $domain = $this->systemConfigService->getDomain('AfterPay');
        }
        $keys = array_map(function ($key) {
            return str_replace('AfterPay.config.', '', $key);
        }, array_keys($domain));
        $config = array_combine($keys, array_values($domain));

        if (!empty($name)) {
            return $config[$name] ?? $default;
        }

        return $config;
    }
}