<?php

declare(strict_types=1);

namespace KimaiStripeConnectorTests\Unit;

use KimaiPlugin\KimaiStripeConnectorBundle\Common\Result\OperatorResult;
use PHPUnit\Framework\TestCase;

final class OperatorResultTest extends TestCase
{
    public function testTaxonomyMatchesAdrThirteen(): void
    {
        self::assertSame([
            'SUCCESS',
            'NO_CHANGE',
            'RETRYABLE',
            'CONFIGURATION_ERROR',
            'CONFLICT',
            'MANUAL_REVIEW_REQUIRED',
            'INTERNAL_ERROR',
        ], array_map(static fn (OperatorResult $result): string => $result->value, OperatorResult::cases()));
    }

    public function testOnlySuccessAndNoChangeAreSuccessfulOutcomes(): void
    {
        self::assertTrue(OperatorResult::SUCCESS->isSuccessful());
        self::assertTrue(OperatorResult::NO_CHANGE->isSuccessful());
        self::assertFalse(OperatorResult::RETRYABLE->isSuccessful());
        self::assertFalse(OperatorResult::CONFIGURATION_ERROR->isSuccessful());
        self::assertFalse(OperatorResult::CONFLICT->isSuccessful());
        self::assertFalse(OperatorResult::MANUAL_REVIEW_REQUIRED->isSuccessful());
        self::assertFalse(OperatorResult::INTERNAL_ERROR->isSuccessful());
    }
}
