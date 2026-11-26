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
