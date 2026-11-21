<?php

declare(strict_types=1);

namespace Tests\Unit;

use Domain\Person\ProfileUpdateRequest;
use Domain\Shared\ValueObject\EntityId;
use PHPUnit\Framework\TestCase;

final class ProfileUpdateRequestTest extends TestCase
{
    public function testRetainedUtcDatesDoNotAcquireTheServersDaylightSavingGap(): void
    {
        $timezone = date_default_timezone_get();
        date_default_timezone_set('America/Los_Angeles');
        try {
            $request = ProfileUpdateRequest::create(
                EntityId::generate(),
                EntityId::generate(),
                EntityId::generate(),
                EntityId::generate(),
                'contact',
                'Fictional instructions.',
                '2026-03-08T02:30:00Z'
            );
            self::assertSame($request->facts(), ProfileUpdateRequest::restore($request->facts())->facts());
            self::assertSame(['profile_request' => '[private working request]'], $request->__debugInfo());
        } finally {
            date_default_timezone_set($timezone);
        }
    }
}
