<?php declare(strict_types=1);

namespace Colo\AfterPay;

use Colo\AfterPay\Service\Payments\InvoicePayment;
use Colo\AfterPay\Service\Payments\DirectDebitPayment;
use Colo\AfterPay\Service\Payments\InstallmentPayment;
use Colo\AfterPay\Manager\MediaManager;
use Colo\AfterPay\Manager\MediaFolderManager;
use Shopware\Core\Checkout\Order\OrderDefinition;
use Shopware\Core\Checkout\Payment\PaymentMethodEntity;
use Shopware\Core\Content\Media\Aggregate\MediaFolder\MediaFolderEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepositoryInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\ContainsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsAnyFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Plugin;
use Shopware\Core\Framework\Plugin\Context\ActivateContext;
use Shopware\Core\Framework\Plugin\Context\DeactivateContext;
use Shopware\Core\Framework\Plugin\Context\InstallContext;
use Shopware\Core\Framework\Plugin\Context\UpdateContext;
use Shopware\Core\Framework\Plugin\Context\UninstallContext;
use Shopware\Core\Framework\Plugin\Util\PluginIdProvider;
use Shopware\Core\System\CustomField\Aggregate\CustomFieldSet\CustomFieldSetEntity;
use Shopware\Core\System\CustomField\CustomFieldTypes;
use Shopware\Core\Framework\DataAbstractionLayer\Exception\InconsistentCriteriaIdsException;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionDefinition;

class AfterPay extends Plugin
{

    const CUSTOM_FIELD_SET_PREFIX = 'afterpay_';
    const CUSTOM_FIELD_NAME_AFTERPAY_TRANSACTION_ID = self::CUSTOM_FIELD_SET_PREFIX . 'orders_transaction_id';
    const CUSTOM_FIELD_NAME_AFTERPAY_TRANSACTION_MODE = self::CUSTOM_FIELD_SET_PREFIX . 'orders_transaction_mode';
    const CUSTOM_FIELD_NAME_AFTERPAY_CAPTURED = self::CUSTOM_FIELD_SET_PREFIX . 'orders_captured';
    const CUSTOM_FIELD_NAME_AFTERPAY_CAPTURE_NUMBER = self::CUSTOM_FIELD_SET_PREFIX . 'orders_capture_number';

    const CUSTOM_FIELD_SETS = [
        [
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
                ]
            ],
            'relations' => [
                [
                    'entityName' => OrderDefinition::ENTITY_NAME,
                ],
            ]
        ]
    ];

    const PAYMENT_METHODS = [
        [
            'handlerIdentifier' => InvoicePayment::class,
            'name' => [
                'de-DE' => 'Rechnung',
                'en-GB' => 'Pay after delivery',
                'nl-BE' => 'Veilig achteraf betalen',
                'nl-NL' => 'Veilig achteraf betalen'
            ],
            'description' => [
                'de-DE' => 'Erst erleben, dann flexibel bezahlen',
                'en-GB' => 'Experience first, pay later',
                'nl-BE' => 'Eerst ervaren, betaal later',
                'nl-NL' => 'Eerst ervaren, betaal later'
            ],
        ],
        [
            'handlerIdentifier' => InstallmentPayment::class,
            'name' => [
                'de-DE' => 'Ratenzahlung',
                'en-GB' => 'Installment'
            ],
            'description' => [
                'de-DE' => 'Zahle in Raten',
                'en-GB' => 'Pay in installments',
            ],
        ],
        [
            'handlerIdentifier' => DirectDebitPayment::class,
            'name' => [
                'de-DE' => 'Lastschrift',
                'en-GB' => 'Direct Debit',
                'nl-BE' => 'Veilig achteraf betalen (eenmalige machtiging)',
                'nl-NL' => 'Veilig achteraf betalen (eenmalige machtiging)'
            ],
            'description' => [
                'de-DE' => 'Zahle bequem per Lastschrifteinzug',
                'en-GB' => 'Pay conveniently by direct debit',
                'nl-BE' => 'Betaal gemakkelijk via automatische incasso',
                'nl-NL' => 'Betaal gemakkelijk via automatische incasso'
            ],
        ]
    ];

    const STATE_MACHINE_STATES = [
        OrderTransactionDefinition::ENTITY_NAME => [
            [
                'name' => [
                    'en-GB' => 'Captured',
                    'de-DE' => 'Captured',
                ],
                'technicalName' => 'afterpay_captured',
//                'fromStateMachineTransitions' => [OrderTransactionStates::STATE_OPEN, OrderTransactionStates::STATE_PAID],
//                'toStateMachineTransitions' => [OrderTransactionStates::STATE_CANCELLED, OrderTransactionStates::STATE_OPEN]
            ]
        ]
    ];

    /**
     * @param InstallContext $context
     * @throws InconsistentCriteriaIdsException
     */
    public function install(InstallContext $context): void
    {
        $this->addPaymentMethods($context->getContext());
        $this->addNewStates($context->getContext());
        $this->addCustomFields($context->getContext());
    }

    /**
     * @param UpdateContext $context
     * @throws InconsistentCriteriaIdsException
     */
    public function update(UpdateContext $context): void
    {
        $version = $context->getCurrentPluginVersion();
        if (version_compare($version, '1.0.5', '<=')) {
            $this->addPaymentMethods($context->getContext());
        }
        if (version_compare($version, '1.0.6', '<=')) {
            $this->addNewStates($context->getContext());
        }
        if (version_compare($version, '1.0.7', '<=')) {
            $this->addPaymentMethods($context->getContext());
        }
    }

    /**
     * @param UninstallContext $context
     * @throws InconsistentCriteriaIdsException
     */
    public function uninstall(UninstallContext $context): void
    {
        // Only set the payment method to inactive when uninstalling. Removing the payment method would
        // cause data consistency issues, since the payment method might have been used in several orders
        $this->setPaymentMethodsIsActive(false, $context->getContext());
        if (!$context->keepUserData()) {
            $this->removeCustomFields($context->getContext());

            $mediaFolderManager = new MediaFolderManager($this->container);
            $mediaFolderManager->removeAlbumFolders($context->getContext());
        }
    }

    /**
     * @param ActivateContext $context
     * @throws InconsistentCriteriaIdsException
     */
    public function activate(ActivateContext $context): void
    {
        $this->setPaymentMethodsIsActive(true, $context->getContext());
        parent::activate($context);
    }

    /**
     * @param DeactivateContext $context
     * @throws InconsistentCriteriaIdsException
     */
    public function deactivate(DeactivateContext $context): void
    {
        $this->setPaymentMethodsIsActive(false, $context->getContext());
        parent::deactivate($context);
    }

    /**
     * @param Context $context
     * @throws InconsistentCriteriaIdsException
     */
    private function addPaymentMethods(Context $context): void
    {
        $mediaFolderManager = new MediaFolderManager($this->container);
        $mediaFolder = $mediaFolderManager->createMediaFolder($context, MediaFolderManager::MAIN_FOLDER_NAME);

        /** @var EntityRepositoryInterface $paymentRepository */
        $paymentRepository = $this->container->get('payment_method.repository');

        /** @var PluginIdProvider $pluginIdProvider */
        $pluginIdProvider = $this->container->get(PluginIdProvider::class);
        $pluginId = $pluginIdProvider->getPluginIdByBaseClass(get_class($this), $context);
        foreach (self::PAYMENT_METHODS as $paymentMethodData) {
            $paymentMethod = $this->getPaymentMethod($context, $paymentMethodData['handlerIdentifier']);

            if ($paymentMethod) {
                if (empty($paymentMethod->getMedia())) {
                    $this->assignLogoToPayment($context, $paymentMethod, $mediaFolder, 'afterpay_logo.svg');
                }

                $paymentMethodData['id'] = $paymentMethod->getId();
                $paymentRepository->update([$paymentMethodData], $context);

                continue;
            }

            $paymentMethodData['pluginId'] = $pluginId;
            $paymentRepository->create([$paymentMethodData], $context);

            $paymentMethod = $this->getPaymentMethod($context, $paymentMethodData['handlerIdentifier']);
            $this->assignLogoToPayment($context, $paymentMethod, $mediaFolder, 'afterpay_logo.svg');
        }
    }

    /**
     * @param Context $context
     */
    private function addNewStates(Context $context)
    {
        /** @var EntityRepositoryInterface $stateMachineRepository */
        $stateMachineRepository = $this->container->get('state_machine.repository');

        /** @var EntityRepositoryInterface $stateMachineStateRepository */
        $stateMachineStateRepository = $this->container->get('state_machine_state.repository');

        foreach (self::STATE_MACHINE_STATES as $stateMachineTechnicalName => $stateMachineStates) {
            $stateMachine = $stateMachineRepository->search((new Criteria())->addFilter(new EqualsFilter('technicalName', $stateMachineTechnicalName . '.state')), $context)->first();
            if (empty($stateMachine)) {
                continue;
            }

            foreach ($stateMachineStates as &$stateMachineState) {
                $stateMachineState['id'] = md5($stateMachineTechnicalName . $stateMachineState['technicalName']);
                $stateMachineState['stateMachineId'] = $stateMachine->getId();

//                if (!empty($stateMachineState['fromStateMachineTransitions'])) {
//                    $stateMachineState['fromStateMachineTransitions'] = $this->getStateMachineTransitions($stateMachineState['fromStateMachineTransitions'], $stateMachineTechnicalName, $context);
//                }
//
//                if (!empty($stateMachineState['toStateMachineTransitions'])) {
//                    $stateMachineState['toStateMachineTransitions'] = $this->getStateMachineTransitions($stateMachineState['toStateMachineTransitions'], $stateMachineTechnicalName, $context);
//                }
            }
            $stateMachineStateRepository->upsert($stateMachineStates, $context);
        }
    }

    /**
     * @param Context $context
     * @throws InconsistentCriteriaIdsException
     */
    private function addCustomFields(Context $context)
    {
        /** @var EntityRepositoryInterface $customFieldSetRepository */
        $customFieldSetRepository = $this->container->get('custom_field_set.repository');

        /** @var EntityRepositoryInterface $customFieldSetRepository */
        $customFieldRepository = $this->container->get('custom_field.repository');

        $customFields = [];
        foreach (self::CUSTOM_FIELD_SETS as $customFieldSet) {
            $results = $customFieldSetRepository->search((new Criteria())->addFilter(new EqualsFilter('name', $customFieldSet['name'])), $context);
            if ($results->getTotal() === 0) {
                if (empty($customFieldSet['id'])) {
                    $customFieldSet['id'] = md5($customFieldSet['name']);
                }
                $customFieldSetRepository->upsert([$customFieldSet], $context);
            } else {
                $customFieldSetEntity = $results->first();
                foreach ($customFieldSet['customFields'] as $customField) {
                    $results = $customFieldRepository->search((new Criteria())->addFilter(new EqualsFilter('name', $customField['name'])), $context);
                    if ($results->getTotal() === 0) {
                        if (empty($customField['id'])) {
                            $customField['id'] = md5($customField['name']);
                        }
                        $customField['customFieldSetId'] = $customFieldSetEntity->getId();
                        $customFields[] = $customField;
                    }
                }
            }
        }

        if (!empty($customFields)) {
            $customFieldRepository->upsert($customFields, $context);
        }
    }

    /**
     * @param Context $context
     */
    private function removeCustomFields(Context $context)
    {
        $customFieldSets = $this->getCustomFieldSets($context);
        if (empty($customFieldSets)) {
            return;
        }

        $customFieldSetIds = [];
        $customFieldIds = [];
        foreach ($customFieldSets as $customFieldSet) {
            $customFieldSetIds[] = ['id' => $customFieldSet->getId()];

            foreach ($customFieldSet->getCustomFields() as $customField) {
                $customFieldIds[] = ['id' => $customField->getId()];
            }
        }

        if (!empty($customFieldIds)) {
            /** @var EntityRepositoryInterface $customFieldRepository */
            $customFieldRepository = $this->container->get('custom_field.repository');

            $customFieldRepository->delete($customFieldIds, $context);
        }

        if (!empty($customFieldSetIds)) {
            /** @var EntityRepositoryInterface $customFieldSetRepository */
            $customFieldSetRepository = $this->container->get('custom_field_set.repository');

            $customFieldSetRepository->delete($customFieldSetIds, $context);
        }
    }

    /**
     * @param bool $active
     * @param Context $context
     * @throws InconsistentCriteriaIdsException
     */
    private function setPaymentMethodsIsActive(bool $active, Context $context): void
    {
        /** @var EntityRepositoryInterface $paymentRepository */
        $paymentRepository = $this->container->get('payment_method.repository');

        foreach (self::PAYMENT_METHODS as $paymentMethodData) {
            $paymentMethod = $this->getPaymentMethod($context, $paymentMethodData['handlerIdentifier']);

            // Payment does not even exist, so nothing to (de-)activate here
            if (!$paymentMethod) {
                continue;
            }

            $paymentRepository->update([[
                'id' => $paymentMethod->getId(),
                'active' => $active,
            ]], $context);
        }
    }

    /**
     * @param Context $context
     * @param string $handlerIdentifier
     * @return PaymentMethodEntity|null
     * @throws InconsistentCriteriaIdsException
     */
    private function getPaymentMethod(Context $context, string $handlerIdentifier): ?PaymentMethodEntity
    {
        /** @var EntityRepositoryInterface $paymentRepository */
        $paymentRepository = $this->container->get('payment_method.repository');

        // Fetch ID for update
        $paymentCriteria = (new Criteria())->addFilter(new EqualsFilter('handlerIdentifier', $handlerIdentifier));
        $payments = $paymentRepository->search($paymentCriteria, $context);

        if ($payments->getTotal() === 0 || empty($payments->first())) {
            return null;
        }

        return $payments->first();
    }

    /**
     * @param Context $context
     * @return CustomFieldSetEntity[]
     */
    private function getCustomFieldSets(Context $context): array
    {
        /** @var EntityRepositoryInterface $customFieldSetRepository */
        $customFieldSetRepository = $this->container->get('custom_field_set.repository');

        $criteria = new Criteria();
        $criteria->addAssociation('customFields');
        $criteria->addFilter(new ContainsFilter('name', self::CUSTOM_FIELD_SET_PREFIX));

        $results = $customFieldSetRepository->search($criteria, $context);
        return $results->getElements();
    }

    /**
     * @param array $stateMachineStateTechnicalNames
     * @param string $stateMachineTechnicalName
     * @param Context $context
     * @return array|array[]|string[]
     */
    private function getStateMachineTransitions(array $stateMachineStateTechnicalNames, string $stateMachineTechnicalName, Context $context)
    {
        $ids = [];
        /** @var EntityRepositoryInterface $stateMachineStateRepository */
        $stateMachineStateRepository = $this->container->get('state_machine_state.repository');

        $criteria = new Criteria();
        $criteria->addAssociation('stateMachine');
        $criteria->addFilter(new EqualsAnyFilter('technicalName', $stateMachineStateTechnicalNames))
            ->addFilter(new EqualsFilter('stateMachine.technicalName', $stateMachineTechnicalName . '.state'));
        $results = $stateMachineStateRepository->search($criteria, $context);
        if ($results->getTotal() > 0) {
            $ids = $results->getElements();
        }
        return $ids;
    }

    /**
     * @param Context $context
     * @param PaymentMethodEntity $paymentMethod
     * @param MediaFolderEntity $mediaFolder
     * @param string $logoName
     */
    private function assignLogoToPayment(Context $context, PaymentMethodEntity $paymentMethod, MediaFolderEntity $mediaFolder, string $logoName)
    {
        $filePath = __DIR__ . '/Resources/public/storefront/assets/img/' . $logoName;

        $mediaRepository = $this->container->get('media.repository');
        $fileSaver = $this->container->get('Shopware\Core\Content\Media\File\FileSaver');
        $mediaManager = new MediaManager($this->container, $mediaRepository, $fileSaver);
        $media = $mediaManager->createMedia($context, $mediaFolder, $filePath);

        $mediaData = [
            'id' => $paymentMethod->getId(),
            'mediaId' => $media->getId(),
        ];

        /** @var EntityRepositoryInterface $paymentRepository */
        $paymentRepository = $this->container->get('payment_method.repository');
        $paymentRepository->update([$mediaData], $context);
    }
}
