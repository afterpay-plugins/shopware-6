<?php declare(strict_types=1);

namespace Colo\AfterPay\Subscribers;

use Symfony\Contracts\Translation\TranslatorInterface;
use Colo\AfterPay\Service\Payments\InstallmentPayment;
use Shopware\Core\Checkout\Payment\PaymentMethodEntity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepositoryInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\System\SalesChannel\Context\SalesChannelContextService;
use Shopware\Core\System\SalesChannel\SalesChannel\SalesChannelContextSwitcher;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SalesChannel\SalesChannelEntity;
use Shopware\Storefront\Framework\Routing\Router;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpKernel\Event\ControllerArgumentsEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

class KernelSubscriber implements EventSubscriberInterface
{

    /**
     * @var ContainerInterface
     */
    private $container;

    /**
     * @var SalesChannelContextSwitcher
     */
    private $contextSwitcher;

    /**
     * @var EntityRepositoryInterface
     */
    private $salesChannelRepository;

    /**
     * @var TranslatorInterface
     */
    private $translator;

    /**
     * KernelSubscriber constructor.
     * @param ContainerInterface $container
     * @param SalesChannelContextSwitcher $contextSwitcher
     * @param EntityRepositoryInterface $salesChannelRepository
     * @param TranslatorInterface $translator
     */
    public function __construct(
        ContainerInterface $container,
        SalesChannelContextSwitcher $contextSwitcher,
        EntityRepositoryInterface $salesChannelRepository,
        TranslatorInterface $translator
    )
    {
        $this->container = $container;
        $this->contextSwitcher = $contextSwitcher;
        $this->salesChannelRepository = $salesChannelRepository;
        $this->translator = $translator;
    }

    /**
     * @return array
     */
    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::CONTROLLER_ARGUMENTS => ['onKernelEventsControllerArguments', 1],
            KernelEvents::RESPONSE => ['onKernelEventsResponse', 1],
        ];
    }

    /**
     * @param ControllerArgumentsEvent $event
     */
    public function onKernelEventsControllerArguments(ControllerArgumentsEvent $event)
    {
        $request = $event->getRequest();
        $attributes = $request->attributes;
        $route = $attributes->get('_route');
        if ($route == 'frontend.checkout.confirm.page') {
            /** @var Session $session */
            $session = $this->container->get('session');

            $arguments = $event->getArguments();
            /** @var SalesChannelContext $salesChannelContext */
            $salesChannelContext = $arguments[1];
            $customer = $salesChannelContext->getCustomer();
            if (empty($customer)) {
                if ($session->has('ColoAfterpayInstallmentInvalid')) {
                    $session->remove('ColoAfterpayInstallmentInvalid');
                }
                return;
            }
            $handlerIdentifier = $salesChannelContext->getPaymentMethod()->getHandlerIdentifier();
            if ($handlerIdentifier === InstallmentPayment::class) {
                if (empty($session->get('ColoAfterpayInstallmentPlan'))) {
                    $paymentMethod = $this->getFallbackPaymentMethod($salesChannelContext);
                    $data = new RequestDataBag([SalesChannelContextService::PAYMENT_METHOD_ID => $paymentMethod->getId()]);
                    $this->contextSwitcher->update($data, $salesChannelContext);

                    $session->set('ColoAfterpayInstallmentInvalid', true);
                }
            } else if ($session->has('ColoAfterpayInstallmentInvalid')) {
                $session->remove('ColoAfterpayInstallmentInvalid');
            }
        }
    }

    /**
     * @param ResponseEvent $event
     */
    public function onKernelEventsResponse(ResponseEvent $event)
    {
        $request = $event->getRequest();
        $attributes = $request->attributes;
        $route = $attributes->get('_route');
        if ($route == 'frontend.checkout.confirm.page') {
            /** @var Session $session */
            $session = $this->container->get('session');
            if ($session->has('ColoAfterpayInstallmentInvalid') && $session->get('ColoAfterpayInstallmentInvalid')) {
                $session->remove('ColoAfterpayInstallmentInvalid');

                $message = $this->translator->trans('afterpay.checkout.messages.paymentMethodChanged');
                $session->getFlashBag()->add('warning', $message);

                /** @var Router $router */
                $router = $this->container->get('router');
                $event->setResponse(new RedirectResponse($router->generate('frontend.checkout.confirm.page', [], Router::ABSOLUTE_URL), 302));
            }
        }
    }

    /**
     * @param SalesChannelContext $salesChannelContext
     * @return PaymentMethodEntity
     */
    private function getFallbackPaymentMethod(SalesChannelContext $salesChannelContext)
    {
        $context = $salesChannelContext->getContext();
        $criteria = new Criteria([$salesChannelContext->getSalesChannelId()]);
        $criteria->addAssociation('paymentMethod');
        $criteria->addAssociation('paymentMethod.media');

        /** @var SalesChannelEntity $salesChannel */
        $salesChannel = $this->salesChannelRepository->search($criteria, $context)->first();
        return $salesChannel->getPaymentMethod();
    }
}
