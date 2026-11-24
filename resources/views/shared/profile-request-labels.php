<?php

declare(strict_types=1);

use function Codefy\Framework\Helpers\trans_html;

return [
    'identity' => trans_html('Recorded identity'),
    'identity_requests' => trans_html('Identity correction requests'),
    'request_identity' => trans_html('Request an identity correction'),
    'review_identity' => trans_html('Review recorded identity'),
    'current_identity' => trans_html('Current recorded identity'),
    'identity_loading' => trans_html('Loading recorded identity…'),
    'identity_failed' => trans_html('Recorded identity could not be loaded. Retry this review.'),
    'names_draft' => trans_html('Save or discard your name changes before leaving this editor.'),
    'close_identity' => trans_html('Close identity review'),
    'refresh_identity' => trans_html('Refresh recorded identity'),
    'edit_names' => trans_html('Edit person names'),
    'given_name' => trans_html('Given name'),
    'family_name' => trans_html('Family name'),
    'institutional_id' => trans_html('Institutional ID'),
    'save_names' => trans_html('Save person names'),
    'reason' => trans_html('Reason for profile correction'),
    'reason_help' => trans_html(
        'Kept in restricted profile history. The resolution note is the message shown to the owner.',
    ),
    'cancel_names' => trans_html('Back to identity review'),
    'names_saved' => trans_html('Person names saved. Review the details before resolving the request.'),
    'owner_help' => trans_html(
        'Review your current names and institutional ID, then explain the correction in your reply. '
        . 'Sending a reply does not change identity details, sign-in access or an admissions decision.',
    ),
    'staff_help' => trans_html(
        'Review the current identity and the owner response. Apply verified name corrections with the person editor, '
        . 'then record a separate request outcome. Institutional ID changes use the existing identifier workflow.',
    ),
    'current_help' => trans_html(
        'These are current identity details. Earlier academic records, issued documents and this request '
        . 'retain their separate histories.',
    ),
    'application_help' => trans_html(
        'Identity follow-up belongs to your person record and can apply across applications. '
        . 'Review requested corrections here without changing your application draft.',
    ),
];
