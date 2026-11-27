<?php

declare(strict_types=1);

use function Codefy\Framework\Helpers\trans;

return [
    'category' => trans('Basic'),
    'title' => trans('Button'),
    'icon' => 'fa fa-stop',
    'settings' => [
        'button-text' => [
            'type' => 'text',
            'label' => trans('Visible label (up to 200 characters)'),
            'value' => trans('Continue'),
        ],
        'button-type' => [
            'type' => 'select',
            'label' => trans('Button behavior'),
            'options' => [
                ['value' => 'button', 'label' => trans('Action button')],
                ['value' => 'link', 'label' => trans('Link to a destination')],
                ['value' => 'submit', 'label' => trans('Submit the enclosing form')],
            ],
            'value' => 'button',
        ],
        'button-url' => [
            'type' => 'text',
            'label' => trans('Link destination (local path, HTTP(S), email or telephone)'),
            'placeholder' => '/portal/programs/',
            'value' => '',
        ],
        'button-new-tab' => [
            'type' => 'yes_no',
            'label' => trans('Open web links in a new tab'),
            'value' => '0',
        ],
        'button-style' => [
            'type' => 'select',
            'label' => trans('Style'),
            'options' => [
                ['value' => 'btn-primary', 'label' => trans('Primary')],
                ['value' => 'btn-secondary', 'label' => trans('Secondary')],
                ['value' => 'btn-success', 'label' => trans('Success')],
                ['value' => 'btn-danger', 'label' => trans('Danger')],
                ['value' => 'btn-warning', 'label' => trans('Warning')],
                ['value' => 'btn-info', 'label' => trans('Info')],
                ['value' => 'btn-light', 'label' => trans('Light')],
                ['value' => 'btn-dark', 'label' => trans('Dark')],
            ],
            'value' => 'btn-primary',
        ],
        'button-size' => [
            'type' => 'select',
            'label' => trans('Size'),
            'options' => [
                ['value' => '', 'label' => trans('Regular')],
                ['value' => 'btn-sm', 'label' => trans('Small')],
                ['value' => 'btn-lg', 'label' => trans('Large')],
            ],
            'value' => '',
        ],
    ],
];
