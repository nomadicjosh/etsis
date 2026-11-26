<?php

declare(strict_types=1);

use function Codefy\Framework\Helpers\trans;

return [
    'category' => trans('Forms'),
    'title' => trans('Textarea'),
    'icon' => 'fa fa-paragraph',
    'settings' => [
        'textarea-name' => ['type' => 'text', 'label' => trans('Field name'), 'value' => 'message'],
        'textarea-help' => ['type' => 'text', 'label' => trans('Help text'), 'value' => ''],
        'textarea-rows' => ['type' => 'number', 'label' => trans('Visible rows (1–20)'), 'value' => '4'],
        'textarea-max-length' => ['type' => 'number', 'label' => trans('Maximum characters (up to 20,000)'),
            'value' => '4000'],

        'textarea-label' => [
            'type' => 'text',
            'label' => trans('Label'),
            'value' => trans('Long answer'),
        ],
        'textarea-required' => [
            'type' => 'yes_no',
            'label' => trans('Required?'),
            'value' => '0',
        ],
        'textarea-placeholder' => [
            'type' => 'text',
            'label' => trans('Placeholder'),
            'value' => 'textarea',
        ],
    ],
];
