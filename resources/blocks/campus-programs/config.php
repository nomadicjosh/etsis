<?php

declare(strict_types=1);

use function Codefy\Framework\Helpers\trans_html;

return [
    "category" => trans_html("Campus portal"),
    "title" => trans_html("Live academic programs"),
    "icon" => "fa fa-graduation-cap",
    "cache" => false,
    "settings" => [
        "heading" => [
            "type" => "text",
            "label" => trans_html("Section heading"),
            "value" => "A path with your name on it.",
        ],
        "eyebrow" => [
            "type" => "text",
            "label" => trans_html("Heading caption"),
            "value" => "LET YOUR INTEREST LEAD THE WAY",
        ],
        "link_label" => [
            "type" => "text",
            "label" => trans_html("Section link label"),
            "value" => "Explore applications",
        ],
        "introduction" => [
            "type" => "text",
            "label" => trans_html("Introduction"),
            "value" => "Discover an academic program, then see how to make it yours.",
        ],
    ],
];
