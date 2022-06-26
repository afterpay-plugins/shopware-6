<?php declare(strict_types=1);

namespace Colo\AfterPay\Checkout\Order\Validation;

use Colo\AfterPay\Service\Payments\DirectDebitPayment;
use Colo\AfterPay\Service\Payments\InstallmentPayment;
use Colo\AfterPay\Service\Payments\InvoicePayment;
use Colo\AfterPay\Traits\HelperTrait;
use Shopware\Core\Framework\Validation\DataValidationDefinition;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\Validator\Constraints\NotBlank;
use Shopware\Core\Framework\Validation\DataValidationFactoryInterface;

class AfterpayPaymentValidationFactory implements DataValidationFactoryInterface
{

    use HelperTrait;

    /**
     * @var DataValidationFactoryInterface
     */
    private $coreFactory;

    /**
     * OrderValidationFactoryDecorator constructor.
     * @param DataValidationFactoryInterface $coreFactory
     * @param SystemConfigService $systemConfigService
     */
    public function __construct(
        DataValidationFactoryInterface $coreFactory,
        SystemConfigService $systemConfigService
    )
    {
        $this->coreFactory = $coreFactory;
        $this->systemConfigService = $systemConfigService;
    }

    public function create(SalesChannelContext $context): DataValidationDefinition
    {
        return $this->createAfterpayPaymentValidation($context, 'afterpay.payment.create');
    }

    public function update(SalesChannelContext $context): DataValidationDefinition
    {
        return $this->createAfterpayPaymentValidation($context, 'afterpay.payment.update');
    }

    private function createAfterpayPaymentValidation(SalesChannelContext $context, string $validationName): DataValidationDefinition
    {
        $definition = new DataValidationDefinition($validationName);
//        $paymentMethod = $context->getPaymentMethod();
//        if (!empty($paymentMethod) && ($paymentMethod->getHandlerIdentifier() === InvoicePayment::class ||
//                $paymentMethod->getHandlerIdentifier() === DirectDebitPayment::class ||
//                $paymentMethod->getHandlerIdentifier() === InstallmentPayment::class)) {
//            $pluginConfig = $this->getPluginConfig($context->getSalesChannelId());
//            if ($pluginConfig['profileTrackingSetup'] === 'mandatory'
//                && !empty($pluginConfig['trackingId'])
//                && trim($pluginConfig['trackingId'])) {
//                $definition->add('afterpay_tos', new NotBlank());
//            }
//        }
        return $definition;
    }

}

