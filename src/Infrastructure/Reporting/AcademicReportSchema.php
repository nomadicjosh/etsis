<?php

declare(strict_types=1);

namespace Infrastructure\Reporting;

final class AcademicReportSchema
{
    /** @return array<string, array<string, mixed>> */
    public static function datasets(): array
    {
        $studentRead = ["sis:student:read"];
        $join = static fn(
            string $table,
            string $parent,
            string $left,
            string $right,
            array $permissions = [],
        ): array => [
            "table" => $table,
            "parent" => $parent,
            "left" => $left,
            "right" => $right,
            "permissions" => $permissions,
            "label" => match ($table) {
                "sis_students" => "Student records",
                "sis_persons" => "Student names",
                "sis_person_identifiers" => "Institutional IDs",
                "sis_programs" => "Academic programs",
                "sis_terms" => "Academic terms",
                "sis_student_catalogs" => "Assigned curricula",
                "sis_program_catalogs" => "Program catalog revisions",
                "sis_program_catalog_details" => "Published program details",
                "sis_academic_years" => "Academic years",
                "sis_grades" => "Final grades",
                "sis_courses" => "Courses",
                "sis_course_descriptions" => "Course descriptions",
                "sis_credit_course_versions" => "Academic credit course versions",
                "sis_credit_posting_terms" => "Retained academic placement",
                "sis_section_course_versions" => "Section course definitions",
                "sis_course_versions" => "Retained course definitions",
                "sis_course_version_classes" => "Retained course classifications",
                "sis_section_delivery" => "Delivery formats",
                "sis_section_classifications" => "Current section setup",
                "sis_section_version_classes" => "Retained offering details",
                "sis_catalog_classifications" => "Academic reference definitions",
                "sis_academic_credit_streams" => "Institutional offering links",
                "sis_enrollment_section_versions" => "Accepted offering references",
                "sis_enrollments" => "Enrollments",
                default => "Related records",
            },
        ];
        $field = static fn(
            string $label,
            string $type,
            string $relation,
            string $column,
            array $permissions = [],
        ): array => [
            "label" => $label,
            "type" => $type,
            "relation" => $relation,
            "column" => $column,
            "permissions" => $permissions,
        ];
        $classificationFields = [];
        $classificationLabels = [
            "department" => "department",
            "academic_level" => "academic level",
            "course_level" => "course level",
        ];
        foreach ($classificationLabels as $kind => $label) {
            $classificationFields["retained_" . $kind . "_code"] = $field(
                "Retained " . $label . " code",
                "string",
                "retained_classifications",
                $kind . "_code",
            );
            $classificationFields["retained_" . $kind . "_title"] = $field(
                "Retained " . $label . " name",
                "string",
                "retained_classifications",
                $kind . "_title",
            );
        }
        $personJoins = [
            "person" => $join("sis_persons", "student", "person_id", "id"),
            "identity" => $join("sis_person_identifiers", "person", "id", "person_id"),
        ];
        $personFields = [
            "student_id" => $field("Student reference", "string", "student", "id"),
            "institutional_id" => $field("Institutional ID", "string", "identity", "institutional_id"),
            "full_name" => $field("Student full name", "string", "person", "given_name") + [
                "expression" => "full_name",
            ],
            "given_name" => $field("Given name", "string", "person", "given_name"),
            "family_name" => $field("Family name", "string", "person", "family_name"),
            "active" => $field("Active student", "boolean", "student", "active"),
        ];
        $students = [
            'student_population' => true,
            'table' => 'sis_students',
            'root' => 'student',
            'label' => 'Students & current primary program',
            'description' => 'One row per student, with the current primary program and institutional entry term.',
            'permissions' => $studentRead,
            'relationships' => $personJoins + [
                'program' => $join('sis_programs', 'student', 'primary_program_id', 'id'),
                'term' => $join('sis_terms', 'student', 'entry_term_id', 'id'),
                'latest_registration' => $join('sis_terms', 'student', 'id', 'id') + [
                    'source' => 'latest_registration_term',
                ],
                'registration' => $join('sis_enrollments', 'student', 'id', 'student_id') + ['many' => true],
                'registered_section' => $join('sis_sections', 'registration', 'section_id', 'id'),
                'registration_term' => $join('sis_terms', 'registration', 'term_id', 'id'),
            ],
            'fields' => $personFields + [
                'primary_program_id' => $field('Primary program reference', 'string', 'student', 'primary_program_id'),
                'institutional_entry_term_id' => $field(
                    'Institutional entry term reference',
                    'string',
                    'student',
                    'entry_term_id'
                ),
                'program_code' => $field('Primary program code', 'string', 'program', 'code'),
                'program_title' => $field('Primary program title', 'string', 'program', 'title'),
                'entry_term' => $field('Institutional entry term', 'string', 'term', 'code'),
                'registration_status' => $field('Registration status', 'string', 'registration', 'status'),
                'registration_section_code' => $field(
                    'Registered section code',
                    'string',
                    'registered_section',
                    'code'
                ),
                'registration_term_code' => $field('Registration term', 'string', 'registration_term', 'code'),
                'registration_term_starts_on' => $field(
                    'Registration term starts',
                    'date',
                    'registration_term',
                    'starts_on'
                ),
                'latest_term_id' => $field('Latest registered term reference', 'string', 'latest_registration', 'id'),
                'latest_term_code' => $field('Latest registered term', 'string', 'latest_registration', 'code'),
                'latest_term_starts_on' => $field(
                    'Latest registered term starts',
                    'date',
                    'latest_registration',
                    'starts_on'
                ),
                'latest_term_ends_on' => $field(
                    'Latest registered term ends',
                    'date',
                    'latest_registration',
                    'ends_on'
                ),
            ],
        ];
        $students['relationships']['latest_registration']['label'] = 'Latest registered term';
        $students['relationships']['registration']['label'] = 'Registrations';
        $students['relationships']['registered_section']['label'] = 'Registered sections';
        $students['relationships']['registration_term']['label'] = 'Registration terms';
        $program = [
            "student_population" => true,
            "table" => "sis_student_programs",
            "root" => "assignment",
            "label" => "Student programs & catalog cohorts",
            "description" => "One row per student program assignment, including its assigned catalog year.",
            "permissions" => $studentRead,
            "relationships" => [
                "student" => $join("sis_students", "assignment", "student_id", "id"),
                ...$personJoins,
                "program" => $join("sis_programs", "assignment", "program_id", "id"),
                "term" => $join("sis_terms", "assignment", "entry_term_id", "id"),
                "binding" => $join("sis_student_catalogs", "assignment", "id", "assignment_id"),
                "catalog" => $join("sis_program_catalogs", "binding", "catalog_id", "id"),
                "program_definition" => $join("sis_program_catalog_details", "catalog", "id", "id"),
                "published_program" => $join("sis_program_catalogs", "program_definition", "id", "id"),
                "year" => $join("sis_academic_years", "catalog", "year_id", "id"),
            ],
            "fields" => $personFields + [
                "program_code" => $field("Program code", "string", "program", "code"),
                "program_title" => $field("Program title", "string", "program", "title"),
                "entry_term" => $field("Program entry term", "string", "term", "code"),
                "assignment_status" => $field("Program assignment status", "string", "assignment", "status"),
                "catalog_year" => $field("Matriculation academic year", "string", "year", "code"),
                "catalog_revision" => $field("Assigned catalog revision", "integer", "catalog", "revision"),
                "published_program_code" => $field(
                    "Published program code",
                    "string",
                    "published_program",
                    "program_code",
                ),
                "published_program_title" => $field(
                    "Published program title",
                    "string",
                    "published_program",
                    "program_title",
                ),
                "published_program_description" => $field(
                    "Published program description",
                    "string",
                    "program_definition",
                    "description",
                ),
                "published_program_year" => $field(
                    "Published program academic year",
                    "string",
                    "program_definition",
                    "academic_year_code",
                ),
                "program_department_code" => $field(
                    "Program department code",
                    "string",
                    "program_definition",
                    "department_code",
                ),
                "program_department_title" => $field(
                    "Program department name",
                    "string",
                    "program_definition",
                    "department_title",
                ),
                "program_academic_level_code" => $field(
                    "Program academic level code",
                    "string",
                    "program_definition",
                    "academic_level_code",
                ),
                "program_academic_level_title" => $field(
                    "Program academic level name",
                    "string",
                    "program_definition",
                    "academic_level_title",
                ),
                "program_award_code" => $field(
                    "Program award code",
                    "string",
                    "program_definition",
                    "award_code",
                ),
                "program_award_title" => $field(
                    "Program award name",
                    "string",
                    "program_definition",
                    "award_title",
                ),
            ],
        ];
        $credits = [
            "student_population" => true,
            "table" => "sis_academic_credits",
            "root" => "credit",
            "label" => "Academic credits & final grades",
            "description" => "One row per academic credit snapshot. Historical course labels are preserved.",
            "permissions" => $studentRead,
            "relationships" => [
                "student" => $join("sis_students", "credit", "student_id", "id"),
                ...$personJoins,
                "term" => $join("sis_terms", "credit", "term_id", "id"),
                "grade" => $join("sis_grades", "credit", "grade_id", "id"),
                "course_binding" => $join("sis_credit_course_versions", "credit", "id", "id"),
                "course_version" => $join("sis_course_versions", "course_binding", "course_version_id", "id"),
                "retained_subject" => $join(
                    "sis_course_version_subjects",
                    "course_binding",
                    "course_version_id",
                    "id",
                ),
                "retained_classifications" => $join(
                    "sis_course_version_classes",
                    "course_binding",
                    "course_version_id",
                    "id",
                ),
                "institutional_offering" => $join("sis_academic_credit_streams", "credit", "id", "id"),
                "posting_reference" => $join("sis_credit_posting_terms", "credit", "id", "id"),
                "original_enrollment" => $join("sis_enrollments", "institutional_offering", "enrollment_id", "id"),
                "original_registration_term" => $join("sis_terms", "original_enrollment", "term_id", "id"),
                "offering_reference" => $join(
                    "sis_enrollment_section_versions",
                    "institutional_offering",
                    "enrollment_id",
                    "id",
                ),
                "retained_offering" => $join(
                    "sis_section_version_classes",
                    "offering_reference",
                    "publication_id",
                    "id",
                ),
            ],
            "fields" => $personFields +
                $classificationFields + [
                    "retained_instructional_method_code" => $field(
                        "Retained instructional method code",
                        "string",
                        "retained_offering",
                        "instructional_method_code",
                    ),
                    "retained_instructional_method_title" => $field(
                        "Retained instructional method name",
                        "string",
                        "retained_offering",
                        "instructional_method_title",
                    ),
                    "retained_campus_code" => $field(
                        "Retained campus/site code",
                        "string",
                        "retained_offering",
                        "campus_code",
                    ),
                    "retained_campus_title" => $field(
                        "Retained campus/site name",
                        "string",
                        "retained_offering",
                        "campus_title",
                    ),
                    "retained_subject_code" => $field(
                        "Retained subject code",
                        "string",
                        "retained_subject",
                        "code",
                    ),
                    "retained_subject_title" => $field(
                        "Retained subject name",
                        "string",
                        "retained_subject",
                        "title",
                    ),
                    "course_version_id" => $field(
                        "Retained course version",
                        "string",
                        "course_binding",
                        "course_version_id",
                    ),
                    "course_code" => $field("Recorded course code", "string", "credit", "course_code"),
                    "course_title" => $field("Recorded course title", "string", "credit", "course_title"),
                    "term_code" => $field("Academic term", "string", "term", "code"),
                    "retained_posting_term_code" => $field(
                        "Retained corrected posting term",
                        "string",
                        "posting_reference",
                        "term_code",
                    ),
                    "original_registration_term_code" => $field(
                        "Original registration term current code",
                        "string",
                        "original_registration_term",
                        "code",
                    ),
                    "term_starts_on" => $field("Term begins", "date", "term", "starts_on"),
                    "grade_code" => $field("Final grade", "string", "grade", "code"),
                    "grade_scale_version" => $field(
                        "Recorded grade scale publication",
                        "string",
                        "grade",
                        "scale_version_id",
                    ),
                    "grade_units" => $field("Grade-point units", "double", "grade", "points_milli") + [
                        "scale" => 1000,
                    ],
                    "status" => $field("Academic credit status", "string", "credit", "status"),
                    "status_label" => $field("Readable academic status", "string", "credit", "status") + [
                        "expression" => "registration_label",
                    ],
                    "credit_type" => $field("Credit type", "string", "credit", "credit_type"),
                    "attempted" => $field("Attempted credits", "double", "credit", "attempted_milli") + [
                        "scale" => 1000,
                    ],
                    "completed" => $field("Earned credits", "double", "credit", "completed_milli") + [
                        "scale" => 1000,
                    ],
                ],
        ];
        $sections = [
            "table" => "sis_sections",
            "root" => "section",
            "label" => "Sections, teaching & enrollment counts",
            "description" => "One row per section; enrollment relationships can expand to registration rows.",
            "permissions" => ["sis:catalog:manage"],
            "relationships" => [
                "course" => $join("sis_courses", "section", "course_id", "id"),
                "course_text" => $join("sis_course_descriptions", "course", "id", "course_id"),
                "course_binding" => $join("sis_section_course_versions", "section", "id", "id"),
                "course_version" => $join("sis_course_versions", "course_binding", "course_version_id", "id"),
                "retained_subject" => $join(
                    "sis_course_version_subjects",
                    "course_binding",
                    "course_version_id",
                    "id",
                ),
                "retained_classifications" => $join(
                    "sis_course_version_classes",
                    "course_binding",
                    "course_version_id",
                    "id",
                ),
                "section_setup" => $join("sis_section_classifications", "section", "id", "id"),
                "current_method" => $join(
                    "sis_catalog_classifications",
                    "section_setup",
                    "instructional_method_id",
                    "id",
                ),
                "current_campus" => $join("sis_catalog_classifications", "section_setup", "campus_id", "id"),
                "term" => $join("sis_terms", "section", "term_id", "id"),
                "delivery" => $join("sis_section_delivery", "section", "id", "section_id"),
                "enrollment" => $join("sis_enrollments", "section", "id", "section_id", $studentRead) + [
                    "many" => true,
                ],
                "student" => $join("sis_students", "enrollment", "student_id", "id", $studentRead),
                ...$personJoins,
            ],
            "fields" => $classificationFields + [
                "current_instructional_method_code" => $field(
                    "Current instructional method code",
                    "string",
                    "current_method",
                    "code",
                ),
                "current_instructional_method_title" => $field(
                    "Current instructional method name",
                    "string",
                    "current_method",
                    "title",
                ),
                "current_campus_code" => $field("Current campus/site code", "string", "current_campus", "code"),
                "current_campus_title" => $field("Current campus/site name", "string", "current_campus", "title"),

                "retained_subject_code" => $field(
                    "Retained subject code",
                    "string",
                    "retained_subject",
                    "code",
                ),
                "retained_subject_title" => $field(
                    "Retained subject name",
                    "string",
                    "retained_subject",
                    "title",
                ),
                "section_code" => $field("Section code", "string", "section", "code"),
                "retained_course_version_id" => $field(
                    "Retained course version",
                    "string",
                    "course_version",
                    "id",
                ),
                "retained_course_code" => $field("Retained course code", "string", "course_version", "code"),
                "retained_course_title" => $field(
                    "Retained catalog title",
                    "string",
                    "course_version",
                    "title",
                ),
                "retained_transcript_title" => $field(
                    "Retained transcript title",
                    "string",
                    "course_version",
                    "transcript_title",
                ),
                "retained_course_credits" => $field(
                    "Retained credits",
                    "double",
                    "course_version",
                    "credits_milli",
                ) + ["scale" => 1000],
                "published_transcript_title" => $field(
                    "Current published transcript title",
                    "string",
                    "course_text",
                    "published_transcript_title",
                ),
                "published_course_description" => $field(
                    "Current published course description",
                    "string",
                    "course_text",
                    "published_description",
                ),
                "course_code" => $field("Current course code", "string", "course", "code"),
                "course_title" => $field("Current catalog title", "string", "course", "title"),
                "term_code" => $field("Academic term", "string", "term", "code"),
                "capacity" => $field("Section capacity", "integer", "section", "capacity"),
                "delivery_mode" => $field("Delivery mode", "string", "delivery", "mode"),
                "enrollment_id" => $field("Enrollment reference", "string", "enrollment", "id", $studentRead),
                "enrollment_status" => $field(
                    "Enrollment status",
                    "string",
                    "enrollment",
                    "status",
                    $studentRead,
                ),
                "institutional_id" => $field(
                    "Student institutional ID",
                    "string",
                    "identity",
                    "institutional_id",
                    $studentRead,
                ),
            ],
        ];
        $academicRelations = [
            'academic_credit' => $join('sis_academic_credits', 'student', 'id', 'student_id', $studentRead) + [
                'many' => true,
            ],
            'credit_term' => $join('sis_terms', 'academic_credit', 'term_id', 'id'),
            'credit_grade' => $join('sis_grades', 'academic_credit', 'grade_id', 'id'),
        ];
        $academicRelations['academic_credit']['label'] = 'Recorded academic credits';
        $academicRelations['credit_term']['label'] = 'Recorded-credit terms';
        $academicRelations['credit_grade']['label'] = 'Recorded grades';
        $academicFields = [
            'academic_credit_id' => $field('Academic credit reference', 'string', 'academic_credit', 'id'),
            'recorded_course_code' => $field('Recorded course code', 'string', 'academic_credit', 'course_code'),
            'recorded_course_title' => $field('Recorded course title', 'string', 'academic_credit', 'course_title'),
            'recorded_credit_status' => $field('Recorded credit status', 'string', 'academic_credit', 'status'),
            'recorded_attempted' => $field('Recorded attempted credits', 'double', 'academic_credit', 'attempted_milli')
                + ['scale' => 1000],
            'recorded_completed' => $field('Recorded earned credits', 'double', 'academic_credit', 'completed_milli')
                + ['scale' => 1000],
            'recorded_term_code' => $field('Recorded-credit term', 'string', 'credit_term', 'code'),
            'recorded_term_starts_on' => $field('Recorded-credit term starts', 'date', 'credit_term', 'starts_on'),
            'recorded_grade_code' => $field('Recorded final grade', 'string', 'credit_grade', 'code'),
        ];
        $applications = [
            'table' => 'sis_applications', 'root' => 'application',
            'label' => 'Applications & admissions',
            'description' => 'One row per application. Join current applicant, program, entry-term '
                . 'and authorized student records.',
            'permissions' => ['sis:admissions:manage'],
            'relationships' => [
                'person' => $join('sis_persons', 'application', 'person_id', 'id'),
                'identity' => $join('sis_person_identifiers', 'person', 'id', 'person_id'),
                'program' => $join('sis_programs', 'application', 'program_id', 'id'),
                'term' => $join('sis_terms', 'application', 'entry_term_id', 'id'),
                'student' => $join('sis_students', 'person', 'id', 'person_id', $studentRead),
                'submission' => $join('sis_application_intake', 'application', 'id', 'id')
                    + ['description' => 'Actual submission facts only; earlier unknown dates remain blank.'],
            ],
            'fields' => [
                'application_id' => $field('Application reference', 'string', 'application', 'id'),
                'submitted_term_code' => $field('Submitted intake', 'string', 'submission', 'submitted_term_code')
                    + ['description' => 'The recorded intake when submitted; unknown earlier values remain blank.'],
                'submitted_on' => $field('Submitted on', 'date', 'submission', 'submitted_on'),
                'submission_timezone' => $field('Submission timezone', 'string', 'submission', 'submission_timezone'),
                'submission_recorded_on' => $field('Submission recorded on', 'date', 'submission', 'recorded_on'),
                'submission_channel' => $field('Submission channel', 'string', 'submission', 'channel'),
                'status' => $field(
                    'Application status',
                    'string',
                    'application',
                    'status'
                ),
                'institutional_id' => $field('Institutional ID', 'string', 'identity', 'institutional_id'),
                'given_name' => $field('Given name', 'string', 'person', 'given_name'),
                'family_name' => $field('Family name', 'string', 'person', 'family_name'),
                'full_name' => $field('Applicant full name', 'string', 'person', 'given_name')
                    + ['expression' => 'full_name'],
                'program_code' => $field('Current program code', 'string', 'program', 'code'),
                'program_title' => $field('Current program title', 'string', 'program', 'title'),
                'entry_term' => $field('Intended entry term', 'string', 'term', 'code'),
                'term_starts_on' => $field('Intended entry term starts', 'date', 'term', 'starts_on'),
                'term_ends_on' => $field('Intended entry term ends', 'date', 'term', 'ends_on'),
                'student_id' => $field('Person’s current student reference', 'string', 'student', 'id', $studentRead),
                'active' => $field('Person’s current student activity', 'boolean', 'student', 'active', $studentRead),
            ],
        ];
        $applications['relationships']['submission']['label'] = 'Recorded submissions';
        $studentRelations = array_intersect_key($students['relationships'], array_flip([
            'registration', 'registered_section', 'registration_term', 'latest_registration',
        ])) + $academicRelations;
        $studentFields = array_intersect_key($students['fields'], array_flip([
            'registration_status', 'registration_section_code', 'registration_term_code', 'registration_term_starts_on',
            'latest_term_id', 'latest_term_code', 'latest_term_starts_on', 'latest_term_ends_on',
        ])) + $academicFields;
        $studentRelations += [
            "person_application" => $join("sis_applications", "person", "id", "person_id", [
                "sis:admissions:manage",
            ]) + ["many" => true, "label" => "Person’s applications"],
            "person_application_submission" => $join(
                "sis_application_intake",
                "person_application",
                "id",
                "id",
            ),
            "person_application_term" => $join("sis_terms", "person_application", "entry_term_id", "id"),
            "person_application_program" => $join("sis_programs", "person_application", "program_id", "id"),
        ];
        $studentRelations["person_application"]["label"] = "Person’s applications";
        $studentRelations["person_application_submission"]["label"] = "Application submissions";
        $studentRelations["person_application_term"]["label"] = "Application entry terms";
        $studentRelations["person_application_program"]["label"] = "Application programs";
        $studentFields += [
            "person_application_id" => $field(
                "Related application reference",
                "string",
                "person_application",
                "id",
            ),
            "person_application_status" => $field(
                "Related application status",
                "string",
                "person_application",
                "status",
            ),
            "person_application_submitted_on" => $field(
                "Related application submitted on",
                "date",
                "person_application_submission",
                "submitted_on",
            ),
            "person_application_recorded_on" => $field(
                "Related submission recorded on",
                "date",
                "person_application_submission",
                "recorded_on",
            ),
            "person_application_entry_term" => $field(
                "Related application entry term",
                "string",
                "person_application_term",
                "code",
            ),
            "person_application_program_code" => $field(
                "Related application program",
                "string",
                "person_application_program",
                "code",
            ),
        ];
        $withStudentWork = static fn(array $dataset): array => array_replace($dataset, [
            'relationships' => $dataset['relationships'] + $studentRelations,
            'fields' => $dataset['fields'] + $studentFields,
        ]);
        $accounts = [
            'student_population' => true,
            'table' => 'sis_student_accounts', 'root' => 'account',
            'label' => 'Student account balances',
            'description' => 'One row per student and billing term. Positive balances are owed; negative balances '
                . 'are available credit. Totals exclude reversed postings; currency stays with every amount.',
            'permissions' => ['sis:finance:read', ...$studentRead],
            'financial_balances' => true,
            'relationships' => [
                'balance' => $join('sis_account_balances', 'account', 'id', 'id'),
                'student' => $join('sis_students', 'account', 'student_id', 'id'),
                ...$personJoins,
                'term' => $join('sis_terms', 'account', 'term_id', 'id'),
                'program' => $join('sis_programs', 'student', 'primary_program_id', 'id'),
            ],
            'fields' => $personFields + [
                'account_id' => $field('Account reference', 'string', 'account', 'id'),
                'term_code' => $field('Billing term', 'string', 'term', 'code'),
                'term_starts_on' => $field('Billing term starts', 'date', 'term', 'starts_on'),
                'term_ends_on' => $field('Billing term ends', 'date', 'term', 'ends_on'),
                'program_code' => $field('Current primary program code', 'string', 'program', 'code'),
                'program_title' => $field('Current primary program title', 'string', 'program', 'title'),
                'currency' => $field('Currency', 'string', 'account', 'currency'),
                'balance' => $field('Account balance', 'double', 'balance', 'balance_minor')
                    + ['expression' => 'account_money', 'currency_field' => 'currency',
                        'currency_relation' => 'account'],
                'balance_minor' => $field('Balance in minor units', 'integer', 'balance', 'balance_minor')
                    + ['currency_field' => 'currency'],
            ],
        ];
        foreach (['charges' => 'Charges', 'payments' => 'Payments', 'refunds' => 'Refunds'] as $id => $label) {
            $accounts['fields'][$id] = $field($label, 'double', 'balance', $id . '_minor')
            + ['expression' => 'account_money', 'currency_field' => 'currency', 'currency_relation' => 'account'];
            $accounts['fields'][$id . '_minor'] = $field(
                $label . ' in minor units',
                'integer',
                'balance',
                $id . '_minor'
            ) + ['currency_field' => 'currency'];
        }
        $accounts['relationships']['balance']['label'] = 'Current balance';
        $registrationChoices = [
            'A' => 'Registered by staff', 'N' => 'Self-registered', 'D' => 'Dropped',
            'W' => 'Withdrawn', 'C' => 'Cancelled',
        ];
        $datasets = [
            'accounts' => ['id' => 'accounts'] + $withStudentWork($accounts),
            'applications' => ['id' => 'applications'] + $withStudentWork($applications),
            'students' => ['id' => 'students'] + $withStudentWork($students),
            "programs" => ["id" => "programs"] + $withStudentWork($program),
            "credits" => ["id" => "credits"] + $withStudentWork($credits),
            "sections" => ["id" => "sections"] + $withStudentWork($sections),
        ];
        foreach ($datasets as &$dataset) {
            $dataset['fields']['registration_status']['choices'] = $registrationChoices;
        }
        unset($dataset);
        $datasets['sections']['fields']['enrollment_status']['choices'] = $registrationChoices;
        $applicationChoices = [
            'submitted' => 'Submitted', 'accepted' => 'Accepted', 'rejected' => 'Rejected',
            'withdrawn' => 'Withdrawn', 'matriculated' => 'Matriculated',
        ];
        $datasets['applications']['fields']['status']['choices'] = $applicationChoices;
        foreach ($datasets as &$dataset) {
            $dataset['fields']['person_application_status']['choices'] = $applicationChoices;
        }
        unset($dataset);
        $datasets['applications']['fields']['submission_channel']['choices'] = [
            'direct' => 'Direct staff entry', 'form' => 'Application form',
        ];
        $datasets['sections'] = SchedulingReportSchema::withSectionBookings($datasets['sections']);
        return $datasets + SchedulingReportSchema::datasets();
    }
}
