<?php

declare(strict_types=1);

use function Codefy\Framework\Helpers\trans;

return [
    'category' => trans('Forms'),
    'title' => trans('Checkbox'),
    'icon' => 'fa fa-square-check',
    'settings' => [
        'checkbox-name' => ['type' => 'text', 'label' => trans('Field name'), 'value' => 'confirmation'],
        'checkbox-help' => ['type' => 'text', 'label' => trans('Help text'), 'value' => ''],

        'checkbox-label' => [
            'type' => 'text',
            'label' => trans('Label'),
            'value' => trans('Confirmation'),
        ],
        'checkbox-value' => [
            'type' => 'text',
            'label' => trans('Checkbox Value'),
            'value' => '0',
        ],
        'checkbox-required' => [
            'type' => 'yes_no',
            'label' => trans('Required?'),
            'value' => '0',
        ],
    ],
];
