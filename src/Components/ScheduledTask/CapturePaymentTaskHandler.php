<?php declare(strict_types=1);

namespace Colo\AfterPay\Components\ScheduledTask;

use Colo\AfterPay\Service\LoggerService;
use Colo\AfterPay\Service\AfterpayService;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTask;
use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTaskHandler;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepositoryInterface;

class CapturePaymentTaskHandler extends ScheduledTaskHandler
{

    /**
     * @var AfterpayService
     */
    protected $afterpayService;

    /**
     * @var LoggerService
     */
    private $logger;

    /**
     * OrderSyncTaskHandler constructor.
     * @param EntityRepositoryInterface $scheduledTaskRepository
     * @param AfterpayService $afterpayService
     * @param LoggerService $logger
     */
    public function __construct(
        EntityRepositoryInterface $scheduledTaskRepository,
        AfterpayService $afterpayService,
        LoggerService $logger
    )
    {
        parent::__construct($scheduledTaskRepository);
        $this->afterpayService = $afterpayService;
        $this->logger = $logger;
    }

    /**
     * @return iterable
     */
    public static function getHandledMessages(): iterable
    {
        return [CapturePaymentTask::class];
    }

    /**
     * @throws \Shopware\Core\Framework\DataAbstractionLayer\Exception\InconsistentCriteriaIdsException
     * @throws \Symfony\Component\DependencyInjection\Exception\ServiceCircularReferenceException
     * @throws \Symfony\Component\DependencyInjection\Exception\ServiceNotFoundException
     */
    public function run(): void
    {
        $context = Context::createDefaultContext();
        $this->afterpayService->capturePayments($context);
    }

    /**
     * @param ScheduledTask $task
     */
    public function handle($task): void
    {
        try {
            parent::handle($task);
        } catch (\Throwable $ex) {
            $this->logger->log('error', $ex->getMessage() . ' [' . $ex->getFile() . ': ' . $ex->getLine() . ']');
            $this->logger->log('error', $ex->getTraceAsString());
        }
    }
}