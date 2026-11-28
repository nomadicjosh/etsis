<?php

declare(strict_types=1);

use function Codefy\Framework\Helpers\trans;

return [
    'title' => trans('Payment gateways'),
    'kicker' => trans('Student accounts · Payment setup'),
    'intro' => trans(
        'Set up clearing assets, verify named Stripe connections, then assign a connection for ' .
        'each payment purpose and currency.'
    ),
    'connections' => trans('Connections'),
    'routes' => trans('Payment routing'),
    'assets' => trans('Clearing accounts'),
    'back_record' => trans('Back to record'),
    'back' => trans('Back to payment setup'),
    'denied' => trans('Your payment setup access changed. Reopen this task after access is restored.'),
    'reason' => trans('Reason for this payment setup change'),
    'name' => trans('Connection name'),
    'provider' => trans('Provider'),
    'merchant' => trans('Verified Stripe merchant'),
    'mode' => trans('Payment mode'),
    'live' => trans('Live payments'),
    'test' => trans('Test payments'),
    'enabled' => trans('Allow new checkouts'),
    'yes' => trans('Yes'),
    'no' => trans('No'),
    'purpose' => trans('Payment purpose'),
    'currency' => trans('Currency'),
    'code' => trans('Account code'),
    'title_account' => trans('Account title'),
    'kind' => trans('Account classification'),
    'connection_id' => trans('Assigned connection'),
    'connection' => trans('Payment connection'),
    'asset_account' => trans('Gateway clearing asset'),
    'unassigned' => trans('No connection · Disable this payment route'),
    'review' => trans('Review payment setup'),
    'review_title' => trans('Review this payment setup change'),
    'confirm' => trans('Confirm payment setup'),
    'saved' => trans('Payment setup saved.'),
    'create_asset' => trans('Create clearing asset'),
    'asset_help' => trans(
        'Use an asset account for gross funds received by the processor. Bank payouts, processing ' .
        'fees and settlement reconciliation are separate accounting decisions.'
    ),
    'refresh' => trans('Show accounts'),
    'empty' => trans('No records on this page.'),
    'classify' => trans('Classify existing account'),
    'classify_help' => trans(
        'Classify an earlier account without changing its journals. A saved classification cannot ' .
        'be changed; create another account for a different financial purpose.'
    ),
    'add_connection' => trans('Add Stripe connection'),
    'assign_route' => trans('Assign payment route'),
    'disabled' => trans(
        'New online checkouts are disabled by the installation payment switch or HTTPS ' .
        'configuration. Existing signed confirmations remain accepted.'
    ),
    'test_notice' => trans(
        'This development installation permits test connections. Test payments must never be used ' .
        'as real institutional receipts.'
    ),
    'view' => trans('View connection'),
    'edit_route' => trans('Review route assignment'),
    'more' => trans('Next page'),
    'credentials_private' => trans(
        'Credentials are encrypted on the server and cannot be displayed. Earlier checkouts ' .
        'retain their original connection and clearing assignment.'
    ),
    'edit' => trans('Edit connection'),
    'more_actions' => trans('More connection actions'),
    'connection_help' => trans(
        'Use a separate connection for each merchant, currency and live/test mode. Changing ' .
        'credentials or the clearing account affects future checkouts after review.'
    ),
    'choose_asset' => trans('Choose a classified asset account'),
    'more_assets' => trans('Load more accounts'),
    'secret' => trans('Stripe secret API key'),
    'webhook' => trans('Stripe webhook signing secret'),
    'credentials_help' => trans(
        'Leave credentials blank to retain saved values when editing. New credentials clear from ' .
        'this form when review is requested; re-enter them for another new-connection review.'
    ),
    'webhook_help' => trans(
        'Configure the Stripe endpoint at /portal/payments/webhook/ for ' .
        'checkout.session.completed and checkout.session.async_payment_succeeded.'
    ),
    'route_help' => trans(
        'Select a connection for this purpose and currency. Removing an assignment stops new ' .
        'checkouts. Existing sessions keep their original connection; application and transcript ' .
        'fee accounting remains separate from student-account journals.'
    ),
    'next' => trans('Choose connection'),
    'account' => trans('Student account · Tuition and fees'),
    'application' => trans('Application fee'),
    'transcript' => trans('Official transcript fee'),
    'asset' => trans('Asset'),
    'liability' => trans('Liability'),
    'equity' => trans('Equity'),
    'income' => trans('Income'),
    'expense' => trans('Expense'),
];
