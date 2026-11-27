<?php

declare(strict_types=1);

// Fictional public inquiry schema; independent from the application Form Library.
$websiteForm = \Domain\WebsiteForms\ValueObject\WebsiteFormDefinition::starter();
$websiteForm['fields'][] = ['name' => 'topic', 'type' => 'select', 'label' => 'Inquiry topic', 'required' => true,
    'options' => [['value' => 'general', 'label' => 'General question'], ['value' => 'visit',
        'label' => 'Visit campus']]];
$websiteForm['fields'][] = ['name' => 'visit_date', 'type' => 'date', 'label' => 'Preferred visit date',
    'required' => true,
    'show_when' => ['field' => 'topic', 'value' => 'visit'], 'min' => '2026-11-01', 'max' => '2027-12-31'];
$websiteForm['fields'][] = ['name' => 'consent', 'type' => 'checkbox',
    'label' => 'Please contact me about my inquiry', 'required' => true];
if (($_GET['purposes'] ?? '') === '1') {
    $websiteForm['fields'] = [...$websiteForm['fields'],
        ['name' => 'phone', 'type' => 'tel', 'label' => 'Contact telephone', 'required' => false,
            'input_purpose' => 'tel'],
        ['name' => 'street', 'type' => 'textarea', 'label' => 'Contact street address', 'required' => false,
            'input_purpose' => 'street-address'],
        ['name' => 'city', 'type' => 'select', 'label' => 'Contact city', 'required' => false,
            'input_purpose' => 'address-level2',
            'options' => [['value' => 'Fictional town', 'label' => 'Fictional town']]],
        ['name' => 'postal', 'type' => 'text', 'label' => 'Contact postal code', 'required' => false,
            'input_purpose' => 'postal-code'],
    ];
}
