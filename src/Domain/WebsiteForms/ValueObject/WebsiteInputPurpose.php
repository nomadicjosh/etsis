<?php

declare(strict_types=1);

namespace Domain\WebsiteForms\ValueObject;

use function Codefy\Framework\Helpers\trans;

/** Explicit visitor contact semantics; never infer a purpose from a mutable field name or label. */
final class WebsiteInputPurpose
{
    public const array TYPES = [
        'name' => ['text'], 'given-name' => ['text'], 'additional-name' => ['text'],
        'family-name' => ['text'], 'honorific-prefix' => ['text'], 'honorific-suffix' => ['text'],
        'nickname' => ['text'], 'organization' => ['text'], 'organization-title' => ['text'],
        'email' => ['email'], 'tel' => ['tel'], 'url' => ['url'], 'street-address' => ['textarea'],
        'address-line1' => ['text'], 'address-line2' => ['text'], 'address-line3' => ['text'],
        'address-level1' => ['text', 'select'], 'address-level2' => ['text', 'select'],
        'address-level3' => ['text', 'select'], 'address-level4' => ['text', 'select'],
        'country-name' => ['text', 'select'], 'postal-code' => ['text'],
    ];

    public static function allows(string $type, mixed $purpose): bool
    {
        return $purpose === null || (is_string($purpose) && isset(self::TYPES[$purpose]) &&
            in_array($type, self::TYPES[$purpose], true));
    }

    /** @return list<array{value:string,label:string,types:list<string>}> */
    public static function choices(): array
    {
        $labels = [
            'name' => trans('Full name'), 'given-name' => trans('Given name'),
            'additional-name' => trans('Middle name'), 'family-name' => trans('Family name'),
            'honorific-prefix' => trans('Title or honorific'), 'honorific-suffix' => trans('Name suffix'),
            'nickname' => trans('Nickname'), 'organization' => trans('Organization'),
            'organization-title' => trans('Job title'), 'email' => trans('Email address'),
            'tel' => trans('Telephone number'), 'url' => trans('Website address'),
            'street-address' => trans('Street address'), 'address-line1' => trans('Address line 1'),
            'address-line2' => trans('Address line 2'), 'address-line3' => trans('Address line 3'),
            'address-level1' => trans('State, province or region'), 'address-level2' => trans('City or town'),
            'address-level3' => trans('District or locality'), 'address-level4' => trans('Neighborhood'),
            'country-name' => trans('Country name'), 'postal-code' => trans('Postal code'),
        ];
        $choices = [];
        foreach (self::TYPES as $value => $types) {
            $choices[] = ['value' => $value, 'label' => $labels[$value], 'types' => $types];
        }
        return $choices;
    }
}
