<?php declare(strict_types=1);

namespace Colo\AfterPay\Service;

use Colo\AfterPay\AfterPay;
use Colo\AfterPay\Service\Payments\DirectDebitPayment;
use Colo\AfterPay\Service\Payments\InstallmentPayment;
use Colo\AfterPay\Service\Payments\InvoicePayment;
use Colo\AfterPay\Traits\HelperTrait;
use Composer\InstalledVersions;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Checkout\Cart\SalesChannel\CartService;
use Shopware\Core\Checkout\Customer\Aggregate\CustomerAddress\CustomerAddressEntity;
use Shopware\Core\Checkout\Customer\CustomerEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderLineItem\OrderLineItemEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStateHandler;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Checkout\Payment\Cart\SyncPaymentTransactionStruct;
use Shopware\Core\Checkout\Payment\Exception\SyncPaymentProcessException;
use Shopware\Core\Content\Product\Aggregate\ProductMedia\ProductMediaEntity;
use Shopware\Core\Content\Product\ProductEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepositoryInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Exception\InconsistentCriteriaIdsException;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsAnyFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\MultiFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\NotFilter;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\System\Currency\CurrencyEntity;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\Salutation\SalutationEntity;
use Shopware\Core\System\StateMachine\Aggregation\StateMachineTransition\StateMachineTransitionActions;
use Shopware\Core\System\StateMachine\StateMachineRegistry;
use Shopware\Core\System\StateMachine\Transition;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Shopware\Storefront\Framework\Seo\SeoUrlRoute\ProductPageSeoUrlRoute;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

class AfterpayService
{
    use HelperTrait;

    public const DEFAULT_MERCHANT_ID = 'default';

    public const INVOICE_DOCUMENT_TYPE_ID = 'invoice';

    public const DEFAULT_COUNTRY_CODE = 'de';
    public const DEFAULT_LANGUAGE_CODE = 'de';

    public const ALLOWED_COUNTRY_CODES = ['de', 'en', 'nl', 'be'];
    public const ALLOWED_LANGUAGE_CODES = ['de', 'en', 'nl', 'be'];

    /**
     * @var OrderTransactionStateHandler
     */
    private $transactionStateHandler;

    /**
     * @var ContainerInterface
     */
    private $container;

    /**
     * @var EntityRepositoryInterface
     */
    private $salutationRepo;

    /**
     * @var EntityRepositoryInterface
     */
    private $productRepo;

    /**
     * @var EntityRepositoryInterface
     */
    private $orderRepo;

    /**
     * @var EntityRepositoryInterface
     */
    private $currencyRepo;

    /**
     * @var EntityRepositoryInterface
     */
    private $languageRepo;

    /**
     * @var CartService
     */
    private $cartService;

    /**
     * @var TranslatorInterface
     */
    private $translator;

    /**
     * @var StateMachineRegistry
     */
    private $stateMachineRegistry;

    /**
     * @var LoggerService
     */
    private $logger;

    /**
     * @var array
     */
    private $constants = [];

    /**
     * @var string
     */
    private $apiUrl;

    /**
     * @var string
     */
    private $apiKey;

    /**
     * @var array
     */
    private $apiHeaders;

    /**
     * AfterpayService constructor.
     * @param OrderTransactionStateHandler $transactionStateHandler
     * @param ContainerInterface $container
     * @param EntityRepositoryInterface $salutationRepo
     * @param EntityRepositoryInterface $productRepo
     * @param EntityRepositoryInterface $orderRepo
     * @param EntityRepositoryInterface $currencyRepo
     * @param EntityRepositoryInterface $languageRepo
     * @param CartService $cartService
     * @param TranslatorInterface $translator
     * @param SystemConfigService $systemConfigService
     * @param StateMachineRegistry $stateMachineRegistry
     * @param LoggerService $logger
     */
    public function __construct(
        OrderTransactionStateHandler $transactionStateHandler,
        ContainerInterface           $container,
        EntityRepositoryInterface    $salutationRepo,
        EntityRepositoryInterface    $productRepo,
        EntityRepositoryInterface    $orderRepo,
        EntityRepositoryInterface    $currencyRepo,
        EntityRepositoryInterface    $languageRepo,
        CartService                  $cartService,
        TranslatorInterface          $translator,
        SystemConfigService          $systemConfigService,
        StateMachineRegistry         $stateMachineRegistry,
        LoggerService                $logger
    )
    {
        $this->transactionStateHandler = $transactionStateHandler;
        $this->container = $container;
        $this->salutationRepo = $salutationRepo;
        $this->productRepo = $productRepo;
        $this->orderRepo = $orderRepo;
        $this->currencyRepo = $currencyRepo;
        $this->languageRepo = $languageRepo;
        $this->cartService = $cartService;
        $this->translator = $translator;
        $this->systemConfigService = $systemConfigService;
        $this->stateMachineRegistry = $stateMachineRegistry;
        $this->logger = $logger;
        if (file_exists(__DIR__ . '/../Constants.php')) {
            $this->constants = include __DIR__ . '/../Constants.php';
        }
    }

    /**
     * @param SyncPaymentTransactionStruct $transaction
     * @param RequestDataBag $dataBag
     * @param SalesChannelContext $salesChannelContext
     * @param string $paymentHandlerIdentifier
     */
    public function handlePayment(SyncPaymentTransactionStruct $transaction, RequestDataBag $dataBag, SalesChannelContext $salesChannelContext, string $paymentHandlerIdentifier)
    {
        try {
            $order = $transaction->getOrder();
            if ($paymentHandlerIdentifier === DirectDebitPayment::class) {
                $this->checkPaymentAvailability($transaction, $dataBag, $salesChannelContext, 'directDebit');

                $this->validateBankAccount($salesChannelContext);
            } else if ($paymentHandlerIdentifier === InstallmentPayment::class) {
                $this->validateBankAccount($salesChannelContext);
            }
            $response = $this->authorizePayment($order, $dataBag, $salesChannelContext, $paymentHandlerIdentifier);
            if (!$response['success']) {
                throw new \Exception(!empty($response['message']) ? $response['message'] : 'Unidentified error');
            }
            $this->orderRepo->upsert([[
                'id' => $order->getId(),
                'customFields' => [
                    AfterPay::CUSTOM_FIELD_NAME_AFTERPAY_TRANSACTION_ID => $response['transactionId'],
                    AfterPay::CUSTOM_FIELD_NAME_AFTERPAY_TRANSACTION_MODE => $this->getPluginConfig($salesChannelContext->getSalesChannelId(), 'mode')
                ]
            ]], $salesChannelContext->getContext());

            $this->stateMachineRegistry->transition(
                new Transition(
                    'order_transaction',
                    $order->getTransactions()->last()->getId(),
                    StateMachineTransitionActions::ACTION_AUTHORIZE,
                    'stateId'
                ),
                $salesChannelContext->getContext()
            );
        } catch (\Exception $ex) {
            if ($this->container->has('session')) {
                $this->container->get('session')->getFlashBag()->add('danger', $ex->getMessage());
            }

            $this->logger->log('error', $ex->getMessage());
            throw new SyncPaymentProcessException(
                $transaction->getOrderTransaction()->getId(),
                $ex->getMessage()
            );
        }
    }

    /**
     * @param Context $context
     * @return bool
     * @throws InconsistentCriteriaIdsException
     * @throws \Symfony\Component\DependencyInjection\Exception\ServiceCircularReferenceException
     * @throws \Symfony\Component\DependencyInjection\Exception\ServiceNotFoundException
     */
    public function capturePayments(Context $context)
    {
        $orders = $this->getCapturableOrders($context);
        if (empty($orders)) {
            return true;
        }
        foreach ($orders as $order) {
            $this->capturePayment($order, $context);
        }
        return true;
    }

    /**
     * @param OrderEntity $order
     * @param Context $context
     * @return bool
     * @throws InconsistentCriteriaIdsException
     * @throws \Symfony\Component\DependencyInjection\Exception\ServiceCircularReferenceException
     * @throws \Symfony\Component\DependencyInjection\Exception\ServiceNotFoundException
     */
    public function capturePayment(OrderEntity $order, Context $context)
    {
        try {
            $billingAddress = $order->getBillingAddress();
            if (empty($billingAddress) || empty($billingAddress->getCountry()) || empty($billingAddress->getCountry()->getIso())) {
                $this->logger->log('error', 'Order details are not complete: ' . $order->getOrderNumber());

                return false;
            }
            $transactionMode = null;
            $customFields = $order->getCustomFields();
            if (!empty($customFields) && !empty($customFields[AfterPay::CUSTOM_FIELD_NAME_AFTERPAY_TRANSACTION_MODE])) {
                $transactionMode = $customFields[AfterPay::CUSTOM_FIELD_NAME_AFTERPAY_TRANSACTION_MODE];
            }
            $salesChannel = $order->getSalesChannel();
            $countryIso = $billingAddress->getCountry()->getIso();
            $this->initApi($salesChannel->getId(), $countryIso, $transactionMode, true);
            if (empty($this->apiKey) || empty($this->apiUrl)) {
                $this->logger->log('error', 'Wrong plugin configuration');

                return false;
            }
            $transactionId = $customFields[AfterPay::CUSTOM_FIELD_NAME_AFTERPAY_TRANSACTION_ID];
            $data = $this->prepareCaptureData($order, $context);
            $url = $this->apiUrl . "orders/" . $transactionId . "/captures";
            $this->logger->log('info', $url);
            $this->logger->log('info', json_encode($data));

            $curlResponse = $this->curl($url, json_encode($data));
            $response = $this->handleResponse($curlResponse, ['success' => true]);
            if ($response['success']) {
                if (!empty($response['captureNumber'])) {
                    $customFields[AfterPay::CUSTOM_FIELD_NAME_AFTERPAY_CAPTURED] = 1;
                    $customFields[AfterPay::CUSTOM_FIELD_NAME_AFTERPAY_CAPTURE_NUMBER] = $response['captureNumber'];
                    $this->orderRepo->upsert([[
                        'id' => $order->getId(),
                        'customFields' => $customFields
                    ]], $context);

                    $this->stateMachineRegistry->transition(
                        new Transition(
                            'order_transaction',
                            $order->getTransactions()->last()->getId(),
                            StateMachineTransitionActions::ACTION_PAID,
                            'stateId'
                        ),
                        $context
                    );
                }
            }
        } catch (\Exception $ex) {
            $this->logger->log('error', $ex->getMessage());

            return false;
        }

        return true;
    }

    /**
     * Gets all available payment methods and checks if payment method is available
     *
     * @param SyncPaymentTransactionStruct $transaction
     * @param RequestDataBag $dataBag
     * @param SalesChannelContext $salesChannelContext
     * @param string $paymentName
     * @return bool
     *
     * @throws \Exception
     */
    public function checkPaymentAvailability(SyncPaymentTransactionStruct $transaction, RequestDataBag $dataBag, SalesChannelContext $salesChannelContext, string $paymentName)
    {
        $isAvailable = false;
        $response = $this->getAvailablePayments($transaction, $dataBag, $salesChannelContext);
        if ($response['success'] && !empty($response['paymentMethods']) && !empty($response['token']) && !empty($response['transactionId'])) {
            foreach ($response['paymentMethods'] as $paymentMethod) {
                if (isset($paymentMethod[$paymentName]) && $paymentMethod[$paymentName]['available']) {
                    $isAvailable = true;
                    break;
                }
            }
        } else {
            throw new \Exception($response['message']);
        }

        return $isAvailable;
    }

    /**
     * Calls afterpay /api/v3/validate/bank-account API method and
     * validates customer's bank account
     *
     * @param SalesChannelContext $salesChannelContext
     * @return array
     *
     * @throws \Exception
     */
    public function validateBankAccount(SalesChannelContext $salesChannelContext)
    {
        $response = ["success" => false];
        $customer = $salesChannelContext->getCustomer();
        $countryIso = $customer->getActiveBillingAddress()->getCountry()->getIso();
        $this->initApi($salesChannelContext->getSalesChannelId(), $countryIso);

        $customFields = $customer->getCustomFields();
        $iban = $customFields['afterpay_iban'] ?? null;
        if (empty($iban)) {
            $message = $this->translator->trans('afterpay.messages.BankAccountNotValid');
            throw new \Exception($message);
        }
        $data = [
            'bankAccount' => $iban
        ];
        $url = $this->apiUrl . "validate/bank-account";
        $curlResponse = $this->curl($url, json_encode($data));
        $this->logger->log('info', $url);
        $this->logger->log('info', json_encode($data));
        $response = $this->handleResponse($curlResponse, $response);
        if (!$response['success']) {
            throw new \Exception($response['message']);
        }

        return $response;
    }

    /**
     * Calls afterpay /api/v3/lookup/installment-plans API method
     *
     * @param SalesChannelContext $salesChannelContext
     * @param float $amount
     * @return array|mixed
     *
     * @throws \Exception
     */
    public function getAvailableInstallments(SalesChannelContext $salesChannelContext, float $amount)
    {
        $response = ['success' => false];
        $customer = $salesChannelContext->getCustomer();
        $countryIso = $customer->getActiveBillingAddress()->getCountry()->getIso();
        $this->initApi($salesChannelContext->getSalesChannelId(), $countryIso);

        $data = [
            'amount' => $amount,
            'countryCode' => $countryIso,
            'currency' => $salesChannelContext->getCurrency()->getShortName()
        ];
        $url = $this->apiUrl . "lookup/installment-plans";
        $curlResponse = $this->curl($url, json_encode($data));
        $this->logger->log('info', $url);
        $this->logger->log('info', json_encode($data));
        $response = $this->handleResponse($curlResponse, $response);
        if ($response['success']) {
            return $response['availableInstallmentPlans'];
        }

        return [];
    }

    /**
     * @param SalesChannelContext $salesChannelContext
     * @return string
     */
    public function getMerchantId(SalesChannelContext $salesChannelContext)
    {
        $merchantId = self::DEFAULT_MERCHANT_ID;
        $customer = $salesChannelContext->getCustomer();
        if (empty($customer)) {
            return $merchantId;
        }
        $countryIso = $customer->getActiveBillingAddress()->getCountry()->getIso();
        $merchantId = $this->getPluginConfig($salesChannelContext->getSalesChannelId(), 'merchantId' . strtoupper($countryIso));
        return !empty($merchantId) ? $merchantId : self::DEFAULT_MERCHANT_ID;
    }

    /**
     * Returns installment by profile number
     *
     * @param string $plan
     * @param SalesChannelContext $salesChannelContext
     * @param float $amount
     * @return array
     * @throws \Exception
     */
    public function getInstallment(string $plan, SalesChannelContext $salesChannelContext, float $amount)
    {
        $installments = $this->getAvailableInstallments($salesChannelContext, $amount);
        if (empty($installments)) {
            return [];
        }
        foreach ($installments as $installment) {
            if ($installment['installmentProfileNumber'] == $plan) {
                return $installment;
            }
        }

        return [];
    }

    /**
     * @param array $ids
     *
     * @return Criteria
     */
    public function createOrderCriteria(array $ids = [])
    {
        if (!empty($ids)) {
            $criteria = new Criteria($ids);
        } else {
            $criteria = new Criteria();
        }
        $criteria->addAssociation('salesChannel');
        $criteria->addAssociation('transactions');
        $criteria->addAssociation('transactions.paymentMethod');
        $criteria->addAssociation('deliveries');
        $criteria->addAssociation('billingAddress');
        $criteria->addAssociation('billingAddress.country');
        $criteria->addAssociation('lineItems');
        $criteria->addAssociation('documents');
        $criteria->addAssociation('documents.documentType');
        return $criteria;
    }

    /**
     * @param SalesChannelContext $salesChannelContext
     * @return string
     */
    public function getShopLanguage(SalesChannelContext $salesChannelContext)
    {
        try {
            $context = $salesChannelContext->getContext();
            $languageId = $context->getLanguageId();

            $criteria = new Criteria([$languageId]);
            $criteria->addAssociation('locale');
            $localeCode = $this->languageRepo->search($criteria, $context)->first()->getLocale()->getCode();

            $language = strtolower(explode('-', $localeCode)[0]);
            if (!in_array($language, self::ALLOWED_LANGUAGE_CODES, true)) {
                $language = self::DEFAULT_LANGUAGE_CODE;
            }
        } catch (\Exception $ex) {
            $language = self::DEFAULT_LANGUAGE_CODE;
        }
        return $language;
    }

    /**
     * @param CustomerEntity|null $customer
     * @return string
     */
    public function getShippingCountryCode(CustomerEntity $customer = null)
    {
        try {
            if (empty($customer)) {
                return self::DEFAULT_COUNTRY_CODE;
            }
            $countryCode = strtolower($customer->getActiveShippingAddress()->getCountry()->getIso());
            if (!in_array($countryCode, self::ALLOWED_COUNTRY_CODES)) {
                $countryCode = self::DEFAULT_COUNTRY_CODE;
            }
        } catch (\Exception $ex) {
            $countryCode = self::DEFAULT_COUNTRY_CODE;
        }
        return $countryCode;
    }

    /**
     * Calls afterpay /api/v3/checkout/authorize API method
     * and returns transaction id and temporary id for the order
     *
     * @param OrderEntity $order
     * @param RequestDataBag $dataBag
     * @param SalesChannelContext $salesChannelContext
     * @param string $paymentHandlerIdentifier
     * @return array
     * @throws InconsistentCriteriaIdsException
     * @throws \Exception
     */
    private function authorizePayment(OrderEntity $order, RequestDataBag $dataBag, SalesChannelContext $salesChannelContext, string $paymentHandlerIdentifier)
    {
        $customer = $salesChannelContext->getCustomer();

        $response = ["success" => false];
        $data = [
            'payment' => [
                'type' => 'Invoice'
            ]
        ];
        if ($paymentHandlerIdentifier === DirectDebitPayment::class || $paymentHandlerIdentifier === InstallmentPayment::class) {
            $customFields = $customer->getCustomFields();
            $iban = $customFields['afterpay_iban'] ?? null;
            $data['payment']['directDebit'] = [
                'bankAccount' => $iban
            ];
            if ($paymentHandlerIdentifier === InstallmentPayment::class) {
                /** @var Session $session */
                $session = $this->container->get('session');
                $installmentPlan = $session->get('ColoAfterpayInstallmentPlan');
                if (empty($installmentPlan)) {
                    $message = $this->translator->trans('afterpay.messages.InstallmentNotSelected');
                    throw new \Exception($message);
                }
                $installment = $this->getInstallment($installmentPlan, $salesChannelContext, round($order->getAmountTotal(), 2));
                if (empty($installment)) {
                    $message = $this->translator->trans('afterpay.messages.InstallmentNotValid');
                    throw new \Exception($message);
                }

                $data['payment']['type'] = 'Installment';
                $data['payment']['installment'] = [
                    'profileNo' => $installment['installmentProfileNumber'],
                    'customerInterestRate' => $installment['interestRate'],
                    'numberOfInstallments' => $installment['numberOfInstallments']
                ];
            }
        }
        $countryIso = $customer->getActiveBillingAddress()->getCountry()->getIso();
        $this->initApi($salesChannelContext->getSalesChannelId(), $countryIso);

        $data = $this->prepareData($data, $customer, $order, $dataBag, $salesChannelContext);
        $url = $this->apiUrl . "checkout/authorize";

        $curlResponse = $this->curl($url, json_encode($data));
        $this->logger->log('info', $url);
        $this->logger->log('info', json_encode($data));
        $response = $this->handleResponse($curlResponse, $response);
        if ($response['success']) {
            $response['token'] = $curlResponse['checkoutId'];
            $response['transactionId'] = $data['order']['number'];

            /** @var Session $session */
            $session = $this->container->get('session');
            $session->remove('ColoAfterpayInstallmentPlan');
        }

        return $response;
    }

    /**
     * Calls afterpay /api/v3/checkout/payment-methods API method and
     * returns all available payment methods and temporary id for the order
     *
     * @param SyncPaymentTransactionStruct $transaction
     * @param RequestDataBag $dataBag
     * @param SalesChannelContext $salesChannelContext
     * @return array
     */
    private function getAvailablePayments(SyncPaymentTransactionStruct $transaction, RequestDataBag $dataBag, SalesChannelContext $salesChannelContext)
    {
        $response = ["success" => false];
        $order = $transaction->getOrder();
        $customer = $salesChannelContext->getCustomer();
        $countryIso = $customer->getActiveBillingAddress()->getCountry()->getIso();
        $this->initApi($salesChannelContext->getSalesChannelId(), $countryIso, null, true);

        $data = $this->prepareData([], $customer, $order, $dataBag, $salesChannelContext);
        $url = $this->apiUrl . "checkout/payment-methods";
        $curlResponse = $this->curl($url, json_encode($data));
        $this->logger->log('info', $url);
        $this->logger->log('info', json_encode($data));
        $response = $this->handleResponse($curlResponse, $response);
        if ($response['success']) {
            $response['token'] = $curlResponse['checkoutId'];
            $response['transactionId'] = $data['order']['number'];
            $response['paymentMethods'] = $curlResponse['paymentMethods'];
        }

        return $response;
    }

    /**
     * @param Context $context
     * @return OrderEntity[]
     * @throws \Exception
     */
    private function getCapturableOrders(Context $context)
    {
        $pluginConfig = $this->getPluginConfig();
        $orderStates = $pluginConfig['captureOrderStates'];
        $paymentStates = $pluginConfig['capturePaymentStates'];
        $deliveryStates = $pluginConfig['captureDeliveryStates'];
        if (empty($orderStates) || empty($paymentStates) || empty($deliveryStates)) {
            throw new \Exception('Order statuses for capture is not defined in plugin configs');
        }
        if (!is_array($orderStates)) {
            $orderStates = [$orderStates];
        }
        if (!is_array($paymentStates)) {
            $paymentStates = [$paymentStates];
        }
        if (!is_array($deliveryStates)) {
            $deliveryStates = [$deliveryStates];
        }
        $criteria = $this->createOrderCriteria();
        $criteria->addFilter(new MultiFilter(MultiFilter::CONNECTION_OR, [
            new EqualsFilter('customFields.' . AfterPay::CUSTOM_FIELD_NAME_AFTERPAY_CAPTURED, null),
            new EqualsFilter('customFields.' . AfterPay::CUSTOM_FIELD_NAME_AFTERPAY_CAPTURED, 0)
        ]));
        $criteria->addFilter(new NotFilter(NotFilter::CONNECTION_AND, [
            new EqualsFilter('customFields.' . AfterPay::CUSTOM_FIELD_NAME_AFTERPAY_TRANSACTION_ID, null)
        ]));
        $criteria->addFilter(new EqualsAnyFilter('transactions.paymentMethod.handlerIdentifier', [InvoicePayment::class, DirectDebitPayment::class, InstallmentPayment::class]));
        $criteria->addFilter(new EqualsAnyFilter('stateMachineState.id', $orderStates));
        $criteria->addFilter(new EqualsAnyFilter('transactions.stateMachineState.id', $paymentStates));
        $criteria->addFilter(new EqualsAnyFilter('deliveries.stateMachineState.id', $deliveryStates));

        $orders = $this->orderRepo->search($criteria, $context);
        if ($orders->getTotal() > 0) {
            return $orders->getElements();
        }
        return [];
    }

    /**
     * Internal method which prepares customer and order data for API requests
     *
     * @param array $data
     * @param CustomerEntity $customer
     * @param OrderEntity $order
     * @param RequestDataBag $dataBag
     * @param SalesChannelContext $salesChannelContext
     * @return array
     * @throws \Exception
     * @throws InconsistentCriteriaIdsException
     */
    private function prepareData(array $data, CustomerEntity $customer, OrderEntity $order, RequestDataBag $dataBag, SalesChannelContext $salesChannelContext)
    {
        $data = $this->prepareCustomerData($data, $customer, $dataBag, $salesChannelContext);
        $data = $this->prepareOrderData($data, $order, $salesChannelContext);
        $data = $this->prepareAdditionalData($data, $salesChannelContext);

        return $data;
    }

    /**
     * Internal method which prepares customer data for API requests
     *
     * @param array $data
     * @param CustomerEntity $customer
     * @param RequestDataBag $dataBag
     * @param SalesChannelContext $salesChannelContext
     * @return array
     * @throws InconsistentCriteriaIdsException
     */
    private function prepareCustomerData(array $data, CustomerEntity $customer, RequestDataBag $dataBag, SalesChannelContext $salesChannelContext)
    {
        $billingAddress = $customer->getActiveBillingAddress();
        $shippingAddress = $customer->getActiveShippingAddress();
        $addresses = ['billingaddress' => $billingAddress];
        if ($billingAddress->getId() !== $shippingAddress->getId()) {
            $addresses['shippingaddress'] = $shippingAddress;
        }
        foreach ($addresses as $type => $address) {
            /** @var CustomerAddressEntity $address */
            $key = "customer";
            if ($type === "shippingaddress") {
                $key = "deliveryCustomer";
            }
            $countryIso = $address->getCountry()->getIso();

            /** @var SalutationEntity|null $salutation */
            $salutation = $this->getSalutationById($address->getSalutationId(), $salesChannelContext->getContext());
            $salutationKey = '';
            if (!empty($salutation)) {
                if ($salutation->getSalutationKey() === 'mr') {
                    $salutationKey = 'Mr';
                } else if ($salutation->getSalutationKey() === 'mrs') {
                    $salutationKey = 'Mrs';
                }
            }
            $data[$key] = [
                "customerNumber" => $customer->getCustomerNumber(),
                "salutation" => $salutationKey,
                "firstName" => $address->getFirstName(),
                "lastName" => $address->getLastName(),
                "email" => $customer->getEmail(),
                "birthDate" => !empty($customer->getBirthday()) ? $customer->getBirthday()->format('Y-m-d') : "",
                "customerCategory" => "Person",
                "address" => [
                    "street" => $address->getStreet(),
                    "streetNumber" => "",
                    "postalCode" => $address->getZipcode(),
                    "postalPlace" => $address->getCity(),
                    "countryCode" => $countryIso,
                    "careOf" => !empty($address->getAdditionalAddressLine1()) ? $address->getAdditionalAddressLine1() : ""
                ],
                //"conversationLanguage" => $address->getCountry()->getIso() // Needs fix as the language has to be used. Not the country (e.g. AT lang = DE)
            ];
            if ($type === "billingaddress") {
                $data = $this->prepareRiskData($data, $customer, $dataBag, $salesChannelContext);
            }
            if (!empty($address->getPhoneNumber()) && ($countryIso === "NL" || $countryIso === "BE")) {
                $data[$key]["mobilePhone"] = $address->getPhoneNumber();
            }
        }

        return $data;
    }

    /**
     * Internal method which prepares order data for API requests
     *
     * @param array $data
     * @param OrderEntity $order
     * @param SalesChannelContext $salesChannelContext
     * @return array
     * @throws \Exception
     * @throws InconsistentCriteriaIdsException
     */
    private function prepareOrderData(array $data, OrderEntity $order, SalesChannelContext $salesChannelContext)
    {
        $uniqueOrderNumber = $this->generateUniqueOrderNumber();

        $items = $this->prepareOrderItemsData($order, $salesChannelContext->getContext());

        $currency = $this->getCurrencyById($order->getCurrencyId(), $salesChannelContext->getContext());
        $data['order'] = [
            "number" => $uniqueOrderNumber,
            "totalNetAmount" => $this->calcTotalNetPrice($items),
            "totalGrossAmount" => round($order->getAmountTotal(), 2),
            "currency" => empty($currency) ? '' : $currency->getShortName(),
            "items" => $items
        ];

        return $data;
    }

    /**
     * @param array $data
     * @param SalesChannelContext $salesChannelContext
     * @return array
     */
    private function prepareAdditionalData(array $data, SalesChannelContext $salesChannelContext)
    {
        $data['additionalData'] = [
            'pluginProvider' => 'COLOGNATION GmbH',
            'pluginVersion' => $this->getPluginVersion($salesChannelContext->getContext()),
            'shopUrl' => $this->getShopUrl($salesChannelContext),
            'shopPlatform' => 'Shopware 6',
            'shopPlatformVersion' => $this->getShopwareVersion()
        ];
        return $data;
    }

    /**
     * Internal method which prepares capture data
     *
     * @param OrderEntity $order
     * @param Context $context
     * @return array
     * @throws InconsistentCriteriaIdsException
     * @throws \Symfony\Component\DependencyInjection\Exception\ServiceCircularReferenceException
     * @throws \Symfony\Component\DependencyInjection\Exception\ServiceNotFoundException
     */
    private function prepareCaptureData(OrderEntity $order, Context $context)
    {
        $items = $this->prepareOrderItemsData($order, $context);

        $invoiceNumber = $order->getOrderNumber();
        $documents = $order->getDocuments();
        foreach ($documents as $document) {
            if ($document->getDocumentType()->getTechnicalName() === self::INVOICE_DOCUMENT_TYPE_ID) {
                $config = $document->getConfig();
                if (!empty($config) && !empty($config['custom']) && !empty($config['custom']['invoiceNumber'])) {
                    $invoiceNumber = $config['custom']['invoiceNumber'];
                }
                break;
            }
        }
        $currency = $this->getCurrencyById($order->getCurrencyId(), $context);
        return [
            'orderDetails' => [
                'totalGrossAmount' => round($order->getAmountTotal(), 2),
                'totalNetAmount' => $this->calcTotalNetPrice($items),
                'currency' => empty($currency) ? '' : $currency->getShortName(),
                'items' => $items
            ],
            'references' => [
                'yourReference' => $order->getOrderNumber(),
                'contractDate' => $order->getOrderDateTime()->format('Y-m-d H:i:s'),
            ],
            'invoiceNumber' => $invoiceNumber,
            'parentTransactionReference' => $order->getOrderNumber()
        ];
    }

    /**
     * @param OrderEntity $order
     * @param Context $context
     * @return array
     * @throws InconsistentCriteriaIdsException
     * @throws \Symfony\Component\DependencyInjection\Exception\ServiceCircularReferenceException
     * @throws \Symfony\Component\DependencyInjection\Exception\ServiceNotFoundException
     */
    private function prepareOrderItemsData(OrderEntity $order, Context $context)
    {
        $items = [];
        $promotionIndex = 1;
        $customIndex = 1;
        $creditIndex = 1;
        $unknownIndex = 1;
        foreach ($order->getLineItems() as $orderItem) {
            $item = $this->prepareOrderLineData($orderItem, count($items) + 1, $context, $promotionIndex, $customIndex, $creditIndex, $unknownIndex);

            $items[] = $item;
        }
        if ($order->getShippingTotal() > 0) {
            $item = $this->prepareShippingLineData($order, count($items) + 1);

            $items[] = $item;
        }

        return $items;
    }

    /**
     * @param OrderLineItemEntity $orderItem
     * @param int $lineNumber
     * @param Context $context
     * @param int $promotionIndex
     * @param int $customIndex
     * @param int $creditIndex
     * @param int $unknownIndex
     * @return array
     * @throws InconsistentCriteriaIdsException
     * @throws \Symfony\Component\DependencyInjection\Exception\ServiceCircularReferenceException
     * @throws \Symfony\Component\DependencyInjection\Exception\ServiceNotFoundException
     */
    private function prepareOrderLineData(OrderLineItemEntity $orderItem, int $lineNumber, Context $context, int &$promotionIndex, int &$customIndex, int &$creditIndex, int &$unknownIndex)
    {
        $pageUrl = null;
        $imageUrl = null;
        $lineItemType = $orderItem->getType();
        // LineItem::PROMOTION_LINE_ITEM_TYPE is not defined on 6.2
        if ((defined(LineItem::class . '::PROMOTION_LINE_ITEM_TYPE') && $lineItemType === LineItem::PROMOTION_LINE_ITEM_TYPE)
            || $lineItemType === 'promotion') {
            $payload = $orderItem->getPayload();
            $productId = empty($payload) ? 'PROMOTION' . $promotionIndex++ : $payload['code'];
        } else if ($lineItemType === LineItem::PRODUCT_LINE_ITEM_TYPE) {
            $productId = $orderItem->getProductId();
            /** @var ProductEntity $product */
            $product = $this->getProductById($productId, $context);
            if (empty($product)) {
                throw new \Exception('Product not found with id ' . $productId);
            }
            $router = $this->container->get('router');
            $pageUrl = $router->generate(ProductPageSeoUrlRoute::ROUTE_NAME, ['productId' => $productId], RouterInterface::ABSOLUTE_URL);

            if ($product->getCover() instanceof ProductMediaEntity) {
                $imageUrl = $product->getCover()->getMedia()->getUrl();
            }
            $productId = $product->getProductNumber();
        } else if ($lineItemType === LineItem::CREDIT_LINE_ITEM_TYPE) {
            $productId = 'CREDIT' . $creditIndex++;
        } else if ($lineItemType === LineItem::CUSTOM_LINE_ITEM_TYPE) {
            $productId = 'CUSTOM' . $customIndex++;
        } else {
            $productId = 'UNKNOWN' . $unknownIndex++;
        }
        $taxRate = 0;
        $grossUnitPrice = round($orderItem->getPrice()->getUnitPrice(), 2);
        $netUnitPrice = $grossUnitPrice;
        $calculatedTaxes = $orderItem->getPrice()->getCalculatedTaxes();
        if (!empty($calculatedTaxes) && $calculatedTaxes->count() > 0) {
            $tax = $calculatedTaxes->first();
            $taxRate = $tax->getTaxRate();
            $taxUnitAmount = round($grossUnitPrice - $grossUnitPrice / (1 + $taxRate / 100), 2);
            $netUnitPrice -= $taxUnitAmount;
        }
        $item = [
            "productId" => $productId,
            "description" => $orderItem->getLabel(),
            "netUnitPrice" => round($netUnitPrice, 2),
            "grossUnitPrice" => $grossUnitPrice,
            "quantity" => $orderItem->getQuantity(),
            "vatPercent" => $taxRate,
            "vatAmount" => round($grossUnitPrice - $netUnitPrice, 2),
            "lineNumber" => $lineNumber
        ];
        if (!empty($pageUrl)) {
            $item["pageUrl"] = $pageUrl;
        }
        if (!empty($imageUrl)) {
            $item['imageUrl'] = $imageUrl;
        }

        return $item;
    }

    /**
     * @param OrderEntity $order
     * @param int $lineNumber
     * @return array
     */
    private function prepareShippingLineData(OrderEntity $order, int $lineNumber)
    {
        $taxRate = 0;
        $grossPrice = round($order->getShippingTotal(), 2);
        $netPrice = $grossPrice;
        $calculatedTaxes = $order->getShippingCosts()->getCalculatedTaxes();
        if (!empty($calculatedTaxes) && $calculatedTaxes->count() > 0) {
            $tax = $calculatedTaxes->first();
            $taxRate = $tax->getTaxRate();
            $taxAmount = round($tax->getTax(), 2);
            $netPrice -= $taxAmount;
        }
        $productDescription = 'Versandkosten';
        return [
            "productId" => 'SHIPPINGCOST',
            "description" => $productDescription,
            "netUnitPrice" => round($netPrice, 2),
            "grossUnitPrice" => $grossPrice,
            "quantity" => 1,
            "vatPercent" => $taxRate,
            "vatAmount" => round($grossPrice - $netPrice, 2),
            "lineNumber" => $lineNumber
        ];
    }

    /**
     * Initializes API details
     * @param string $salesChannelId
     * @param string $countryIso
     * @param ?string $transactionMode
     * @param bool $force
     * @throws \Exception
     */
    private function initApi(string $salesChannelId, string $countryIso, string $transactionMode = null, bool $force = false)
    {
        $config = $this->getPluginConfig($salesChannelId);
        if (empty($config)) {
            throw new \Exception('Config is not valid');
        }
        if (empty($transactionMode)) {
            $transactionMode = $config['mode'];
        }
        if ((!isset($this->apiKey) && !empty($countryIso)) || $force) {
            if (!isset($config['apiKey' . strtoupper($countryIso)])) {
                throw new \Exception('Payment not available for country ' . $countryIso);
            }
            $apiKey = $config['apiKey' . strtoupper($countryIso)];
            $apiUrl = $this->constants['urls'][$transactionMode];
            if (empty($apiKey) || empty($apiUrl)) {
                throw new \Exception('API key or API URL is not defined');
            }
            $this->apiHeaders = [
                "cache-control: no-cache",
                "content-type: application/json",
                "x-auth-key: {$apiKey}"
            ];
            $this->apiKey = $apiKey;
            $this->apiUrl = $apiUrl;
        }
    }

    /**
     * @param array $items
     * @return float|int
     */
    private function calcTotalNetPrice(array $items)
    {
        $totalNetPrice = 0.0;
        foreach ($items as $item) {
            $totalNetPrice += $item['netUnitPrice'] * $item['quantity'];
        }
        return round($totalNetPrice, 2);
    }

    /**
     * @param array $data
     * @param CustomerEntity $customer
     * @param RequestDataBag $dataBag
     * @param SalesChannelContext $salesChannelContext
     * @return array
     * @throws \Symfony\Component\DependencyInjection\Exception\ServiceCircularReferenceException
     * @throws \Symfony\Component\DependencyInjection\Exception\ServiceNotFoundException
     */
    private function prepareRiskData(array $data, CustomerEntity $customer, RequestDataBag $dataBag, SalesChannelContext $salesChannelContext)
    {
        $pluginConfig = $this->getPluginConfig($salesChannelContext->getSalesChannelId());
        /** @var Request $request */
        $request = $this->container->get('request_stack')->getCurrentRequest();
        $data['customer']['riskData'] = [
            'existingCustomer' => $customer->getOrderCount() > 0,
            'numberOfTransactions' => $customer->getOrderCount(),
        ];
        $firstLogin = $customer->getFirstLogin();
        if ($firstLogin instanceof \DateTimeInterface) {
            $data['customer']['riskData']['customerSince'] = $firstLogin->format('Y-m-d');
        }
        if (in_array($pluginConfig['profileTrackingSetup'], ['optional', 'mandatory'])) {
            $data['customer']['riskData']['ipAddress'] = $request->getClientIp();
            if ($dataBag->has('afterpay_tos') && $dataBag->get('afterpay_tos') === 'on') {
                $data['customer']['riskData']['profileTrackingId'] = $salesChannelContext->getToken();
            }
        }

        return $data;
    }

    /**
     * Handles response from Afterpay API
     * @param array $curlResponse
     * @param array $response
     * @return array
     */
    private function handleResponse(array $curlResponse, array $response)
    {
        if ((!empty($curlResponse) && !empty($curlResponse['outcome']) && $curlResponse['outcome'] === 'Accepted') ||
            (!empty($curlResponse) && !empty($curlResponse['isValid'])) ||
            (!empty($curlResponse) &&
                !empty($curlResponse['contractId']) &&
                !empty($curlResponse['contractList']) &&
                $curlResponse['contractId'] !== "00000000-0000-0000-0000-000000000000") ||
            (!empty($curlResponse) && !empty($curlResponse['captureNumber']) ||
                (!empty($curlResponse) && !empty($curlResponse['availableInstallmentPlans']))) ||
            (!empty($curlResponse) && !empty($curlResponse['refundNumbers']))) {
            $this->logger->log('info', json_encode($curlResponse));
            if (!empty($curlResponse['captureNumber'])) {
                $response['captureNumber'] = $curlResponse['captureNumber'];
            }
            if (!empty($curlResponse['availableInstallmentPlans'])) {
                $response['availableInstallmentPlans'] = $curlResponse['availableInstallmentPlans'];
            }
            if (!empty($curlResponse['refundNumbers'])) {
                $response['refundNumbers'] = $curlResponse['refundNumbers'];
                $response['totalAuthorizedAmount'] = $curlResponse['totalAuthorizedAmount'];
                $response['totalCapturedAmount'] = $curlResponse['totalCapturedAmount'];
            }
            $response['success'] = true;
        } else {
            $this->logger->log('error', json_encode($curlResponse));
            if (!empty($curlResponse['riskCheckMessages'])) {
                $response['message'] = "";
                foreach ($curlResponse['riskCheckMessages'] as $riskMessage) {
                    if (!empty($riskMessage['customerFacingMessage'])) {
                        $response['message'] .= $riskMessage['customerFacingMessage'] . "<br/>";
                    } else {
                        if ($riskMessage['actionCode'] === "AskConsumerToReEnterData") {
                            $message = $this->translator->trans('afterpay.messages.AskConsumerToReEnterDataError');
                            $response['message'] .= $message . "<br/>";
                        } else if ($riskMessage['actionCode'] === "AskConsumerToConfirm") {
                            $message = $this->translator->trans('afterpay.messages.AskConsumerToConfirm');
                            $response['message'] .= $message . "<br/>";
                        } else if ($riskMessage['actionCode'] === "OfferSecurePaymentMethods") {
                            $message = $this->translator->trans('afterpay.messages.OfferSecurePaymentMethodsError');
                            $response['message'] .= $message . "<br/>";
                        }
                    }
                    if (($riskMessage['actionCode'] === "AskConsumerToReEnterData" || $riskMessage['actionCode'] === "AskConsumerToConfirm") && !isset($response['address'])) {
                        if (!empty($curlResponse['customer']) && !empty($curlResponse['customer']['addressList'])) {
                            $address = $curlResponse['customer']['addressList'][0];
                            $street = $address['street'];
                            if (!empty($address['streetNumber'])) {
                                $street .= " " . $address['streetNumber'];
                            }
                            $response['address'] = [
                                'street' => $street,
                                'zipcode' => $address['postalCode'],
                                'city' => $address['postalPlace'],
                                'country' => $address['countryCode'],
                            ];
                        } else {
                            $response['address'] = 1;
                        }
                    }
                }
                if (!empty($response['message'])) {
                    $response['message'] = substr($response['message'], 0, -5);
                }
            } else if (!empty($curlResponse[0]) && !empty($curlResponse[0]['customerFacingMessage'])) {
                $response['message'] = $curlResponse[0]['customerFacingMessage'];
            } else if (!empty($curlResponse) && !empty($curlResponse['customerFacingMessage'])) {
                $response['message'] = $curlResponse['customerFacingMessage'];
            } else if (!empty($curlResponse) && !empty($curlResponse['message'])) {
                $response['message'] = $curlResponse['message'];
            }
        }

        return $response;
    }

    /**
     * @param string $id
     * @param Context $context
     * @return SalutationEntity|null
     * @throws InconsistentCriteriaIdsException
     */
    private function getSalutationById(string $id, Context $context)
    {
        $criteria = new Criteria([$id]);
        $searchResult = $this->salutationRepo->search($criteria, $context);
        if ($searchResult->count() === 0) {
            return null;
        }

        return $searchResult->first();
    }

    /**
     * @param string $id
     * @param Context $context
     * @return ProductEntity|null
     * @throws InconsistentCriteriaIdsException
     */
    private function getProductById(string $id, Context $context)
    {
        $criteria = (new Criteria([$id]))
            ->addAssociation('cover')
            ->addAssociation('media');
        $searchResult = $this->productRepo->search($criteria, $context);
        if ($searchResult->count() === 0) {
            return null;
        }

        return $searchResult->first();
    }

    /**
     * @param string $id
     * @param Context $context
     * @return CurrencyEntity|null
     * @throws InconsistentCriteriaIdsException
     */
    private function getCurrencyById(string $id, Context $context)
    {
        $criteria = new Criteria([$id]);
        $criteria->addAssociation('media');
        $searchResult = $this->currencyRepo->search($criteria, $context);
        if ($searchResult->count() === 0) {
            return null;
        }

        return $searchResult->first();
    }

    /**
     * Internal method for generating unique ordernumber
     *
     * @return string
     */
    private function generateUniqueOrderNumber()
    {
        $characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $charactersLength = strlen($characters);
        $randomString = '';
        for ($i = 0; $i < 16; $i++) {
            $randomString .= $characters[rand(0, $charactersLength - 1)];
        }

        return $randomString;
    }

    /**
     * @param Context $context
     * @return mixed
     */
    private function getPluginVersion(Context $context)
    {
        $plugin = $this->container->get('plugin.repository')->search((new Criteria())->addFilter(new EqualsFilter('name', 'AfterPay')), $context)->first();
        if (empty($plugin)) {
            return '';
        }
        return $plugin->getVersion();
    }

    /**
     * @return string
     */
    private function getShopwareVersion()
    {
        $shopPlatformVersion = '';
        if (class_exists(InstalledVersions::class)) {
            $shopPlatformVersion = InstalledVersions::getVersion('shopware/core');
        }
        return $shopPlatformVersion;
    }

    /**
     * @param SalesChannelContext $salesChannelContext
     * @return string
     */
    private function getShopUrl(SalesChannelContext $salesChannelContext)
    {
        $shopUrl = '';
        foreach ($salesChannelContext->getSalesChannel()->getDomains() as $domain) {
            if (!empty($domain->getUrl())) {
                $shopUrl = $domain->getUrl();
                break;
            }
        }
        return $shopUrl;
    }

    /**
     * Utility method for sending cURL to afterpay API
     *
     * @param string $url
     * @param string $data
     * @param array $headers
     * @param boolean $json
     * @return array
     */
    private function curl(string $url, string $data = "", array $headers = [], bool $json = true)
    {
        $curl = curl_init();
        curl_setopt($curl, CURLOPT_URL, $url);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, 0);
        curl_setopt($curl, CURLOPT_MAXREDIRS, 10);
        curl_setopt($curl, CURLOPT_HEADER, 0);
        curl_setopt($curl, CURLOPT_TIMEOUT, 30);

        if (!empty($data)) {
            curl_setopt($curl, CURLOPT_POST, 1);
            curl_setopt($curl, CURLOPT_POSTFIELDS, $data);
        }
        $apiHeaders = array_merge($this->apiHeaders, $headers);
        if (!empty($apiHeaders)) {
            curl_setopt($curl, CURLOPT_HTTPHEADER, $apiHeaders);
        }
        $response = curl_exec($curl);
        if ($json) {
            $response = json_decode($response, true);
        }
        curl_close($curl);

        return $response;
    }
}