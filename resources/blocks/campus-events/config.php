<?php

declare(strict_types=1);

use function Codefy\Framework\Helpers\trans_html;

return [
    "category" => trans_html("Campus portal"),
    "title" => trans_html("Live public campus events"),
    "icon" => "fa fa-calendar",
    "cache" => false,
    "settings" => [
        "heading" => [
            "type" => "text",
            "label" => trans_html("Section heading"),
            "value" => "Make time for something wonderful.",
        ],
        "eyebrow" => [
            "type" => "text",
            "label" => trans_html("Heading caption"),
            "value" => "COME BE PART OF IT",
        ],
        "link_label" => [
            "type" => "text",
            "label" => trans_html("Section link label"),
            "value" => "Explore the campus calendar",
        ],
    ],
];
