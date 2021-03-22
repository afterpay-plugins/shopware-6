<?php

namespace Colo\AfterPay\Traits;

use Shopware\Core\System\SystemConfig\SystemConfigService;

trait HelperTrait
{

    /** @var SystemConfigService */
    protected $systemConfigService;

    /**
     * @param string $name
     * @param mixed $default
     * @return mixed
     */
    public function getPluginConfig(string $name = '', $default = null)
    {
        $domain = $this->systemConfigService->getDomain('AfterPay');
        $keys = array_map(function ($key) {
            return str_replace('AfterPay.config.', '', $key);
        }, array_keys($domain));
        $config = array_combine($keys, array_values($domain));

        if (!empty($name)) {
            return isset($config[$name]) ? $config[$name] : $default;
        }

        return $config;
    }
}