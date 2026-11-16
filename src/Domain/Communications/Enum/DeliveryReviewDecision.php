<?php

declare(strict_types=1);

namespace Domain\Communications\Enum;

/** An administrative disposition never replaces the transport's recorded result. */
enum DeliveryReviewDecision: string
{
    case ConfirmSent = 'confirmed_sent';
    case Close = 'closed';

    public function allows(string $transportStatus): bool
    {
        return $this === self::ConfirmSent ? $transportStatus === 'needs_review'
        : in_array($transportStatus, ['sending', 'needs_review'], true);
    }
}
