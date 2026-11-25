<?php

declare(strict_types=1);

use function Codefy\Framework\Helpers\trans;

return [
    "title" => trans("Eligible programs"),
    "eyebrow" => trans("SECTION REGISTRATION"),
    "loading" => trans("Loading section program eligibility…"),
    "help" => trans(
        // phpcs:ignore Generic.Files.LineLength -- Keep this complete translated message together.
        "An empty list allows every program. Otherwise, new registrations must belong to one of these programs. Save the draft, then publish and select the offering before it can govern registration.",
    ),
    "all" => trans("All programs"),
    "selected" => trans("Selected programs"),
    'campus_rule' => trans('Require program cohort to include the section campus'),
    'campus_help' => trans(
        'The section campus must be a primary or additional campus in the student’s assigned published program cohort. '
        . 'An empty program list still applies this campus rule when selected. Assign a section campus, '
        . 'then publish and select the offering to apply the rule.'
    ),
    'campus_heading' => trans('Program campus eligibility'),
    'campus_enabled' => trans('Section campus must be included in the assigned program cohort.'),
    'campus_disabled' => trans('No program campus restriction.'),
    'campus_unknown' => trans('Program campus eligibility was not recorded in this publication.'),
    "current_labels" => trans(
        "Program names describe the current catalog. Published rules retain the selected program identities.",
    ),
    "search" => trans("Find a program"),
    "find" => trans("Find programs"),
    "none" => trans("No programs match this search."),
    "previous" => trans("Previous programs"),
    "more" => trans("More programs"),
    "remove" => trans("Remove program"),
    "limit" => trans("Choose at most twenty programs."),
    "save" => trans("Save eligible programs"),
    "saved" => trans("Program eligibility draft saved. Publish and select the offering to apply it."),
    "publish" => trans("Review offering publication"),
    "back" => trans("Back to record"),
    "reload" => trans("Reload saved policy"),
    "reload_help" => trans(
        "Your choices remain in this drawer. Reload the saved policy before replacing a changed revision.",
    ),
    "not_recorded" => trans("Program eligibility was not recorded in this publication."),
    "restricted" => trans("Restricted to the selected programs"),
    "pending" => trans(
        "The eligible-program draft is still saving. Wait for completion, then reload this policy.",
    ),
    "denied" => trans("Program eligibility is no longer available to this account."),
];
