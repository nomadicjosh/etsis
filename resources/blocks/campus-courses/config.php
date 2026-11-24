<?php

declare(strict_types=1);

use function Codefy\Framework\Helpers\trans_html;

return [
    "category" => trans_html("Campus portal"),
    "title" => trans_html("Live course offerings"),
    "icon" => "fa fa-book",
    "cache" => false,
    "settings" => [
        "heading" => [
            "type" => "text",
            "label" => trans_html("Section heading"),
            "value" => "Find your next spark.",
        ],
        "eyebrow" => [
            "type" => "text",
            "label" => trans_html("Heading caption"),
            "value" => "MAKE ROOM FOR POSSIBILITY",
        ],
        "link_label" => [
            "type" => "text",
            "label" => trans_html("Section link label"),
            "value" => "Explore the catalog",
        ],
    ],
];
