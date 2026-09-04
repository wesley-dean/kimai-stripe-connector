<?php

/**
 * @file
 * @brief Integrates Payment Link generation with Kimai's supported pre-render invoice event.
 */

declare(strict_types=1);

namespace KimaiPlugin\KimaiStripeConnectorBundle\PaymentLink;

use App\Event\InvoicePreRenderEvent;
use KimaiPlugin\KimaiStripeConnectorBundle\Common\Configuration\ConfigurationException;
use KimaiPlugin\KimaiStripeConnectorBundle\Common\Configuration\EnvironmentConfigurationLoader;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * @brief Runs Payment Link creation/recovery before a real Kimai invoice is rendered.
 */
final readonly class InvoicePaymentLinkSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private EnvironmentConfigurationLoader $configurationLoader,
        private PaymentLinkServiceFactory $serviceFactory,
        private KimaiInvoiceMetadataFactory $metadataFactory,
        private PaymentLinkInvoiceModelHydrator $hydrator,
    ) {
    }

    /**
     * @return array<class-string, string>
     */
    public static function getSubscribedEvents(): array
    {
        return [InvoicePreRenderEvent::class => 'onInvoicePreRender'];
    }

    /**
     * @brief Attach a proven Payment Link URL to a real invoice or stop rendering safely.
     *
     * @throws PaymentLinkGenerationException When automatic payment-link generation is enabled but cannot complete safely.
     */
    public function onInvoicePreRender(InvoicePreRenderEvent $event): void
    {
        $model = $event->getModel();

        if ($model->isPreview()) {
            return;
        }

        try {
            $configuration = $this->configurationLoader->loadFromEnvironment();
        } catch (ConfigurationException $exception) {
            throw new PaymentLinkGenerationException(
                'Stripe Payment Link configuration is invalid: ' . $exception->getMessage(),
                previous: $exception,
            );
        }

        if (!$configuration->enabled() || !$configuration->paymentLinksEnabled()) {
            return;
        }

        $instanceId = $configuration->instanceId();
        $credential = $configuration->stripeCredential();
        if ($instanceId === null || $credential === null) {
            throw new PaymentLinkGenerationException(
                'Active Stripe Payment Link configuration is missing validated runtime prerequisites.',
            );
        }

        $metadata = $this->metadataFactory->create($model, $instanceId);
        $outcome = $this->serviceFactory->create($credential)->ensure(false, $metadata);
        $url = $outcome->url();

        if ($url === null) {
            throw new PaymentLinkGenerationException(
                sprintf(
                    'Automatic Stripe Payment Link generation stopped invoice rendering (%s): %s',
                    $outcome->result()->value,
                    $outcome->message(),
                ),
            );
        }

        $model->setOption(PaymentLinkInvoiceModelHydrator::OPTION_KEY, $url);
        $model->addModelHydrator($this->hydrator);
    }
}
