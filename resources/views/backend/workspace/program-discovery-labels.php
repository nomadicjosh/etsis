<?php

declare(strict_types=1);

use function Codefy\Framework\Helpers\trans_html;

return [
    'title' => trans_html('Website catalog'),
    'help' => trans_html(
        'Choose the published academic program version visitors may see. '
        . 'Draft edits stay private until a new version is deliberately selected here.',
    ),
    'publication' => trans_html('Published program definition'),
    'choose' => trans_html('Choose a publication'),
    'selected' => trans_html('Current selected publication'),
    'visible' => trans_html('Show this program in the public catalog'),
    'review' => trans_html('Review website catalog'),
    'confirm' => trans_html('Confirm catalog publication'),
    'hide' => trans_html('Confirm removal from public catalog'),
    'revise' => trans_html('Revise catalog settings'),
    'back' => trans_html('Back to catalog settings'),
    'preview' => trans_html('Public program preview'),
    'shown' => trans_html('Visitors will see this selected program version.'),
    'hidden' => trans_html(
        'This program will be removed from the public catalog. '
        . 'Academic records and application forms remain unchanged.',
    ),
    'saved' => trans_html('Website catalog updated.'),
    'empty' => trans_html('Publish an academic program definition before adding it to the website catalog.'),
    'more' => trans_html('Older publications'),
    'previous' => trans_html('Newer publications'),
    'reload' => trans_html('Reload catalog settings'),
    'open' => trans_html('View public program'),
    'year' => trans_html('Academic year'),
    'components' => trans_html('Published academic options'),
    'no_description' => trans_html('No published description.'),
    'scope' => trans_html(
        'Website visibility does not accept an applicant, select a major or change any student’s retained curriculum.',
    ),
];
