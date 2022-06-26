<?php declare(strict_types=1);

namespace Colo\AfterPay\Service\Payments;

use Colo\AfterPay\Service\AfterpayService;
use Shopware\Core\Checkout\Payment\Cart\PaymentHandler\SynchronousPaymentHandlerInterface;
use Shopware\Core\Checkout\Payment\Cart\SyncPaymentTransactionStruct;
use Shopware\Core\Checkout\Payment\Exception\SyncPaymentProcessException;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

class InvoicePayment implements SynchronousPaymentHandlerInterface
{

    /**
     * @var AfterpayService
     */
    protected $afterpayService;

    /**
     * InvoicePayment constructor.
     * @param AfterpayService $afterpayService
     */
    public function __construct(AfterpayService $afterpayService)
    {
        $this->afterpayService = $afterpayService;
    }

    /**
     * @return string
     */
    public static function getName(): string
    {
        return 'invoice';
    }

    /**
     * @param SyncPaymentTransactionStruct $transaction
     * @param RequestDataBag $dataBag
     * @param SalesChannelContext $salesChannelContext
     * @throws SyncPaymentProcessException
     */
    public function pay(SyncPaymentTransactionStruct $transaction, RequestDataBag $dataBag, SalesChannelContext $salesChannelContext): void
    {
        $this->afterpayService->handlePayment($transaction, $dataBag, $salesChannelContext, self::class);
    }
}
