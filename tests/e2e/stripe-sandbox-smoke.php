<?php

/**
 * @file
 * @brief Probes the real Stripe sandbox contract with a restricted test credential.
 */

declare(strict_types=1);

use KimaiPlugin\KimaiStripeConnectorBundle\Common\Configuration\CredentialSource;
use KimaiPlugin\KimaiStripeConnectorBundle\Common\Configuration\StripeCredential;
use KimaiPlugin\KimaiStripeConnectorBundle\Common\Identity\KimaiInstanceId;
use KimaiPlugin\KimaiStripeConnectorBundle\Common\Logging\SecretRedactor;
use KimaiPlugin\KimaiStripeConnectorBundle\Common\Metadata\InvoiceMetadata;
use KimaiPlugin\KimaiStripeConnectorBundle\Common\Money\Money;
use KimaiPlugin\KimaiStripeConnectorBundle\Common\Stripe\StripeClientFactory;
use KimaiPlugin\KimaiStripeConnectorBundle\PaymentLink\PaymentLinkCreateRequest;
use Stripe\Exception\ApiErrorException;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

const PROBE_AMOUNT = '12.34';
const PROBE_CURRENCY = 'usd';

/**
 * @param array<string, mixed> $actual
 * @param array<string, mixed> $expected
 */
function assertExactArray(array $actual, array $expected, string $label): void
{
    ksort($actual);
    ksort($expected);

    if ($actual !== $expected) {
        throw new RuntimeException($label . ' did not match the expected connector contract.');
    }
}

function requireStringEnvironment(string $name): string
{
    $value = getenv($name);

    if (!is_string($value) || $value === '') {
        throw new RuntimeException('Required sandbox environment is incomplete: ' . $name . '.');
    }

    return $value;
}

$secret = requireStringEnvironment('KIMAI_STRIPE_CONNECTOR_STRIPE_API_KEY');
$redactor = new SecretRedactor();
$linkId = null;
$client = null;
$failure = null;

try {
    $credential = new StripeCredential($secret, CredentialSource::DIRECT);
    if ($credential->isLive()) {
        throw new RuntimeException('Stripe sandbox probe refuses to use a live-mode credential.');
    }

    $runId = requireStringEnvironment('STRIPE_SANDBOX_RUN_ID');
    $instanceId = new KimaiInstanceId(requireStringEnvironment('KIMAI_STRIPE_CONNECTOR_INSTANCE_ID'));
    $metadata = new InvoiceMetadata(
        $instanceId,
        'E2E-STRIPE-' . $runId,
        Money::fromDecimal(PROBE_AMOUNT, PROBE_CURRENCY),
    );
    $request = PaymentLinkCreateRequest::fromInvoiceMetadata($metadata);

    $client = (new StripeClientFactory())->create($credential);
    $link = $client->paymentLinks->create($request->parameters(), $request->requestOptions());
    $linkId = $link->id;

    if (!is_string($linkId) || $linkId === '') {
        throw new RuntimeException('Stripe returned a Payment Link without a usable ID.');
    }

    $payload = $link->toArray();
    assertExactArray(
        is_array($payload['metadata'] ?? null) ? $payload['metadata'] : [],
        $metadata->toStripeMetadata(),
        'Payment Link metadata',
    );

    if (($payload['active'] ?? null) !== true) {
        throw new RuntimeException('Created Payment Link was not active.');
    }

    if (($payload['payment_method_types'] ?? null) !== ['card']) {
        throw new RuntimeException('Created Payment Link was not restricted to card payments.');
    }

    $completedSessions = $payload['restrictions']['completed_sessions']['limit'] ?? null;
    if ($completedSessions !== 1) {
        throw new RuntimeException('Created Payment Link did not enforce the one-completed-session limit.');
    }

    $lineItems = $client->paymentLinks->allLineItems($linkId, ['limit' => 10]);
    if (count($lineItems->data) !== 1) {
        throw new RuntimeException('Created Payment Link did not contain exactly one line item.');
    }

    $lineItem = $lineItems->data[0]->toArray();
    if (($lineItem['quantity'] ?? null) !== 1) {
        throw new RuntimeException('Created Payment Link line-item quantity was not exactly one.');
    }

    $price = $lineItem['price'] ?? null;
    if (!is_array($price)) {
        throw new RuntimeException('Stripe did not return an inspectable line-item price.');
    }

    if (($price['unit_amount'] ?? null) !== 1234 || ($price['currency'] ?? null) !== PROBE_CURRENCY) {
        throw new RuntimeException('Stripe line-item amount or currency did not match the exact invoice amount.');
    }

    $retrieved = $client->paymentLinks->retrieve($linkId)->toArray();
    assertExactArray(
        is_array($retrieved['metadata'] ?? null) ? $retrieved['metadata'] : [],
        $metadata->toStripeMetadata(),
        'Retrieved Payment Link metadata',
    );

    // Exercise the list permission required by discovery without logging unrelated sandbox objects.
    $client->paymentLinks->all(['limit' => 1]);

    // Prove each configured read permission without logging returned sandbox objects.
    $client->checkout->sessions->all(['payment_link' => $linkId, 'limit' => 1]);
    $client->paymentIntents->all(['limit' => 1]);
    $client->charges->all(['limit' => 1]);

    fwrite(STDOUT, "Stripe sandbox contract probe passed.\n");
} catch (\Throwable $exception) {
    $details = $redactor->redact($exception->getMessage(), [$secret]);

    if ($exception instanceof ApiErrorException) {
        $status = $exception->getHttpStatus();
        $details = sprintf(
            'Stripe API error%s: %s',
            $status === null ? '' : ' HTTP ' . $status,
            $details,
        );
    }

    $failure = new RuntimeException($details);
} finally {
    if ($client !== null && is_string($linkId) && $linkId !== '') {
        try {
            $deactivated = $client->paymentLinks->update($linkId, ['active' => false])->toArray();
            if (($deactivated['active'] ?? null) !== false) {
                throw new RuntimeException('Stripe did not confirm Payment Link deactivation.');
            }
        } catch (\Throwable $cleanupException) {
            $cleanupMessage = $redactor->redact($cleanupException->getMessage(), [$secret]);
            $cleanupFailure = new RuntimeException('Stripe sandbox cleanup failed: ' . $cleanupMessage);
            $failure ??= $cleanupFailure;
        }
    }
}

if ($failure !== null) {
    fwrite(STDERR, 'Stripe sandbox contract probe failed: ' . $failure->getMessage() . "\n");
    exit(1);
}
