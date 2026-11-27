<?php

declare(strict_types=1);

use function Codefy\Framework\Helpers\trans_html;

return [
    'title' => trans_html('Verify your sign-in email'),
    'help' => trans_html(
        'Confirm that you control the current sign-in email for this account. This does not verify '
        . 'your identity or decide admission.'
    ),
    'required' => trans_html(
        'The institution requires email verification before you submit or resubmit your own '
        . 'application.'
    ),
    'optional' => trans_html('Email verification is optional under the current application policy.'),
    'loading' => trans_html('Checking your email verification…'),
    'destination' => trans_html('Verification destination'),
    'not_requested' => trans_html('Request a private verification link for your current sign-in email.'),
    'queued' => trans_html(
        'Your link is queued for background delivery. Check your email, then open the link while '
        . 'signed in to this account.'
    ),
    'sending' => trans_html('Delivery is in progress. Check your email for the private link.'),
    'sent' => trans_html('The verification message was sent. Open its link while signed in to this account.'),
    'uncertain' => trans_html(
        'Delivery could not be confirmed. Check your email or explicitly request a new link after '
        . 'the cooldown.'
    ),
    'expired' => trans_html('The earlier link expired. Request a new link to continue.'),
    'verified' => trans_html('Your current sign-in email is verified.'),
    'disabled' => trans_html('Email delivery is currently disabled. Contact the institution for help.'),
    'cooldown' => trans_html('Wait at least one minute between new verification links, then refresh the status.'),
    'request' => trans_html('Send verification link'),
    'refresh' => trans_html('Refresh verification status'),
    'confirm' => trans_html('Confirm my email'),
    'confirm_help' => trans_html(
        'Select Confirm my email to use the private link. Opening this page alone does not verify '
        . 'an address.'
    ),
    'link_help' => trans_html('Links expire after 30 minutes. A new link replaces the earlier one.'),
    'requesting' => trans_html('Requesting your verification link…'),
    'confirming' => trans_html('Confirming your email…'),
    'failed' => trans_html(
        'Verification could not be completed. Refresh its status before trying again. Keep your '
        . 'application tab open.'
    ),
    'invalid' => trans_html('This link is unavailable. Sign in to its matching account or request a new link.'),
    'signin' => trans_html(
        'Sign in, then reopen the private link from your email. The link is kept only in this '
        . 'page’s memory.'
    ),
    'signin_action' => trans_html('Continue to sign in'),
    'open' => trans_html('Open email verification in a new tab'),
    'return' => trans_html('Go to applications'),
    'draft_help' => trans_html(
        'Keep your original application tab open to preserve unsaved answers. Refresh verification '
        . 'there after confirming your email.'
    ),
    'javascript' => trans_html(
        'Enable JavaScript to use the private email link. It is never sent in a URL or saved in '
        . 'browser storage.'
    ),
];
