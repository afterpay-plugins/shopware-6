<?php declare(strict_types=1);

namespace Colo\AfterPay\Checkout;

use Colo\AfterPay\Service\InvoicePayment;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Shopware\Storefront\Page\Checkout\Confirm\CheckoutConfirmPageLoadedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Colo\AfterPay\Traits\HelperTrait;

class CheckoutSubscriber implements EventSubscriberInterface
{
    use HelperTrait;

    public const AFTERPAY_DATA_EXTENSION_ID = 'afterPayData';
    public const DEFAULT_MERCHANT_ID = 'default';

    /**
     * @var ContainerInterface
     */
    private $container;

    /**
     * CheckoutSubscriber constructor.
     * @param ContainerInterface $container
     * @param SystemConfigService $systemConfigService
     */
    public function __construct(ContainerInterface $container, SystemConfigService $systemConfigService)
    {
        $this->container = $container;
        $this->systemConfigService = $systemConfigService;
    }

    /**
     * @return array
     */
    public static function getSubscribedEvents(): array
    {
        return [
            CheckoutConfirmPageLoadedEvent::class => ['onConfirmPageLoaded', 1]
        ];
    }

    /**
     * @param CheckoutConfirmPageLoadedEvent $event
     */
    public function onConfirmPageLoaded(CheckoutConfirmPageLoadedEvent $event): void
    {
        $salesChannelContext = $event->getSalesChannelContext();
        $variables = [
            'afterPayActive' => false
        ];
        if ($salesChannelContext->getPaymentMethod()->getHandlerIdentifier() === InvoicePayment::class) {
            $variables['afterPayActive'] = true;
            $variables['merchantId'] = $this->getMerchantId($salesChannelContext);
            $variables['tosCheckbox'] = $this->getPluginConfig('showTosCheckbox');
            $variables['languageCode'] = $this->getLanguageCode($salesChannelContext);
            $variables['merchantPaymentMethod'] = $this->getMerchantPaymentMethod();
            $variables['paymentMethodName'] = $salesChannelContext->getPaymentMethod()->getTranslation('name');
            $variables['paymentMethodId'] = $salesChannelContext->getPaymentMethod()->getId();
            $variables['paymentLogo'] = base64_encode(file_get_contents(__DIR__ . '/../Resources/public/storefront/assets/img/afterpay_logo.svg'));
        }
        $afterPayData = (new AfterPayData())->assign($variables);
        $event->getPage()->addExtension(
            self::AFTERPAY_DATA_EXTENSION_ID,
            $afterPayData
        );
    }

    /**
     * @param SalesChannelContext $salesChannelContext
     * @return string
     */
    private function getMerchantId($salesChannelContext)
    {
        $merchantId = self::DEFAULT_MERCHANT_ID;
        $customer = $salesChannelContext->getCustomer();
        if (empty($customer)) {
            return $merchantId;
        }
        $countryIso = $customer->getActiveBillingAddress()->getCountry()->getIso();
        $merchantId = $this->getPluginConfig('merchantId' . strtoupper($countryIso));
        $merchantId = !empty($merchantId) ? $merchantId : self::DEFAULT_MERCHANT_ID;
        return $merchantId;
    }

    /**
     * @return string
     */
    private function getMerchantPaymentMethod()
    {
        return 'invoice';
    }

    /**
     * @param $salesChannelContext
     * @return string
     */
    private function getLanguageCode($salesChannelContext)
    {
        return 'de_DE';
    }
}
