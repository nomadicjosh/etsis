<?php

declare(strict_types=1);

use function Codefy\Framework\Helpers\trans;

return [
    'title' => trans('Payment activity'),
    'back' => trans('Back to record'),
    'intro' => trans(
        'Card authorization reserves funds with the provider. The server captures only the balance still owed.'
    ),
    'held' => trans(
        'An online payment is being finalized. Conflicting balance reductions wait until verification finishes.'
    ),
    'open' => trans('Checkout open · No money recorded'),
    'reserved' => trans('Capture reserved · Verification in progress'),
    'received' => trans('Funds received · Awaiting ledger posting'),
    'paid' => trans('Payment recorded'),
    'cancelled' => trans('Authorization cancelled · No money collected'),
    'expired' => trans('Checkout expired'),
    'review' => trans('Provider reconciliation required'),
    'quoted' => trans('Checkout amount'),
    'capture' => trans('Maximum capture'),
    'received_amount' => trans('Verified received amount'),
    'retry' => trans('Request verification'),
    'refresh' => trans('Refresh payment activity'),
    'queued' => trans('Verification is queued on the server. Refresh this activity after processing.'),
    'empty' => trans('No online checkouts on this page.'),
    'next' => trans('Next page'),
    'denied' => trans('Payment activity is unavailable with your current access.'),
    'review_help' => trans(
        'Student accounts must reconcile this provider outcome. '
        . 'Requesting verification cannot change the retained capture amount.'
    ),
];
