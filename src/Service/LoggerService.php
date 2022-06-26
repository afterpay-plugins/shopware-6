<?php declare(strict_types=1);

namespace Colo\AfterPay\Service;

use Colo\AfterPay\Traits\HelperTrait;
use Monolog\Handler\AbstractHandler;
use Psr\Log\LoggerInterface;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SystemConfig\SystemConfigService;

class LoggerService
{

    use HelperTrait;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @var array
     */
    private $config;

    /**
     * LoggerService constructor.
     * @param SystemConfigService $systemConfigService
     * @param LoggerInterface $logger
     */
    public function __construct(SystemConfigService $systemConfigService, LoggerInterface $logger)
    {
        $this->systemConfigService = $systemConfigService;
        $this->logger = $logger;
    }

    /**
     * @param string $level
     * @param string $message
     * @param array $context
     * @return bool
     */
    public function log(string $level, string $message, array $context = [])
    {
        $success = true;
        $salesChannelContext = null;
        if (!empty($context['salesChannelContext']) && $context['salesChannelContext'] instanceof SalesChannelContext) {
            /** @var SalesChannelContext $salesChannelContext */
            $salesChannelContext = $context['salesChannelContext'];
            if (empty($this->config[$salesChannelContext->getSalesChannelId()])) {
                $this->config[$salesChannelContext->getSalesChannelId()] = $this->getPluginConfig($salesChannelContext->getSalesChannelId());
            }
            $config = $this->config[$salesChannelContext->getSalesChannelId()];
        } else {
            if (empty($this->config['default'])) {
                $this->config['default'] = $this->getPluginConfig();
            }
            $config = $this->config['default'];
        }
        if ($config['logType'] === 'none') {
            return $success;
        }

        // change the log level based on the plugin config
        $handlerLevels = [];
        /** @var AbstractHandler[] $handlers */
        $handlers = $this->logger->getHandlers();
        $logLevel = 'debug';
        if ($config['logType'] === 'fail') {
            $logLevel = 'error';
        }
        foreach ($handlers as $index => $handler) {
            $handlerLevels[$index] = $handler->getLevel();
            $handler->setLevel($logLevel);
        }

        try {
            $this->logger->log($level, $message);
        } catch (\Exception $ex) {
            $this->logger->error($ex->getMessage());
            $success = false;
        }

        // change back the log level to the default value
        foreach ($handlers as $index => $handler) {
            $handler->setLevel($handlerLevels[$index]);
        }

        return $success;

    }
}