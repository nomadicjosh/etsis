<?php

declare(strict_types=1);

use function Codefy\Framework\Helpers\trans_html;

return [
    'field' => trans_html('Qualifying prerequisite coursework'),
    'either' => trans_html('Institutional or transfer'),
    'institutional' => trans_html('Institutional work only'),
    'transfer' => trans_html('Transfer work only'),
    'help' => trans_html('Applies to every prerequisite requirement. Co-requisites use their separate policy.'),
    'scope' => trans_html('This rule does not change earned credits or decide which work counts toward a degree.'),
];
