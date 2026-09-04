<?php

/**
 * @file
 * @brief Selects safe Payment Link reuse/create behavior from discovered candidates.
 */

declare(strict_types=1);

namespace KimaiPlugin\KimaiStripeConnectorBundle\PaymentLink;

use KimaiPlugin\KimaiStripeConnectorBundle\Common\Metadata\InvoiceMetadata;
use KimaiPlugin\KimaiStripeConnectorBundle\Common\Metadata\MetadataException;

/**
 * @brief Pure fail-closed Payment Link decision logic.
 *
 * @details
 * The planner never mutates Stripe.  It identifies only exact connector-managed
 * matches and treats malformed, conflicting, inactive, or ambiguous same-invoice
 * state as a stop condition rather than permission to create another payment
 * surface.
 */
final class PaymentLinkPlanner
{
    /**
     * @param list<PaymentLinkCandidate> $candidates Discovered Stripe Payment Links.
     */
    public function plan(bool $preview, InvoiceMetadata $expected, array $candidates): PaymentLinkPlan
    {
        if ($preview) {
            return PaymentLinkPlan::preview();
        }

        $exact = [];
        $conflicts = [];

        foreach ($candidates as $candidate) {
            $metadata = $candidate->metadata();

            if (($metadata['integration'] ?? null) !== InvoiceMetadata::INTEGRATION) {
                continue;
            }

            if (($metadata['kimai_instance'] ?? null) !== $expected->instanceId()->value()) {
                continue;
            }

            if (($metadata['invoice_number'] ?? null) !== $expected->invoiceNumber()) {
                continue;
            }

            try {
                $parsed = InvoiceMetadata::fromStripeMetadata($metadata);
            } catch (MetadataException $exception) {
                return PaymentLinkPlan::manualReview(
                    'A connector-managed Payment Link for this invoice has malformed or unknown metadata.'
                );
            }

            if (
                $parsed->money()->minorUnits() !== $expected->money()->minorUnits()
                || $parsed->money()->currency()->value() !== $expected->money()->currency()->value()
            ) {
                $conflicts[] = $candidate;
                continue;
            }

            if (!$candidate->active()) {
                return PaymentLinkPlan::manualReview(
                    'An exact connector-managed Payment Link exists for this invoice but is inactive.'
                );
            }

            $exact[] = $candidate;
        }

        if ($conflicts !== [] && $exact !== []) {
            return PaymentLinkPlan::manualReview(
                'Exact and financially conflicting Payment Links both exist for this invoice.'
            );
        }

        if ($conflicts !== []) {
            return PaymentLinkPlan::conflict(
                'A connector-managed Payment Link for this invoice conflicts on amount or currency.'
            );
        }

        if (count($exact) > 1) {
            return PaymentLinkPlan::manualReview(
                'Multiple exact compatible Payment Links exist for this invoice.'
            );
        }

        if (count($exact) === 1) {
            return PaymentLinkPlan::reuse($exact[0]);
        }

        return PaymentLinkPlan::create();
    }
}
