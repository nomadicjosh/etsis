<?php

declare(strict_types=1);

use Domain\WebsiteForms\ValueObject\WebsiteFormDefinition;

use function Codefy\Framework\Helpers\trans_html;

return [
    'category' => trans_html('Forms'),
    'title' => trans_html('Website form'),
    'icon' => 'fa fa-pen-to-square',
    'cache' => false,
    'settings' => [
        'website-definition' => [
            'type' => 'website-form',
            'label' => trans_html('Form design'),
            'value' => json_encode(WebsiteFormDefinition::starter(), JSON_THROW_ON_ERROR),
        ],
    ],
];
