<?php

declare(strict_types=1);

use function Codefy\Framework\Helpers\trans_html;

return [
    'program' => trans_html('Program department ownership'),
    'program_help' => trans_html(
        'Add up to five departments that jointly administer this program. Each department keeps its own college. '
        . 'Program ownership is independent of major/minor ownership and grants no access or eligibility.',
    ),
    'program_duplicate' => trans_html('That department is already selected for this program.'),
    "primary" => trans_html("Responsible department"),
    "unknown" => trans_html("not recorded in this publication"),
    "unassigned" => trans_html("not assigned"),
    "school" => trans_html("School/college"),
    "additional" => trans_html("Additional responsible departments"),
    "none" => trans_html("none"),
    "add" => trans_html("Add responsible department"),
    "remove" => trans_html("Remove department"),
    "help" => trans_html(
        "Add up to five other departments that jointly own this curriculum component. " .
            "Each department keeps its own school/college. These choices grant no access or eligibility.",
    ),
    "duplicate" => trans_html(
        "That department is already selected for this component.",
    ),
    "promoted" => trans_html(
        "The chosen responsible department was removed from the additional list.",
    ),
];
