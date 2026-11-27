<?php

declare(strict_types=1);

namespace Application\View;

use function Codefy\Framework\Helpers\trans;

/** Bounded presentation settings; notice wording remains in the native editable child block. */
final readonly class WebsiteNotice
{
    private function __construct(
        public string $context,
        public string $label,
        public string $tone,
        public string $icon,
        public bool $dismissible,
    ) {
    }

    public static function fromSettings(array $settings): self
    {
        $contexts = [
            'alert-primary' => [trans('Information'), 'info-circle'],
            'alert-secondary' => [trans('Notice'), 'info-circle'],
            'alert-success' => [trans('Success'), 'check-circle'],
            'alert-danger' => [trans('Important'), 'exclamation-octagon'],
            'alert-warning' => [trans('Warning'), 'exclamation-triangle'],
            'alert-info' => [trans('Information'), 'info-circle'],
            'alert-light' => [trans('Notice'), 'info-circle'],
            'alert-dark' => [trans('Notice'), 'info-circle'],
        ];
        $context = $settings['alert-context'] ?? '';
        $context = is_string($context) && isset($contexts[$context]) ? $context : 'alert-primary';
        [$tone, $icon] = $contexts[$context];
        $label = $settings['alert-label'] ?? '';
        if (
            !is_string($label) || !mb_check_encoding($label, 'UTF-8') || mb_strlen($label) > 160
            || trim($label) === '' || preg_match('/[\x00-\x1F\x7F]/', $label)
        ) {
            $label = $tone;
        }
        return new self($context, trim($label), $tone, $icon, ($settings['alert-dismissible'] ?? '') === '1');
    }
}
