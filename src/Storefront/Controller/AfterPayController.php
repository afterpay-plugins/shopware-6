<?php declare(strict_types=1);

namespace Colo\AfterPay\Storefront\Controller;

use Colo\AfterPay\Service\AfterpayService;
use Colo\AfterPay\Service\Payments\InstallmentPayment;
use Shopware\Core\Checkout\Cart\SalesChannel\CartService;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepositoryInterface;
use Shopware\Core\Framework\Routing\Annotation\RouteScope;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Shopware\Storefront\Controller\StorefrontController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Colo\AfterPay\Traits\HelperTrait;

/**
 * @RouteScope(scopes={"storefront"})
 */
class AfterPayController extends StorefrontController
{
    use HelperTrait;

    /**
     * @var EntityRepositoryInterface
     */
    private $customerRepository;

    /**
     * @var EntityRepositoryInterface
     */
    private $addressRepository;

    /**
     * @var CartService
     */
    private $cartService;

    /**
     * @var AfterpayService
     */
    private $afterpayService;

    /**
     * AfterPayController constructor.
     * @param EntityRepositoryInterface $customerRepository
     * @param EntityRepositoryInterface $addressRepository
     * @param CartService $cartService
     * @param AfterpayService $afterpayService
     * @param SystemConfigService $systemConfigService
     */
    public function __construct(
        EntityRepositoryInterface $customerRepository,
        EntityRepositoryInterface $addressRepository,
        CartService               $cartService,
        AfterpayService           $afterpayService,
        SystemConfigService       $systemConfigService
    )
    {
        $this->customerRepository = $customerRepository;
        $this->addressRepository = $addressRepository;
        $this->cartService = $cartService;
        $this->afterpayService = $afterpayService;
        $this->systemConfigService = $systemConfigService;
    }

    /**
     * @Route("/afterpay/save-payment-details", name="frontend.afterpay.save-payment-details", defaults={"csrf_protected"=false, "XmlHttpRequest"=true}, options={"seo"="false"}, methods={"POST"})
     */
    public function savePaymentDetails(Request $request, SalesChannelContext $salesChannelContext)
    {
        $response = ['success' => false];
        $customer = $salesChannelContext->getCustomer();
        if (empty($customer)) {
            $response['code'] = 'CUSTOMER_NOT_LOGGED_IN';
            return new JsonResponse($response);
        }
        $requiredFields = [];
        $errorFlags = [];
        $requestParams = $request->request->all();
        if (!empty($requestParams['required_fields'])) {
            $requiredFields = array_filter(array_map('trim', explode(',', $requestParams['required_fields'])));
            foreach ($requiredFields as $requiredField) {
                if (empty($requestParams[$requiredField]) && empty($requestParams[$requiredField . '_masked'])) {
                    $errorFlags[$requiredField] = 1;
                }
            }
        }
        if (!empty($errorFlags)) {
            $response['code'] = 'BLANK_FIELDS';
            $response['errorFlags'] = $errorFlags;
            return new JsonResponse($response);
        }
        $birthday = null;
        if (!empty($requestParams['birthdayDay'])) {
            if (checkdate((int)$requestParams['birthdayMonth'], (int)$requestParams['birthdayDay'], (int)$requestParams['birthdayYear'])) {
                $birthday = (new \DateTime($requestParams['birthdayMonth'] . '/' . $requestParams['birthdayDay'] . '/' . $requestParams['birthdayYear']))->format('Y-m-d');
            } else {
                $response['code'] = 'BLANK_FIELDS';
                $response['errorFlags']['birthdayDay'] = 1;
                $response['errorFlags']['birthdayMonth'] = 1;
                $response['errorFlags']['birthdayYear'] = 1;
                return new JsonResponse($response);
            }
            unset($requestParams['birthdayDay']);
            unset($requestParams['birthdayMonth']);
            unset($requestParams['birthdayYear']);
        }
        $context = $salesChannelContext->getContext();
        if (!empty($requestParams['afterpay_installment_plan'])) {
            $this->container->get('session')->set('ColoAfterpayInstallmentPlan', $requestParams['afterpay_installment_plan']);
            unset($requestParams['afterpay_installment_plan']);
        }
        if (!empty($requestParams['phoneNumber'])) {
            $billingAddress = $customer->getActiveBillingAddress();
            if (!empty($billingAddress) && $billingAddress->getPhoneNumber() !== $requestParams['phoneNumber']) {
                $this->addressRepository->update([
                    [
                        'id' => $billingAddress->getId(),
                        'phoneNumber' => $requestParams['phoneNumber'],
                    ],
                ], $context);
            }
            unset($requestParams['phoneNumber']);
        }
        $customFields = $customer->getCustomFields();
        foreach ($requestParams as $name => $value) {
            if ($name === 'required_fields'
                || (in_array($name, $requiredFields) && empty($value))
                || strpos($name, '_masked') !== false) {
                continue;
            }
            $customFields[$name] = $value;
        }
        $updateParams = [
            'id' => $customer->getId(),
            'customFields' => $customFields,
        ];
        if (!empty($birthday)) {
            $updateParams['birthday'] = $birthday;
        }
        $this->customerRepository->update([
            $updateParams,
        ], $context);

        $response['success'] = true;
        return new JsonResponse($response);
    }

    /**
     * @Route("/afterpay/get-installments", name="frontend.afterpay.get-installments", defaults={"csrf_protected"=false, "XmlHttpRequest"=true}, options={"seo"="false"}, methods={"POST"})
     */
    public function getInstallments(Request $request, SalesChannelContext $salesChannelContext)
    {
        $customer = $salesChannelContext->getCustomer();
        if (empty($customer)) {
            return $this->renderStorefront('@Storefront/storefront/component/afterpay/installment-error.html.twig');
        }
        try {
            $cart = $this->cartService->getCart($salesChannelContext->getToken(), $salesChannelContext);
            $installments = $this->afterpayService->getAvailableInstallments($salesChannelContext, round($cart->getPrice()->getTotalPrice(), 2));
            if (!empty($installments)) {
                $shopLanguage = $this->afterpayService->getShopLanguage($salesChannelContext);
                $shippingCountryCode = $this->afterpayService->getShippingCountryCode($customer);
                $variables = [
                    'coloAfterpayInstallments' => $installments,
                    'coloAfterpayBasketAmount' => $cart->getPrice()->getTotalPrice(),
                    'coloAfterpayMerchantID' => $this->afterpayService->getMerchantId($salesChannelContext),
                    'coloAfterpayLanguageCode' => $shopLanguage . '_' . $shippingCountryCode,
                    'coloAfterpayShopLanguage' => $shopLanguage,
                    'coloAfterpayShippingCountryCode' => $shippingCountryCode,
                    'coloAfterpayMerchantPaymentMethod' => InstallmentPayment::getName()
                ];
                $installmentPlan = $this->container->get('session')->get('ColoAfterpayInstallmentPlan');
                if (!empty($installmentPlan)) {
                    $variables['coloAfterpaySelectedInstallment'] = $installmentPlan;
                }
                return $this->renderStorefront('@Storefront/storefront/component/afterpay/installment-success.html.twig', $variables);
            }
        } catch (\Exception $ex) {
        }
        return $this->renderStorefront('@Storefront/storefront/component/afterpay/installment-error.html.twig');
    }

    /**
     * @Route("/afterpay/tracking", name="frontend.afterpay.tracking", defaults={"csrf_protected"=false, "XmlHttpRequest"=true}, options={"seo"="false"}, methods={"GET"})
     */
    public function tracking(Request $request, SalesChannelContext $salesChannelContext)
    {
        $variables = ['loadTracking' => false];
        $pluginConfig = $this->getPluginConfig($salesChannelContext->getSalesChannelId());
        if (in_array($pluginConfig['profileTrackingSetup'], ['optional', 'mandatory'])
            && !empty($pluginConfig['trackingId'])
            && trim($pluginConfig['trackingId'])) {
            $variables['loadTracking'] = true;
        }
        return $this->renderStorefront('@Storefront/storefront/component/afterpay/tracking.html.twig', $variables);
    }
}