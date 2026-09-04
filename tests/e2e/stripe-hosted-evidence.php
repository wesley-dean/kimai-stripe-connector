<?php

/**
 * @file
 * @brief Verifies the real Stripe-hosted payment object chain for Phase 7b E2E.
 */

declare(strict_types=1);

use KimaiPlugin\KimaiStripeConnectorBundle\Common\Configuration\CredentialSource;
use KimaiPlugin\KimaiStripeConnectorBundle\Common\Configuration\StripeCredential;
use KimaiPlugin\KimaiStripeConnectorBundle\Common\Identity\KimaiInstanceId;
use KimaiPlugin\KimaiStripeConnectorBundle\Common\Logging\SecretRedactor;
use KimaiPlugin\KimaiStripeConnectorBundle\Common\Metadata\InvoiceMetadata;
use KimaiPlugin\KimaiStripeConnectorBundle\Common\Money\Money;
use KimaiPlugin\KimaiStripeConnectorBundle\Common\Stripe\StripeClientFactory;
use Stripe\StripeClient;

require '/opt/kimai/var/plugins/KimaiStripeConnectorBundle/vendor/autoload.php';

/**
 * @return non-empty-string
 */
$requiredEnvironment = static function (string $name): string {
    $value = getenv($name);
    if (!is_string($value) || trim($value) === '') {
        throw new RuntimeException('Required environment is incomplete: ' . $name . '.');
    }

    return $value;
};

/**
 * @param array<string, mixed> $actual
 * @param array<string, string> $expected
 */
$assertExactMetadata = static function (array $actual, array $expected, string $label): void {
    ksort($actual);
    ksort($expected);

    if ($actual !== $expected) {
        throw new RuntimeException(
            $label . ' metadata did not match the expected invoice identity.',
        );
    }
};

$objectId = static function (mixed $value): ?string {
    if (is_string($value) && $value !== '') {
        return $value;
    }

    if (is_array($value)) {
        $id = $value['id'] ?? null;
        if (is_string($id) && $id !== '') {
            return $id;
        }
    }

    return null;
};

/**
 * @return array<string, mixed>
 */
$exactPaymentLink = static function (
    StripeClient $client,
    InvoiceMetadata $expected,
) use ($assertExactMetadata): array {
    $matches = [];

    foreach ($client->paymentLinks->all(['limit' => 100])->autoPagingIterator() as $paymentLink) {
        $payload = $paymentLink->toArray();
        $metadata = $payload['metadata'] ?? null;
        if (!is_array($metadata)) {
            continue;
        }

        if (($metadata['integration'] ?? null) !== InvoiceMetadata::INTEGRATION) {
            continue;
        }

        if (($metadata['kimai_instance'] ?? null) !== $expected->instanceId()->value()) {
            continue;
        }

        if (($metadata['invoice_number'] ?? null) !== $expected->invoiceNumber()) {
            continue;
        }

        $assertExactMetadata($metadata, $expected->toStripeMetadata(), 'Payment Link');
        $matches[] = $payload;
    }

    if (count($matches) !== 1) {
        throw new RuntimeException(sprintf(
            'Expected exactly one connector-managed Payment Link for the disposable invoice, found %d.',
            count($matches),
        ));
    }

    return $matches[0];
};

/**
 * @param array<string, mixed> $link
 * @return array{id: string, url: string}
 */
$validatePaymentLink = static function (
    StripeClient $client,
    array $link,
    InvoiceMetadata $expected,
): array {
    $id = $link['id'] ?? null;
    $url = $link['url'] ?? null;
    if (
        !is_string($id)
        || $id === ''
        || !is_string($url)
        || !str_starts_with($url, 'https://')
    ) {
        throw new RuntimeException('Stripe Payment Link identity or URL is malformed.');
    }

    if (($link['active'] ?? null) !== true) {
        throw new RuntimeException('The disposable Payment Link is not active.');
    }

    if (($link['payment_method_types'] ?? null) !== ['card']) {
        throw new RuntimeException('The disposable Payment Link is not restricted to card payments.');
    }

    if (($link['restrictions']['completed_sessions']['limit'] ?? null) !== 1) {
        throw new RuntimeException('The disposable Payment Link does not enforce one completed session.');
    }

    $lineItems = $client->paymentLinks->allLineItems($id, ['limit' => 10]);
    if (count($lineItems->data) !== 1) {
        throw new RuntimeException('The disposable Payment Link does not contain exactly one line item.');
    }

    $lineItem = $lineItems->data[0]->toArray();
    $price = $lineItem['price'] ?? null;
    if (!is_array($price)) {
        throw new RuntimeException('Stripe returned an unreadable line-item price.');
    }

    if (($lineItem['quantity'] ?? null) !== 1) {
        throw new RuntimeException('The disposable Payment Link line-item quantity is not one.');
    }

    if (
        ($price['unit_amount'] ?? null) !== $expected->money()->minorUnits()
        || ($price['currency'] ?? null) !== $expected->money()->currency()->value()
    ) {
        throw new RuntimeException('The disposable Payment Link amount or currency is incorrect.');
    }

    return ['id' => $id, 'url' => $url];
};

/**
 * @return array{session: string, payment_intent: string, charge: string, charge_created: int}|null
 */
$successfulPaymentEvidence = static function (
    StripeClient $client,
    string $paymentLinkId,
    InvoiceMetadata $expected,
) use ($assertExactMetadata, $objectId): ?array {
    $successful = [];

    foreach (
        $client->checkout->sessions->all([
            'payment_link' => $paymentLinkId,
            'limit' => 100,
        ])->autoPagingIterator() as $session
    ) {
        $sessionPayload = $session->toArray();
        $sessionMetadata = $sessionPayload['metadata'] ?? null;
        if (!is_array($sessionMetadata)) {
            throw new RuntimeException('Checkout Session metadata is missing or malformed.');
        }
        $assertExactMetadata(
            $sessionMetadata,
            $expected->toStripeMetadata(),
            'Checkout Session',
        );

        if (
            ($sessionPayload['amount_total'] ?? null) !== $expected->money()->minorUnits()
            || ($sessionPayload['currency'] ?? null) !== $expected->money()->currency()->value()
            || $objectId($sessionPayload['payment_link'] ?? null) !== $paymentLinkId
        ) {
            throw new RuntimeException(
                'Checkout Session financial or relationship evidence conflicts with the invoice.',
            );
        }

        if (
            ($sessionPayload['status'] ?? null) !== 'complete'
            || ($sessionPayload['payment_status'] ?? null) !== 'paid'
        ) {
            continue;
        }

        $paymentIntentId = $objectId($sessionPayload['payment_intent'] ?? null);
        if ($paymentIntentId === null) {
            throw new RuntimeException('Paid Checkout Session has no usable PaymentIntent ID.');
        }

        $intentPayload = $client->paymentIntents->retrieve($paymentIntentId)->toArray();
        $intentMetadata = $intentPayload['metadata'] ?? null;
        if (!is_array($intentMetadata)) {
            throw new RuntimeException('PaymentIntent metadata is missing or malformed.');
        }
        $assertExactMetadata(
            $intentMetadata,
            $expected->toStripeMetadata(),
            'PaymentIntent',
        );

        if (
            ($intentPayload['status'] ?? null) !== 'succeeded'
            || ($intentPayload['amount'] ?? null) !== $expected->money()->minorUnits()
            || ($intentPayload['amount_received'] ?? null) !== $expected->money()->minorUnits()
            || ($intentPayload['currency'] ?? null) !== $expected->money()->currency()->value()
        ) {
            throw new RuntimeException(
                'PaymentIntent does not prove the exact successful invoice payment.',
            );
        }

        $chargeId = $objectId($intentPayload['latest_charge'] ?? null);
        if ($chargeId === null) {
            throw new RuntimeException('Successful PaymentIntent has no usable latest Charge ID.');
        }

        $chargePayload = $client->charges->retrieve($chargeId)->toArray();
        if (
            ($chargePayload['paid'] ?? null) !== true
            || ($chargePayload['captured'] ?? null) !== true
            || ($chargePayload['refunded'] ?? null) !== false
            || ($chargePayload['disputed'] ?? null) !== false
            || ($chargePayload['amount'] ?? null) !== $expected->money()->minorUnits()
            || ($chargePayload['amount_captured'] ?? null) !== $expected->money()->minorUnits()
            || ($chargePayload['amount_refunded'] ?? null) !== 0
            || ($chargePayload['currency'] ?? null) !== $expected->money()->currency()->value()
            || $objectId($chargePayload['payment_intent'] ?? null) !== $paymentIntentId
        ) {
            throw new RuntimeException(
                'Charge does not prove the exact captured, non-refunded, '
                . 'undisputed invoice payment.',
            );
        }

        $chargeCreated = $chargePayload['created'] ?? null;
        if (!is_int($chargeCreated)) {
            throw new RuntimeException('Charge creation timestamp is missing or malformed.');
        }

        $sessionId = $sessionPayload['id'] ?? null;
        if (!is_string($sessionId) || $sessionId === '') {
            throw new RuntimeException('Paid Checkout Session has no usable ID.');
        }

        $successful[] = [
            'session' => $sessionId,
            'payment_intent' => $paymentIntentId,
            'charge' => $chargeId,
            'charge_created' => $chargeCreated,
        ];
    }

    if (count($successful) > 1) {
        throw new RuntimeException('Multiple successful Stripe payments match the disposable invoice.');
    }

    return $successful[0] ?? null;
};

/**
 * @param array<int, string> $argv
 * @return array{0: InvoiceMetadata, 1: string}
 */
$expectedInvoice = static function (array $argv) use ($requiredEnvironment): array {
    $invoiceNumber = $argv[2] ?? null;
    $minorUnits = $argv[3] ?? null;
    $currency = $argv[4] ?? null;

    if (!is_string($invoiceNumber) || trim($invoiceNumber) === '') {
        throw new RuntimeException('Invoice number argument is required.');
    }
    if (!is_string($minorUnits) || preg_match('/^[1-9][0-9]*$/', $minorUnits) !== 1) {
        throw new RuntimeException('Positive minor-unit amount argument is required.');
    }
    if (!is_string($currency) || trim($currency) === '') {
        throw new RuntimeException('Currency argument is required.');
    }

    $instanceId = new KimaiInstanceId(
        $requiredEnvironment('KIMAI_STRIPE_CONNECTOR_INSTANCE_ID'),
    );

    return [
        new InvoiceMetadata(
            $instanceId,
            $invoiceNumber,
            Money::fromMinorUnits((int) $minorUnits, $currency),
        ),
        $invoiceNumber,
    ];
};

$secret = $requiredEnvironment('KIMAI_STRIPE_CONNECTOR_STRIPE_API_KEY');
$redactor = new SecretRedactor();

try {
    $credential = new StripeCredential($secret, CredentialSource::DIRECT);
    if ($credential->isLive()) {
        throw new RuntimeException('Hosted-payment E2E refuses to use a live-mode Stripe credential.');
    }

    $client = (new StripeClientFactory())->create($credential);
    $mode = $argv[1] ?? '';

    if ($mode === 'cleanup') {
        $instanceId = new KimaiInstanceId(
            $requiredEnvironment('KIMAI_STRIPE_CONNECTOR_INSTANCE_ID'),
        );
        $count = 0;

        foreach ($client->paymentLinks->all(['limit' => 100])->autoPagingIterator() as $paymentLink) {
            $payload = $paymentLink->toArray();
            $metadata = $payload['metadata'] ?? null;
            if (!is_array($metadata)) {
                continue;
            }
            if (($metadata['integration'] ?? null) !== InvoiceMetadata::INTEGRATION) {
                continue;
            }
            if (($metadata['kimai_instance'] ?? null) !== $instanceId->value()) {
                continue;
            }
            if (($payload['active'] ?? null) !== true || !is_string($payload['id'] ?? null)) {
                continue;
            }

            $updated = $client->paymentLinks
                ->update($payload['id'], ['active' => false])
                ->toArray();
            if (($updated['active'] ?? null) !== false) {
                throw new RuntimeException(
                    'Stripe did not confirm Payment Link deactivation during E2E cleanup.',
                );
            }
            $count++;
        }

        fwrite(STDOUT, sprintf(
            "Deactivated %d run-owned Stripe Payment Link(s).\n",
            $count,
        ));
        exit(0);
    }

    [$expected] = $expectedInvoice($argv);
    $link = $validatePaymentLink(
        $client,
        $exactPaymentLink($client, $expected),
        $expected,
    );

    if ($mode === 'locate') {
        fwrite(STDOUT, json_encode($link, JSON_THROW_ON_ERROR) . "\n");
        exit(0);
    }

    if ($mode !== 'wait') {
        throw new RuntimeException('Unknown hosted-payment evidence mode.');
    }

    $waitValue = getenv('STRIPE_HOSTED_WAIT_SECONDS');
    $waitSeconds = is_string($waitValue) && preg_match('/^[1-9][0-9]*$/', $waitValue) === 1
        ? (int) $waitValue
        : 900;
    $deadline = time() + $waitSeconds;
    $nextProgress = time();

    fwrite(STDOUT, sprintf(
        "Waiting up to %d seconds for hosted Stripe Checkout completion.\n",
        $waitSeconds,
    ));

    do {
        $evidence = $successfulPaymentEvidence($client, $link['id'], $expected);
        if ($evidence !== null) {
            fwrite(STDOUT, json_encode($evidence, JSON_THROW_ON_ERROR) . "\n");
            exit(0);
        }

        if (time() >= $nextProgress) {
            fwrite(STDOUT, "No complete paid Checkout Session yet; continuing to wait.\n");
            $nextProgress = time() + 30;
        }

        sleep(5);
    } while (time() < $deadline);

    throw new RuntimeException('Timed out waiting for the hosted Stripe test-card payment.');
} catch (Throwable $exception) {
    $message = $redactor->redact($exception->getMessage(), [$secret]);
    fwrite(STDERR, 'Hosted-payment evidence check failed: ' . $message . "\n");
    exit(1);
}
