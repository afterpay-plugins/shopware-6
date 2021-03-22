<?php declare(strict_types=1);

namespace Colo\AfterPay;

use Colo\AfterPay\Service\InvoicePayment;
use Shopware\Core\Checkout\Order\OrderDefinition;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepositoryInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\ContainsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\IdSearchResult;
use Shopware\Core\Framework\Plugin;
use Shopware\Core\Framework\Plugin\Context\ActivateContext;
use Shopware\Core\Framework\Plugin\Context\DeactivateContext;
use Shopware\Core\Framework\Plugin\Context\InstallContext;
use Shopware\Core\Framework\Plugin\Context\UninstallContext;
use Shopware\Core\Framework\Plugin\Util\PluginIdProvider;
use Shopware\Core\System\Country\CountryDefinition;
use Shopware\Core\System\CustomField\CustomFieldTypes;
use Shopware\Core\Framework\DataAbstractionLayer\Exception\InconsistentCriteriaIdsException;

class AfterPay extends Plugin
{

    const CUSTOM_FIELD_SET_PREFIX = 'afterpay_';
    const CUSTOM_FIELD_NAME_AFTERPAY_TRANSACTION_ID = 'afterpay_orders_transaction_id';
    const CUSTOM_FIELD_NAME_AFTERPAY_API_KEY = 'afterpay_countries_api_key';

    /**
     * @param InstallContext $context
     * @throws InconsistentCriteriaIdsException
     */
    public function install(InstallContext $context): void
    {
        $this->addPaymentMethod($context->getContext());
        $this->addCustomFields($context->getContext());
    }

    /**
     * @param UninstallContext $context
     * @throws InconsistentCriteriaIdsException
     */
    public function uninstall(UninstallContext $context): void
    {
        // Only set the payment method to inactive when uninstalling. Removing the payment method would
        // cause data consistency issues, since the payment method might have been used in several orders
        $this->setPaymentMethodIsActive(false, $context->getContext());
        if (!$context->keepUserData()) {
            $this->removeCustomFields($context->getContext());
        }
    }

    /**
     * @param ActivateContext $context
     * @throws InconsistentCriteriaIdsException
     */
    public function activate(ActivateContext $context): void
    {
        $this->setPaymentMethodIsActive(true, $context->getContext());
        parent::activate($context);
    }

    /**
     * @param DeactivateContext $context
     * @throws InconsistentCriteriaIdsException
     */
    public function deactivate(DeactivateContext $context): void
    {
        $this->setPaymentMethodIsActive(false, $context->getContext());
        parent::deactivate($context);
    }

    /**
     * @param Context $context
     * @throws InconsistentCriteriaIdsException
     */
    private function addPaymentMethod(Context $context): void
    {
        $paymentMethodExists = $this->getPaymentMethodId($context);

        // Payment method exists already, no need to continue here
        if ($paymentMethodExists) {
            return;
        }

        /** @var PluginIdProvider $pluginIdProvider */
        $pluginIdProvider = $this->container->get(PluginIdProvider::class);
        $pluginId = $pluginIdProvider->getPluginIdByBaseClass(get_class($this), $context);

        $invoicePaymentData = [
            // payment handler will be selected by the identifier
            'handlerIdentifier' => InvoicePayment::class,
            'name' => 'Rechnung',
            'description' => 'Erst erleben, dann flexibel bezahlen',
            'pluginId' => $pluginId,
        ];

        /** @var EntityRepositoryInterface $paymentRepository */
        $paymentRepository = $this->container->get('payment_method.repository');
        $paymentRepository->create([$invoicePaymentData], $context);
    }

    /**
     * @param Context $context
     * @throws InconsistentCriteriaIdsException
     */
    private function addCustomFields(Context $context)
    {
        /** @var EntityRepositoryInterface $customFieldSetRepository */
        $customFieldSetRepository = $this->container->get('custom_field_set.repository');

        $customFieldSetRepository->upsert([[
            'name' => self::CUSTOM_FIELD_SET_PREFIX . 'orders',
            'active' => true,
            'config' => [
                'label' => [
                    'en-GB' => 'AfterPay Orders',
                    'de-DE' => 'AfterPay Orders',
                ],
            ],
            'customFields' => [
                [
                    'name' => self::CUSTOM_FIELD_NAME_AFTERPAY_TRANSACTION_ID,
                    'type' => CustomFieldTypes::TEXT,
                    'config' => [
                        'componentName' => 'sw-field',
                        'type' => CustomFieldTypes::TEXT,
                        'customFieldType' => CustomFieldTypes::TEXT,
                        'label' => [
                            'en-GB' => 'Transaction ID',
                            'de-DE' => 'Transaction ID',
                        ],
                        'customFieldPosition' => 0
                    ]
                ],
            ],
            'relations' => [
                [
                    'entityName' => OrderDefinition::ENTITY_NAME,
                ],
            ]
        ]], $context);

        $customFieldSetRepository->upsert([[
            'name' => self::CUSTOM_FIELD_SET_PREFIX . 'countries',
            'active' => true,
            'config' => [
                'label' => [
                    'en-GB' => 'AfterPay Countries',
                    'de-DE' => 'AfterPay Countries',
                ],
            ],
            'customFields' => [
                [
                    'name' => self::CUSTOM_FIELD_NAME_AFTERPAY_API_KEY,
                    'type' => CustomFieldTypes::TEXT,
                    'config' => [
                        'componentName' => 'sw-field',
                        'type' => CustomFieldTypes::TEXT,
                        'customFieldType' => CustomFieldTypes::TEXT,
                        'label' => [
                            'en-GB' => 'API key',
                            'de-DE' => 'API key',
                        ],
                        'customFieldPosition' => 0
                    ]
                ],
            ],
            'relations' => [
                [
                    'entityName' => CountryDefinition::ENTITY_NAME,
                ],
            ]
        ]], $context);
    }

    /**
     * @param Context $context
     */
    private function removeCustomFields(Context $context)
    {
        $customFieldIds = $this->getCustomFieldSetIds($context);

        if ($customFieldIds->getTotal() === 0) {
            return;
        }

        $ids = array_map(static function ($id) {
            return ['id' => $id];
        }, $customFieldIds->getIds());

        $this->container->get('custom_field_set.repository')->delete($ids, $context);
    }

    /**
     * @param bool $active
     * @param Context $context
     * @throws InconsistentCriteriaIdsException
     */
    private function setPaymentMethodIsActive(bool $active, Context $context): void
    {
        /** @var EntityRepositoryInterface $paymentRepository */
        $paymentRepository = $this->container->get('payment_method.repository');

        $paymentMethodId = $this->getPaymentMethodId($context);

        // Payment does not even exist, so nothing to (de-)activate here
        if (!$paymentMethodId) {
            return;
        }

        $paymentMethod = [
            'id' => $paymentMethodId,
            'active' => $active,
        ];

        $paymentRepository->update([$paymentMethod], $context);
    }

    /**
     * @param Context $context
     * @return string|null
     * @throws InconsistentCriteriaIdsException
     */
    private function getPaymentMethodId(Context $context): ?string
    {
        /** @var EntityRepositoryInterface $paymentRepository */
        $paymentRepository = $this->container->get('payment_method.repository');

        // Fetch ID for update
        $paymentCriteria = (new Criteria())->addFilter(new EqualsFilter('handlerIdentifier', InvoicePayment::class));
        $paymentIds = $paymentRepository->searchIds($paymentCriteria, $context);

        if ($paymentIds->getTotal() === 0) {
            return null;
        }

        return $paymentIds->getIds()[0];
    }

    /**
     * @param Context $context
     * @return IdSearchResult
     */
    private function getCustomFieldSetIds(Context $context): IdSearchResult
    {
        $criteria = new Criteria();
        $criteria->addFilter(new ContainsFilter('name', self::CUSTOM_FIELD_SET_PREFIX));

        return $this->container->get('custom_field_set.repository')->searchIds($criteria, $context);
    }
}
