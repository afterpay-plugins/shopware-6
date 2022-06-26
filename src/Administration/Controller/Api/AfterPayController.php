<?php declare(strict_types=1);

namespace Colo\AfterPay\Administration\Controller\Api;

use Colo\AfterPay\Service\AfterpayService;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepositoryInterface;
use Symfony\Component\Routing\Annotation\Route;
use Shopware\Core\Framework\Routing\Annotation\RouteScope;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Shopware\Core\Framework\Context;

/**
 * @RouteScope(scopes={"api"})
 */
class AfterPayController extends AbstractController
{
    /**
     * @var EntityRepositoryInterface
     */
    private $orderRepository;

    /**
     * @var AfterpayService
     */
    private $afterpayService;

    /**
     * AfterPayController constructor
     * @param EntityRepositoryInterface $orderRepository
     * @param AfterpayService $afterpayService
     */
    public function __construct(
        EntityRepositoryInterface $orderRepository,
        AfterpayService $afterpayService
    )
    {
        $this->orderRepository = $orderRepository;
        $this->afterpayService = $afterpayService;
    }

    /**
     * @Route("/api/v{version}/_action/afterpay/capture", name="api.action.afterpay.capture", methods={"POST"})
     */
    public function capture(RequestDataBag $requestDataBag, Context $context): JsonResponse
    {
        $orderId = $requestDataBag->get('id');
        $criteria = $this->afterpayService->createOrderCriteria([$orderId]);
        /** @var OrderEntity $order */
        $order = $this->orderRepository->search($criteria, $context)->first();
        if (empty($order)) {
            return JsonResponse::create(['success' => false]);
        }
        $success = $this->afterpayService->capturePayment($order, $context);
        return JsonResponse::create(['success' => $success]);
    }
}