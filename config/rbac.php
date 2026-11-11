<?php

declare(strict_types=1);

// Initial migration seed only. Runtime authorization is database controlled.
return [
    "permissions" => [
        "sis:ai:course:readiness" => [
            "description" => "Request optional course publication-readiness suggestions.",
        ],
        "sis:ai:course:description" => ["description" => "Request optional course description drafts."],
        "sis:admissions:correct" => [
            "description" => "Approve reasoned admissions outcome corrections before matriculation.",
        ],
        "sis:gradebook:amend" => ["description" => "Approve reasoned final-grade corrections."],
        "sis:degree-audit:adjust" => [
            "description" => "Approve and revoke student-program course waivers and minimum exceptions.",
        ],
        "sis:degree-audit:assign" => [
            "description" => "Assign reviewed published degree requirements to student programs.",
        ],
        "admin:access:manage" => ["description" => "Administer roles, permissions and user grants."],
        "sis" => [
            "description" => "Student information system",
            "permissions" => [
                "sis:reporting:manage" => ["description" => "Create and share constrained student reports."],
                "sis:reporting:admin" => ["description" => "Administer institution reports."],
                "sis:reporting:run" => ["description" => "Run owned or explicitly shared student reports."],
                "sis:import:manage" => ["description" => "Import validated academic catalog data."],
                "sis:communications:manage" => [
                    "description" => "Manage and dispatch institution communications.",
                ],
                "sis:communications:admin" => ["description" => "Administer institution communications."],
                "sis:portal:manage" => ["description" => "Manage institution portal publications."],
                "sis:portal:admin" => ["description" => "Administer portal publications."],
                "sis:registration:manage" => [
                    "description" => "Manage registration and student restrictions.",
                ],
                "sis:gradebook:manage" => ["description" => "Manage grades across course sections."],
                "sis:scheduling:manage" => ["description" => "Manage room booking approvals."],
                "sis:hr:manage" => ["description" => "Manage employment and timesheet approvals."],
                "sis:transfer:manage" => ["description" => "Manage transfer courses and credit awards."],
                "sis:finance:ledger" => [
                    "description" => "Post and reverse balanced general-ledger journals.",
                ],
                "sis:audit:read" => ["description" => "Read business audit metadata."],
                "sis:catalog:manage" => ["description" => "Manage academic programs and terms."],
                "sis:finance:post" => ["description" => "Post student charges, payments and refunds."],
                "sis:finance:read" => ["description" => "Read student accounts."],
                "sis:person:assign-id" => ["description" => "Assign and change institutional person IDs."],
                "sis:person:manage" => ["description" => "Create person records."],
                "sis:admissions:manage" => ["description" => "Manage admissions and matriculation."],
                "sis:student:read" => ["description" => "Read student records."],
                "sis:transcript:manage" => ["description" => "Process official transcript requests."],
            ],
        ],
        "admin" => [
            "description" => "Administration",
            "permissions" => [
                "admin:dashboard" => ["description" => "Can access the dashboard."],
                "admin:profile" => ["description" => "Can edit own profile."],
                "admin:create:user" => ["description" => "Can create users."],
                "admin:edit:user" => ["description" => "Can edit a user profile."],
                "admin:delete:user" => ["description" => "Can delete a user profile."],
            ],
        ],
        "webmaster" => [
            "description" => "Website creator and builder.",
            "permissions" => [
                "vihzhuo:manage" => ["description" => "Can use the Vihzhuo pagebuilder to build pages."],
            ],
        ],
    ],
    "roles" => [
        // Legacy migration roles 8–12: super administrator, faculty, parent, student and staff.
        // Super authority is evaluated directly from the protected system role, including future permissions.
        "super" => [
            "description" => "Super administrator",
            "permissions" => [],
        ],
        "faculty" => [
            "description" => "Faculty: assigned-class access is checked by the application.",
            "permissions" => ["admin:dashboard", "admin:profile", "sis:reporting:run"],
        ],
        "parent" => [
            "description" => "Parent: dependent-record access requires a separate consent policy.",
            "permissions" => ["admin:dashboard", "admin:profile"],
        ],
        "student" => [
            "description" => "Student: academic and financial access is limited to owned records.",
            "permissions" => ["admin:dashboard", "admin:profile"],
        ],
        "staff" => [
            "description" => "Staff: institution-wide privileges require explicit office grants.",
            "permissions" => ["admin:dashboard", "admin:profile"],
        ],
        "user" => [
            "description" => "Regular user",
            "permissions" => ["admin:dashboard", "admin:profile"],
        ],
        "manager" => [
            "description" => "Editor",
            "permissions" => ["admin:dashboard", "admin:profile"],
        ],
        "admin" => [
            "description" => "Administrator",
            "permissions" => ["admin", "webmaster", "sis"],
        ],
    ],
];
