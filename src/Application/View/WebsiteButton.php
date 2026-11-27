<?php

declare(strict_types=1);

namespace Application\View;

use Domain\Portal\ValueObject\NavigationTree;
use Domain\Shared\Exception\ValidationException;

use function Codefy\Framework\Helpers\trans_html;

/** Presentation settings for a native block; rendering never navigates or submits a form. */
final readonly class WebsiteButton
{
    private function __construct(
        public string $type,
        public string $label,
        public string $classes,
        public ?string $destination = null,
        public bool $newTab = false,
    ) {
    }

    public static function fromSettings(array $settings): self
    {
        $type = $settings['button-type'] ?? '';
        $type = in_array($type, ['button', 'submit', 'link'], true) ? $type : 'button';
        $label = $settings['button-text'] ?? '';
        if (
            !is_string($label) || !mb_check_encoding($label, 'UTF-8') || mb_strlen($label) > 200
            || trim($label) === '' || preg_match('/[\x00-\x1F\x7F]/', $label)
        ) {
            $label = trans_html('Continue');
        }
        $style = $settings['button-style'] ?? '';
        if (
            !in_array($style, ['btn-primary', 'btn-secondary', 'btn-success', 'btn-danger',
            'btn-warning', 'btn-info', 'btn-light', 'btn-dark'], true)
        ) {
            $style = 'btn-primary';
        }
        $size = $settings['button-size'] ?? '';
        $size = in_array($size, ['btn-sm', 'btn-lg'], true) ? $size : '';
        $destination = null;
        if ($type === 'link') {
            $url = $settings['button-url'] ?? '';
            try {
                NavigationTree::requireUrl($url);
                $destination = $url;
            } catch (ValidationException) {
                // Invalid destinations stay unavailable rather than acquiring an inferred target.
            }
        }
        $newTab = $destination !== null && ($settings['button-new-tab'] ?? '') === '1'
        && !str_starts_with($destination, 'mailto:') && !str_starts_with($destination, 'tel:');

        return new self(
            $type,
            trim($label),
            trim('btn website-button ' . $style . ' ' . $size),
            $destination,
            $newTab
        );
    }
}
