<?php declare(strict_types=1);

namespace Colo\AfterPay\Checkout\Order\Validation;

use Colo\AfterPay\Service\InvoicePayment;
use Shopware\Core\Checkout\Order\Validation\OrderValidationFactory;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Validation\DataValidationDefinition;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\Validator\Constraints\NotBlank;
use Shopware\Core\Framework\Validation\DataValidationFactoryInterface;

class OrderValidationFactoryDecorator extends OrderValidationFactory
{

    /**
     * @var DataValidationFactoryInterface
     */
    private $coreFactory;

    /**
     * OrderValidationFactoryDecorator constructor.
     * @param DataValidationFactoryInterface $coreFactory
     */
    public function __construct(DataValidationFactoryInterface $coreFactory)
    {
        $this->coreFactory = $coreFactory;
    }

    /**
     * @return DataValidationFactoryInterface
     */
    public function getCoreFactory()
    {
        return $this->coreFactory;
    }

    public function buildCreateValidation(Context $context): DataValidationDefinition
    {
        return $this->getCoreFactory()->buildCreateValidation($context);
    }

    public function buildUpdateValidation(Context $context): DataValidationDefinition
    {
        return $this->getCoreFactory()->buildUpdateValidation($context);
    }

    public function create(SalesChannelContext $context): DataValidationDefinition
    {
        $definition = $this->getCoreFactory()->create($context);
        return $this->addAfterPayValidation($context, $definition);
    }

    public function update(SalesChannelContext $context): DataValidationDefinition
    {
        $definition = $this->getCoreFactory()->update($context);
        return $this->addAfterPayValidation($context, $definition);
    }

    private function addAfterPayValidation(SalesChannelContext $context, DataValidationDefinition $definition): DataValidationDefinition
    {
        $paymentMethod = $context->getPaymentMethod();
        if (!empty($paymentMethod) && $paymentMethod->getHandlerIdentifier() === InvoicePayment::class) {
            $definition->add('afterpay_tos', new NotBlank());
        }
        return $definition;
    }
}

