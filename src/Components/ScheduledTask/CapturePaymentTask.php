<?php declare(strict_types=1);

namespace Colo\AfterPay\Components\ScheduledTask;

use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTask;

class CapturePaymentTask extends ScheduledTask
{
    /**
     * @return string
     */
    public static function getTaskName(): string
    {
        return 'colo_afterpay.capture_payment_task';
    }

    /**
     * @return int
     */
    public static function getDefaultInterval(): int
    {
        return 300;
    }
}
