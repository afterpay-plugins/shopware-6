<?php declare(strict_types=1);

namespace Colo\AfterPay\Checkout\Order\SalesChannel;

use Shopware\Core\Checkout\Cart\SalesChannel\CartService;
use Shopware\Core\Checkout\Order\SalesChannel\OrderService;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepositoryInterface;
use Shopware\Core\Framework\Validation\BuildValidationEvent;
use Shopware\Core\Framework\Validation\DataBag\DataBag;
use Shopware\Core\Framework\Validation\DataValidationDefinition;
use Shopware\Core\Framework\Validation\DataValidationFactoryInterface;
use Shopware\Core\Framework\Validation\DataValidator;
use Shopware\Core\Framework\Validation\Exception\ConstraintViolationException;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\StateMachine\StateMachineRegistry;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\ParameterBag;

class OrderServiceDecorator extends OrderService
{
    /**
     * @var OrderService
     */
    private $coreService;

    /**
     * @var DataValidator
     */
    private $dataValidator;

    /**
     * @var DataValidationFactoryInterface
     */
    private $afterpayPaymentValidationFactory;

    /**
     * @var EventDispatcherInterface
     */
    private $eventDispatcher;

    public function __construct(
        OrderService $coreService,
        DataValidator $dataValidator,
        DataValidationFactoryInterface $afterpayPaymentValidationFactory,
        EventDispatcherInterface $eventDispatcher,
        CartService $cartService,
        EntityRepositoryInterface $paymentMethodRepository,
        StateMachineRegistry $stateMachineRegistry
    ) {
        $this->coreService = $coreService;
        $this->dataValidator = $dataValidator;
        $this->afterpayPaymentValidationFactory = $afterpayPaymentValidationFactory;
        $this->eventDispatcher = $eventDispatcher;

        parent::__construct($dataValidator, $afterpayPaymentValidationFactory, $eventDispatcher, $cartService, $paymentMethodRepository, $stateMachineRegistry);
    }

    /**
     * @throws ConstraintViolationException
     */
    public function createOrder(DataBag $data, SalesChannelContext $context): string
    {
        $this->validateAfterpayData($data, $context);

        return $this->coreService->createOrder($data, $context);
    }

    /**
     * @throws ConstraintViolationException
     */
    private function validateAfterpayData(ParameterBag $data, SalesChannelContext $context): void
    {
        $definition = $this->getAfterpayCreateValidationDefinition($context);
        $violations = $this->dataValidator->getViolations($data->all(), $definition);

        if ($violations->count() > 0) {
            throw new ConstraintViolationException($violations, $data->all());
        }
    }

    private function getAfterpayCreateValidationDefinition(SalesChannelContext $context): DataValidationDefinition
    {
        $validation = $this->afterpayPaymentValidationFactory->create($context);

        $validationEvent = new BuildValidationEvent($validation, $context->getContext());
        $this->eventDispatcher->dispatch($validationEvent);

        return $validation;
    }
}
