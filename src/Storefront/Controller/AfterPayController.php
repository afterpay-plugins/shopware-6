<?php declare(strict_types=1);

namespace Colo\AfterPay\Storefront\Controller;

use Colo\AfterPay\Service\AfterpayService;
use Shopware\Core\Checkout\Cart\SalesChannel\CartService;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepositoryInterface;
use Shopware\Core\Framework\Routing\Annotation\RouteScope;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Storefront\Controller\StorefrontController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpFoundation\Request;

/**
 * @RouteScope(scopes={"storefront"})
 */
class AfterPayController extends StorefrontController
{

    /**
     * @var EntityRepositoryInterface
     */
    private $customerRepository;

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
     * @param CartService $cartService
     * @param AfterpayService $afterpayService
     */
    public function __construct(EntityRepositoryInterface $customerRepository, CartService $cartService, AfterpayService $afterpayService)
    {
        $this->customerRepository = $customerRepository;
        $this->cartService = $cartService;
        $this->afterpayService = $afterpayService;
    }

    /**
     * @Route("/afterpay/save-bank-details", name="frontend.afterpay.save-bank-details", defaults={"csrf_protected"=false, "XmlHttpRequest"=true}, options={"seo"="false"}, methods={"POST"})
     */
    public function saveBankDetails(Request $request, SalesChannelContext $salesChannelContext)
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
            $requiredFields = explode(',', $requestParams['required_fields']);
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
        if (!empty($requestParams['afterpay_installment_plan'])) {
            $this->container->get('session')->set('ColoAfterpayInstallmentPlan', $requestParams['afterpay_installment_plan']);
            unset($requestParams['afterpay_installment_plan']);
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
        $this->customerRepository->update([
            [
                'id' => $customer->getId(),
                'customFields' => $customFields,
            ],
        ], $salesChannelContext->getContext());

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
                $variables = [
                    'coloAfterpayInstallments' => $installments,
                    'coloAfterpayBasketAmount' => $cart->getPrice()->getTotalPrice()
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
}