<?php declare(strict_types=1);

namespace Colo\AfterPay\Checkout;

use Shopware\Core\Framework\Struct\Struct;

class AfterPayData extends Struct
{
    /**
     * @var bool
     */
    protected $afterPayActive;

    /**
     * @var string
     */
    protected $merchantId;

    /**
     * @var string
     */
    protected $languageCode;

    /**
     * @var bool
     */
    protected $showTosCheckbox = false;

    /**
     * @var bool
     */
    protected $tosCheckboxRequired = false;

    /**
     * @var string
     */
    protected $merchantPaymentMethod;

    /**
     * @var string
     */
    protected $paymentMethodName;

    /**
     * @var string
     */
    protected $paymentMethodId;

    /**
     * @return bool
     */
    public function isAfterPayActive(): bool
    {
        return $this->afterPayActive;
    }

    /**
     * @return string
     */
    public function getMerchantId(): string
    {
        return $this->merchantId;
    }

    /**
     * @return string
     */
    public function getLanguageCode(): string
    {
        return $this->languageCode;
    }

    /**
     * @return bool
     */
    public function showTosCheckbox(): bool
    {
        return $this->showTosCheckbox;
    }

    /**
     * @return bool
     */
    public function isTosCheckboxRequired(): bool
    {
        return $this->tosCheckboxRequired;
    }

    /**
     * @return string
     */
    public function getMerchantPaymentMethod(): string
    {
        return $this->merchantPaymentMethod;
    }

    /**
     * @return string
     */
    public function getPaymentMethodName(): string
    {
        return $this->paymentMethodName;
    }

    /**
     * @return string
     */
    public function getPaymentMethodId(): string
    {
        return $this->paymentMethodId;
    }
}
