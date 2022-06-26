<?php declare(strict_types=1);

namespace Colo\AfterPay\Subscribers;

use Shopware\Core\Content\Media\MediaEntity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepositoryInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Storefront\Page\Page;
use Colo\AfterPay\Checkout\AfterPayData;
use Colo\AfterPay\Service\AfterpayService;
use Colo\AfterPay\Service\Payments\DirectDebitPayment;
use Colo\AfterPay\Service\Payments\InstallmentPayment;
use Colo\AfterPay\Service\Payments\InvoicePayment;
use Colo\AfterPay\Traits\HelperTrait;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Shopware\Storefront\Page\Checkout\Confirm\CheckoutConfirmPageLoadedEvent;
use Shopware\Storefront\Page\Checkout\Finish\CheckoutFinishPageLoadedEvent;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class CheckoutSubscriber implements EventSubscriberInterface
{
    use HelperTrait;

    public const AFTERPAY_DATA_EXTENSION_ID = 'afterPayData';

    /**
     * @var ContainerInterface
     */
    private $container;

    /**
     * @var AfterpayService
     */
    private $afterpayService;

    /**
     * @var EntityRepositoryInterface
     */
    private $mediaRepository;

    /**
     * CheckoutSubscriber constructor.
     * @param ContainerInterface $container
     * @param AfterpayService $afterpayService
     * @param EntityRepositoryInterface $mediaRepository
     * @param SystemConfigService $systemConfigService
     */
    public function __construct(
        ContainerInterface        $container,
        AfterpayService           $afterpayService,
        EntityRepositoryInterface $mediaRepository,
        SystemConfigService       $systemConfigService)
    {
        $this->container = $container;
        $this->afterpayService = $afterpayService;
        $this->mediaRepository = $mediaRepository;
        $this->systemConfigService = $systemConfigService;
    }

    /**
     * @return array
     */
    public static function getSubscribedEvents(): array
    {
        return [
            CheckoutConfirmPageLoadedEvent::class => ['onConfirmPageLoaded', 1],
            CheckoutFinishPageLoadedEvent::class => ['onFinishPageLoaded', 1]
        ];
    }

    /**
     * @param CheckoutConfirmPageLoadedEvent $event
     * @throws \Exception
     */
    public function onConfirmPageLoaded(CheckoutConfirmPageLoadedEvent $event): void
    {
        $this->addAfterPayDataExtension($event->getPage(), $event->getSalesChannelContext());
    }

    /**
     * @param CheckoutFinishPageLoadedEvent $event
     * @throws \Exception
     */
    public function onFinishPageLoaded(CheckoutFinishPageLoadedEvent $event): void
    {
        $page = $event->getPage();
        $salesChannelContext = $event->getSalesChannelContext();
        $this->addAfterPayDataExtension($page, $salesChannelContext);
        $paymentMethod = $page->getOrder()->getTransactions()->last()->getPaymentMethod();
        if (!empty($paymentMethod) && empty($paymentMethod->getMedia()) && !empty($paymentMethod->getMediaId())) {
            $mediaId = $paymentMethod->getMediaId();
            /** @var MediaEntity $media */
            $media = $this->mediaRepository->search(new Criteria([$mediaId]), $salesChannelContext->getContext())->first();
            if (!empty($media)) {
                $paymentMethod->setMedia($media);
            }
        }
    }

    /**
     * @param Page $page
     * @param SalesChannelContext $salesChannelContext
     * @throws \Exception
     */
    private function addAfterPayDataExtension(Page $page, SalesChannelContext $salesChannelContext)
    {
        $variables = [
            'afterPayActive' => false
        ];
        $handlerIdentifier = $salesChannelContext->getPaymentMethod()->getHandlerIdentifier();
        if ($handlerIdentifier === InvoicePayment::class ||
            $handlerIdentifier === DirectDebitPayment::class ||
            $handlerIdentifier === InstallmentPayment::class) {
            $pluginConfig = $this->getPluginConfig($salesChannelContext->getSalesChannelId());

            $showTosCheckbox = false;
            $tosCheckboxRequired = false;
            if (in_array($pluginConfig['profileTrackingSetup'], ['optional', 'mandatory'])
                && !empty($pluginConfig['trackingId'])
                && trim($pluginConfig['trackingId'])) {
                $showTosCheckbox = true;
                if ($pluginConfig['profileTrackingSetup'] === 'mandatory') {
                    $tosCheckboxRequired = true;
                }
            }

            $customer = $salesChannelContext->getCustomer();
            $variables['afterPayActive'] = true;
            $variables['merchantId'] = $this->afterpayService->getMerchantId($salesChannelContext);
            $variables['showTosCheckbox'] = $showTosCheckbox;
            $variables['tosCheckboxRequired'] = $tosCheckboxRequired;
            $variables['shopLanguage'] = $this->afterpayService->getShopLanguage($salesChannelContext);
            $variables['shippingCountryCode'] = $this->afterpayService->getShippingCountryCode($customer);
            $variables['languageCode'] = $variables['shopLanguage'] . '_' . $variables['shippingCountryCode'];
            $variables['merchantPaymentMethod'] = $handlerIdentifier::getName();
            $variables['paymentMethodName'] = $salesChannelContext->getPaymentMethod()->getTranslation('name');
            $variables['paymentMethodId'] = $salesChannelContext->getPaymentMethod()->getId();

            $session = $this->container->get('session');
            $installmentPlan = $session->get('ColoAfterpayInstallmentPlan');
            if (!empty($installmentPlan)) {
                $cart = $page->getCart();
                $installment = $this->afterpayService->getInstallment($installmentPlan, $salesChannelContext, round($cart->getPrice()->getTotalPrice(), 2));
                if (!empty($installment)) {
                    $variables['selectedInstallment'] = $installment;
                }
            }
        }
        $afterPayData = (new AfterPayData())->assign($variables);
        $page->addExtension(
            self::AFTERPAY_DATA_EXTENSION_ID,
            $afterPayData
        );
    }
}
