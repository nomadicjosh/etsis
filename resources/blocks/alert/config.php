<?php

declare(strict_types=1);

use function Codefy\Framework\Helpers\trans;

return [
    'category' => trans('Component'),
    'title' => trans('Alert'),
    'icon' => 'fa fa-bell',
    'settings' => [
        'alert-label' => [
            'type' => 'text',
            'label' => trans('Notice label (up to 160 characters)'),
            'placeholder' => trans('Optional descriptive label'),
            'value' => '',
        ],
        'alert-context' => [
            'type' => 'select',
            'label' => trans('Context'),
            'options' => [
                ['value' => 'alert-primary', 'label' => trans('Primary')],
                ['value' => 'alert-secondary', 'label' => trans('Secondary')],
                ['value' => 'alert-success', 'label' => trans('Success')],
                ['value' => 'alert-danger', 'label' => trans('Danger')],
                ['value' => 'alert-warning', 'label' => trans('Warning')],
                ['value' => 'alert-info', 'label' => trans('Info')],
                ['value' => 'alert-light', 'label' => trans('Light')],
                ['value' => 'alert-dark', 'label' => trans('Dark')],
            ],
            'value' => 'alert-primary',
        ],
        'alert-dismissible' => [
            'type' => 'yes_no',
            'label' => trans('Allow visitors to dismiss this notice'),
            'value' => '0',
        ],
    ],
];
