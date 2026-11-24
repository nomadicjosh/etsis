<?php

declare(strict_types=1);

use function Codefy\Framework\Helpers\trans_html;

return [
    "category" => trans_html("Campus portal"),
    "title" => trans_html("Live public news feed"),
    "icon" => "fa fa-newspaper",
    "cache" => false,
    "settings" => [
        "heading" => [
            "type" => "text",
            "label" => trans_html("Section heading"),
            "value" => "Life, learning & everything in between.",
        ],
        "eyebrow" => [
            "type" => "text",
            "label" => trans_html("Heading caption"),
            "value" => "THE CONVERSATIONS. THE CONNECTIONS.",
        ],
        "link_label" => [
            "type" => "text",
            "label" => trans_html("Section link label"),
            "value" => "All campus updates",
        ],
    ],
];
