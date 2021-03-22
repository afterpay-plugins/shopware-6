<?php declare(strict_types=1);

namespace Colo\AfterPay\Service;

use Colo\AfterPay\AfterPay;
use Shopware\Core\Checkout\Payment\Cart\PaymentHandler\AsynchronousPaymentHandlerInterface;
use Shopware\Core\Checkout\Payment\Cart\AsyncPaymentTransactionStruct;
use Shopware\Core\Checkout\Payment\Exception\AsyncPaymentProcessException;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStateHandler;
use Shopware\Core\Content\Media\MediaEntity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepositoryInterface;
use Shopware\Core\Content\Product\ProductEntity;
use Shopware\Core\System\Currency\CurrencyEntity;
use Shopware\Core\System\Salutation\SalutationEntity;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Checkout\Customer\CustomerEntity;
use Shopware\Core\Checkout\Customer\Aggregate\CustomerAddress\CustomerAddressEntity;
use Shopware\Core\Framework\DataAbstractionLayer\Exception\InconsistentCriteriaIdsException;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Shopware\Core\Framework\Context;
use Symfony\Component\HttpFoundation\Request;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Checkout\Cart\SalesChannel\CartService;
use Shopware\Core\Checkout\Order\Aggregate\OrderLineItem\OrderLineItemEntity;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Colo\AfterPay\Traits\HelperTrait;

class InvoicePayment implements AsynchronousPaymentHandlerInterface
{
    use HelperTrait;

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
     * @var CartService
     */
    private $cartService;

    /**
     * @var TranslatorInterface
     */
    private $translator;

    /**
     * @var Session
     */
    private $session;

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
     * InvoicePayment constructor.
     * @param OrderTransactionStateHandler $transactionStateHandler
     * @param ContainerInterface $container
     * @param EntityRepositoryInterface $salutationRepo
     * @param EntityRepositoryInterface $productRepo
     * @param EntityRepositoryInterface $orderRepo
     * @param EntityRepositoryInterface $currencyRepo
     * @param CartService $cartService
     * @param TranslatorInterface $translator
     * @param SystemConfigService $systemConfigService
     * @param Session $session
     */
    public function __construct(
        OrderTransactionStateHandler $transactionStateHandler,
        ContainerInterface $container,
        EntityRepositoryInterface $salutationRepo,
        EntityRepositoryInterface $productRepo,
        EntityRepositoryInterface $orderRepo,
        EntityRepositoryInterface $currencyRepo,
        CartService $cartService,
        TranslatorInterface $translator,
        SystemConfigService $systemConfigService,
        Session $session
    )
    {
        $this->transactionStateHandler = $transactionStateHandler;
        $this->container = $container;
        $this->salutationRepo = $salutationRepo;
        $this->productRepo = $productRepo;
        $this->orderRepo = $orderRepo;
        $this->currencyRepo = $currencyRepo;
        $this->cartService = $cartService;
        $this->translator = $translator;
        $this->systemConfigService = $systemConfigService;
        $this->session = $session;
        if (file_exists(__DIR__ . '/../Constants.php')) {
            $this->constants = include __DIR__ . '/../Constants.php';
        }
    }

    /**
     * @param AsyncPaymentTransactionStruct $transaction
     * @param RequestDataBag $dataBag
     * @param SalesChannelContext $salesChannelContext
     * @return RedirectResponse
     * @throws \Exception
     */
    public function pay(AsyncPaymentTransactionStruct $transaction, RequestDataBag $dataBag, SalesChannelContext $salesChannelContext): RedirectResponse
    {
        $customer = $salesChannelContext->getCustomer();
        $order = $transaction->getOrder();
        $context = $salesChannelContext->getContext();
        try {
            $countryIso = $customer->getActiveBillingAddress()->getCountry()->getIso();
            $this->initApi($countryIso);

            $response = $this->authorizePayment($customer, $order, $context);
            if (!$response['success']) {
                throw new \Exception(!empty($response['message']) ? $response['message'] : 'Unidentified error');
            }
            $this->orderRepo->upsert([[
                'id' => $order->getId(),
                'customFields' => [AfterPay::CUSTOM_FIELD_NAME_AFTERPAY_TRANSACTION_ID => $response['transactionId']]
            ]], $context);
        } catch (\Exception $ex) {
            try {
                $this->addProductsToCart($order, $salesChannelContext);
                $this->orderRepo->delete([['id' => $order->getId()]], $context);
            } catch (\Exception $e) {
                throw new AsyncPaymentProcessException(
                    $transaction->getOrderTransaction()->getId(),
                    $e->getMessage()
                );
            }

            if ($this->container->has('session')) {
                $this->container->get('session')->getFlashBag()->add('danger', $ex->getMessage());
            }
            $confirmUrl = $this->container->get('router')->generate('frontend.checkout.confirm.page', []);

            return new RedirectResponse($confirmUrl);
        }
        $finishUrl = $this->container->get('router')->generate('frontend.checkout.finish.page', [
            'orderId' => $order->getId()
        ]);

        return new RedirectResponse($finishUrl);
    }

    public function finalize(AsyncPaymentTransactionStruct $transaction, Request $request, SalesChannelContext $salesChannelContext): void
    {

    }

    /**
     * Calls afterpay /api/v3/checkout/authorize API method
     * and returns transaction id and temporary id for the order
     *
     * @param CustomerEntity $customer
     * @param OrderEntity $order
     * @param Context $context
     * @return array
     * @throws InconsistentCriteriaIdsException
     */
    public function authorizePayment($customer, $order, $context)
    {
        $response = ["success" => false];
        $data = [
            'payment' => [
                'type' => 'Invoice'
            ]
        ];
        $data = $this->prepareData($data, $customer, $order, $context);
        $url = $this->apiUrl . "checkout/authorize";

        $curlResponse = $this->curl($url, json_encode($data));
        $response = $this->handleResponse($curlResponse, $response);
        if ($response['success']) {
            $response['token'] = $curlResponse['checkoutId'];
            $response['transactionId'] = $data['order']['number'];
        }

        return $response;
    }

    /**
     * Internal method which prepares customer and order data for API requests
     *
     * @param array $data
     * @param CustomerEntity $customer
     * @param OrderEntity $order
     * @param Context $context
     * @return array
     * @throws \Exception
     * @throws InconsistentCriteriaIdsException
     */
    private function prepareData($data, $customer, $order, $context)
    {
        $uniqueOrderNumber = $this->generateUniqueOrderNumber();
        $data = $this->prepareCustomerData($data, $customer, $context);
        $data = $this->prepareOrderData($data, $uniqueOrderNumber, $order, $context);

        return $data;
    }

    /**
     * Internal method which prepares customer data for API requests
     *
     * @param array $data
     * @param CustomerEntity $customer
     * @param Context $context
     * @return array
     * @throws InconsistentCriteriaIdsException
     */
    private function prepareCustomerData($data, $customer, $context)
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
            $salutation = $this->getSalutationById($address->getSalutationId(), $context);
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
                $data = $this->prepareRiskData($data, $customer);
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
     * @param string $uniqueOrderNumber
     * @param OrderEntity $order
     * @param Context $context
     * @return array
     * @throws \Exception
     * @throws InconsistentCriteriaIdsException
     */
    private function prepareOrderData($data, $uniqueOrderNumber, $order, $context)
    {
        $items = [];
        $promotionIndex = 1;
        $customIndex = 1;
        $creditIndex = 1;
        $unknownIndex = 1;
        $totalNetPrice = 0;
        foreach ($order->getLineItems() as $orderItem) {
            /** @var OrderLineItemEntity $orderItem */
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
                $taxUnitAmount = round($tax->getTax() / $orderItem->getQuantity(), 2);
                $netUnitPrice = $grossUnitPrice - $taxUnitAmount;
            }
            $item = [
                "productId" => $productId,
                "description" => $orderItem->getLabel(),
                "netUnitPrice" => $netUnitPrice,
                "grossUnitPrice" => $grossUnitPrice,
                "quantity" => $orderItem->getQuantity(),
                "vatPercent" => $taxRate,
                "vatAmount" => $grossUnitPrice - $netUnitPrice,
                "lineNumber" => count($items) + 1
            ];
            if ($orderItem->getCover() instanceof MediaEntity) {
                $item['imageUrl'] = $orderItem->getCover()->getUrl();
            }
            $items[] = $item;

            $totalNetPrice += $netUnitPrice * $orderItem->getQuantity();
        }
        if ($order->getShippingTotal() > 0) {
            $taxRate = 0;
            $grossPrice = round($order->getShippingTotal(), 2);
            $netPrice = $grossPrice;
            $calculatedTaxes = $order->getShippingCosts()->getCalculatedTaxes();
            if (!empty($calculatedTaxes) && $calculatedTaxes->count() > 0) {
                $tax = $calculatedTaxes->first();
                $taxRate = $tax->getTaxRate();
                $taxAmount = round($tax->getTax(), 2);
                $netPrice = $grossPrice - $taxAmount;
            }
            $lineNumber = count($items) + 1;
            $productDescription = 'Versandkosten';
            $items[] = [
                "productId" => 'SHIPPINGCOST',
                "description" => $productDescription,
                "netUnitPrice" => $netPrice,
                "grossUnitPrice" => $grossPrice,
                "quantity" => 1,
                "vatPercent" => $taxRate,
                "vatAmount" => $grossPrice - $netPrice,
                "lineNumber" => $lineNumber
            ];
            $totalNetPrice += $netPrice;
        }
        $currency = $this->getCurrencyById($order->getCurrencyId(), $context);
        $data['order'] = [
            "number" => $uniqueOrderNumber,
            "totalNetAmount" => $totalNetPrice,
            "totalGrossAmount" => $order->getAmountTotal(),
            "currency" => empty($currency) ? '' : $currency->getShortName(),
            "items" => $items
        ];

        return $data;
    }

    /**
     * @param array $data
     * @param CustomerEntity $customer
     * @return mixed
     */
    private function prepareRiskData($data, $customer)
    {
        /** @var Request $request */
        $request = $this->container->get('request_stack')->getCurrentRequest();
        $data['customer']['riskData'] = [
            'ipAddress' => $request->getClientIp(),
            'existingCustomer' => $customer->getOrderCount() - 1 > 0 ? true : false,
            'numberOfTransactions' => $customer->getOrderCount() - 1,
            'customerSince' => $customer->getFirstLogin()->format('Y-m-d'),
            'profileTrackingId' => $this->session->getId()
        ];

        return $data;
    }

    /**
     * @param OrderEntity $order
     * @param SalesChannelContext $salesChannelContext
     * @throws \Shopware\Core\Checkout\Cart\Exception\InvalidQuantityException
     * @throws \Shopware\Core\Checkout\Cart\Exception\LineItemNotStackableException
     * @throws \Shopware\Core\Checkout\Cart\Exception\MixedLineItemTypeException
     */
    private function addProductsToCart($order, $salesChannelContext)
    {
        $cart = $this->cartService->getCart($salesChannelContext->getToken(), $salesChannelContext);
        $lineItems = $order->getLineItems();
        foreach ($lineItems as $lineItemData) {
            /** @var OrderLineItemEntity $lineItemData */
            $lineItem = new LineItem(
                $lineItemData->getId(),
                $lineItemData->getType(),
                $lineItemData->getReferencedId(),
                $lineItemData->getQuantity()
            );

            $lineItem->setStackable($lineItemData->getStackable());
            $lineItem->setRemovable($lineItemData->getRemovable());

            $cart = $this->cartService->add($cart, $lineItem, $salesChannelContext);
        }
    }

    /**
     * Handles response from Afterpay API
     * @param array $curlResponse
     * @param array $response
     * @return array
     */
    private function handleResponse($curlResponse, $response)
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
    private function getSalutationById($id, $context)
    {
        $criteria = new Criteria([$id]);
        $searchResult = $this->salutationRepo->search($criteria, $context);
        if (!empty($searchResult) && $searchResult->count() === 0) {
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
    private function getProductById($id, $context)
    {
        $criteria = new Criteria([$id]);
        $criteria->addAssociation('media');
        $searchResult = $this->productRepo->search($criteria, $context);
        if (!empty($searchResult) && $searchResult->count() === 0) {
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
    private function getCurrencyById($id, $context)
    {
        $criteria = new Criteria([$id]);
        $criteria->addAssociation('media');
        $searchResult = $this->currencyRepo->search($criteria, $context);
        if (!empty($searchResult) && $searchResult->count() === 0) {
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
     * Initializes API details
     * @param string $countryIso
     * @param bool $force
     * @throws \Exception
     */
    private function initApi($countryIso, $force = false)
    {
        $config = $this->getPluginConfig();
        if (empty($config)) {
            throw new \Exception('Config is not valid');
        }
        if ((!isset($this->apiKey) && !empty($countryIso)) || $force) {
            if (!isset($config['apiKey' . strtoupper($countryIso)])) {
                throw new \Exception('Payment not available for country ' . $countryIso);
            }
            $apiKey = $config['apiKey' . strtoupper($countryIso)];
            $apiUrl = $this->constants['urls'][$config['mode']];
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
     * Utility method for sending cURL to afterpay API
     *
     * @param string $url
     * @param string $data
     * @param array $headers
     * @param boolean $json
     * @return array
     */
    private function curl($url, $data = "", $headers = [], $json = true)
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
