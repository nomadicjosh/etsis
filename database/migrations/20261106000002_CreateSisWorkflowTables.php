<?php

declare(strict_types=1);

use Qubus\Expressive\Migration\Migration;
use Qubus\Expressive\Schema\CreateTable;

final class CreateSisWorkflowTables extends Migration
{
    public function up(): void
    {
        $this->schema()->create('sis_audit', static function (CreateTable $table): void {
            $table->string('id', 26)->primary()->notNull();
            $table->string('actor_id', 26)->notNull();
            $table->string('action', 60)->notNull();
            $table->string('subject_id', 26)->notNull();
            $table->string('recorded_at', 26)->notNull();
        });
        $this->schema()->create('sis_courses', static function (CreateTable $table): void {
            $table->string('id', 26)->primary()->notNull();
            $table->string('code', 80)->notNull();
            $table->string('title', 191)->notNull();
            $table->integer('credits_milli')->notNull();
            $table->integer('active')->notNull();
            $table->unique(['code'], 'sis_w1_u0');
        });
        $this->schema()->create('sis_grades', static function (CreateTable $table): void {
            $table->string('id', 26)->primary()->notNull();
            $table->string('code', 12)->notNull();
            $table->integer('points_milli')->notNull();
            $table->integer('passes')->notNull();
            $table->integer('counts_gpa')->notNull();
            $table->unique(['code'], 'sis_w2_u0');
        });
        $this->schema()->create('sis_reg_windows', static function (CreateTable $table): void {
            $table->string('id', 26)->primary()->notNull();
            $table->string('term_id', 26)->notNull();
            $table->string('opens_at', 26)->notNull();
            $table->string('closes_at', 26)->notNull();
            $table->integer('course_limit')->notNull();
            $table->unique(['term_id'], 'sis_w3_u0');
            $table->foreign('term_id', 'sis_w3_f0')->references('sis_terms', 'id');
        });
        $this->schema()->create('sis_sections', static function (CreateTable $table): void {
            $table->string('id', 26)->primary()->notNull();
            $table->string('course_id', 26)->notNull();
            $table->string('term_id', 26)->notNull();
            $table->string('faculty_user_id', 36);
            $table->string('code', 80)->notNull();
            $table->integer('capacity')->notNull();
            $table->integer('active')->notNull();
            $table->integer('web_registration')->notNull();
            $table->integer('fee_minor')->notNull();
            $table->string('currency', 3)->notNull();
            $table->unique(['term_id', 'code'], 'sis_w4_u0');
            $table->foreign('course_id', 'sis_w4_f0')->references('sis_courses', 'id');
            $table->foreign('term_id', 'sis_w4_f1')->references('sis_terms', 'id');
            $table->foreign('faculty_user_id', 'sis_w4_f2')->references('users', 'user_id');
        });
        $this->schema()->create('sis_prerequisites', static function (CreateTable $table): void {
            $table->string('id', 26)->primary()->notNull();
            $table->string('course_id', 26)->notNull();
            $table->string('required_course_id', 26)->notNull();
            $table->unique(['course_id', 'required_course_id'], 'sis_w5_u0');
            $table->foreign('course_id', 'sis_w5_f0')->references('sis_courses', 'id');
            $table->foreign('required_course_id', 'sis_w5_f1')->references('sis_courses', 'id');
        });
        $this->schema()->create('sis_restrictions', static function (CreateTable $table): void {
            $table->string('id', 26)->primary()->notNull();
            $table->string('person_id', 26)->notNull();
            $table->string('code', 22)->notNull();
            $table->integer('severity')->notNull();
            $table->string('starts_on', 10)->notNull();
            $table->string('ends_on', 10);
            $table->integer('blocks_staff')->notNull();
            $table->foreign('person_id', 'sis_w6_f0')->references('sis_persons', 'id');
        });
        $this->schema()->create('sis_enrollments', static function (CreateTable $table): void {
            $table->string('id', 26)->primary()->notNull();
            $table->string('student_id', 26)->notNull();
            $table->string('section_id', 26)->notNull();
            $table->string('term_id', 26)->notNull();
            $table->string('status', 1)->notNull();
            $table->string('registered_at', 26)->notNull();
            $table->string('status_at', 26)->notNull();
            $table->integer('version')->notNull();
            $table->unique(['student_id', 'section_id'], 'sis_w7_u0');
            $table->foreign('student_id', 'sis_w7_f0')->references('sis_students', 'id');
            $table->foreign('section_id', 'sis_w7_f1')->references('sis_sections', 'id');
            $table->foreign('term_id', 'sis_w7_f2')->references('sis_terms', 'id');
        });
        $this->schema()->create('sis_academic_credits', static function (CreateTable $table): void {
            $table->string('id', 26)->primary()->notNull();
            $table->string('student_id', 26)->notNull();
            $table->string('course_id', 26)->notNull();
            $table->string('term_id', 26)->notNull();
            $table->string('source_id', 26)->notNull();
            $table->string('credit_type', 16)->notNull();
            $table->string('course_code', 80)->notNull();
            $table->string('course_title', 191)->notNull();
            $table->integer('attempted_milli')->notNull();
            $table->integer('completed_milli')->notNull();
            $table->integer('grade_points_milli')->notNull();
            $table->string('grade_id', 26);
            $table->string('status', 1)->notNull();
            $table->integer('version')->notNull();
            $table->unique(['source_id'], 'sis_w8_u0');
            $table->foreign('student_id', 'sis_w8_f0')->references('sis_students', 'id');
            $table->foreign('course_id', 'sis_w8_f1')->references('sis_courses', 'id');
            $table->foreign('term_id', 'sis_w8_f2')->references('sis_terms', 'id');
            $table->foreign('grade_id', 'sis_w8_f3')->references('sis_grades', 'id');
        });
        $this->schema()->create('sis_assignments', static function (CreateTable $table): void {
            $table->string('id', 26)->primary()->notNull();
            $table->string('section_id', 26)->notNull();
            $table->string('title', 191)->notNull();
            $table->integer('possible_hundredths')->notNull();
            $table->string('due_on', 10)->notNull();
            $table->foreign('section_id', 'sis_w9_f0')->references('sis_sections', 'id');
        });
        $this->schema()->create('sis_scores', static function (CreateTable $table): void {
            $table->string('id', 26)->primary()->notNull();
            $table->string('assignment_id', 26)->notNull();
            $table->string('student_id', 26)->notNull();
            $table->integer('earned_hundredths');
            $table->integer('version')->notNull();
            $table->unique(['assignment_id', 'student_id'], 'sis_w10_u0');
            $table->foreign('assignment_id', 'sis_w10_f0')->references('sis_assignments', 'id');
            $table->foreign('student_id', 'sis_w10_f1')->references('sis_students', 'id');
        });
        $this->schema()->create('sis_attendance', static function (CreateTable $table): void {
            $table->string('id', 26)->primary()->notNull();
            $table->string('section_id', 26)->notNull();
            $table->string('student_id', 26)->notNull();
            $table->string('held_on', 10)->notNull();
            $table->string('status', 12)->notNull();
            $table->unique(['section_id', 'student_id', 'held_on'], 'sis_w11_u0');
            $table->foreign('section_id', 'sis_w11_f0')->references('sis_sections', 'id');
            $table->foreign('student_id', 'sis_w11_f1')->references('sis_students', 'id');
        });
        $this->schema()->create('sis_rooms', static function (CreateTable $table): void {
            $table->string('id', 26)->primary()->notNull();
            $table->string('code', 80)->notNull();
            $table->integer('capacity')->notNull();
            $table->unique(['code'], 'sis_w12_u0');
        });
        $this->schema()->create('sis_bookings', static function (CreateTable $table): void {
            $table->string('id', 26)->primary()->notNull();
            $table->string('room_id', 26)->notNull();
            $table->string('owner_user_id', 36)->notNull();
            $table->string('title', 191)->notNull();
            $table->string('status', 16)->notNull();
            $table->integer('version')->notNull();
            $table->foreign('room_id', 'sis_w13_f0')->references('sis_rooms', 'id');
            $table->foreign('owner_user_id', 'sis_w13_f1')->references('users', 'user_id');
        });
        $this->schema()->create('sis_booking_slots', static function (CreateTable $table): void {
            $table->string('id', 26)->primary()->notNull();
            $table->string('booking_id', 26)->notNull();
            $table->string('room_id', 26)->notNull();
            $table->string('starts_at', 26)->notNull();
            $table->string('ends_at', 26)->notNull();
            $table->foreign('booking_id', 'sis_w14_f0')->references('sis_bookings', 'id');
            $table->foreign('room_id', 'sis_w14_f1')->references('sis_rooms', 'id');
        });
        $this->schema()->create('sis_employees', static function (CreateTable $table): void {
            $table->string('id', 26)->primary()->notNull();
            $table->string('person_id', 26)->notNull();
            $table->string('job_title', 191)->notNull();
            $table->integer('active')->notNull();
            $table->unique(['person_id'], 'sis_w15_u0');
            $table->foreign('person_id', 'sis_w15_f0')->references('sis_persons', 'id');
        });
        $this->schema()->create('sis_timesheets', static function (CreateTable $table): void {
            $table->string('id', 26)->primary()->notNull();
            $table->string('employee_id', 26)->notNull();
            $table->string('starts_at', 26)->notNull();
            $table->string('ends_at', 26)->notNull();
            $table->string('status', 16)->notNull();
            $table->string('approved_by', 36);
            $table->integer('version')->notNull();
            $table->foreign('employee_id', 'sis_w16_f0')->references('sis_employees', 'id');
            $table->foreign('approved_by', 'sis_w16_f1')->references('users', 'user_id');
        });
        $this->schema()->create('sis_external_institutions', static function (CreateTable $table): void {
            $table->string('id', 26)->primary()->notNull();
            $table->string('code', 80)->notNull();
            $table->string('name', 191)->notNull();
            $table->unique(['code'], 'sis_w17_u0');
        });
        $this->schema()->create('sis_external_courses', static function (CreateTable $table): void {
            $table->string('id', 26)->primary()->notNull();
            $table->string('institution_id', 26)->notNull();
            $table->string('code', 80)->notNull();
            $table->string('title', 191)->notNull();
            $table->unique(['institution_id', 'code'], 'sis_w18_u0');
            $table->foreign('institution_id', 'sis_w18_f0')->references('sis_external_institutions', 'id');
        });
        $this->schema()->create('sis_transfer_equivalencies', static function (CreateTable $table): void {
            $table->string('id', 26)->primary()->notNull();
            $table->string('external_course_id', 26)->notNull();
            $table->string('course_id', 26)->notNull();
            $table->string('starts_on', 10)->notNull();
            $table->string('ends_on', 10);
            $table->integer('minimum_grade_rank')->notNull();
            $table->integer('credits_milli')->notNull();
            $table->string('grade_id', 26)->notNull();
            $table->foreign('external_course_id', 'sis_w19_f0')->references('sis_external_courses', 'id');
            $table->foreign('course_id', 'sis_w19_f1')->references('sis_courses', 'id');
            $table->foreign('grade_id', 'sis_w19_f2')->references('sis_grades', 'id');
        });
        $this->schema()->create('sis_transfer_awards', static function (CreateTable $table): void {
            $table->string('id', 26)->primary()->notNull();
            $table->string('student_id', 26)->notNull();
            $table->string('equivalency_id', 26)->notNull();
            $table->string('completed_on', 10)->notNull();
            $table->string('term_id', 26)->notNull();
            $table->string('credit_id', 26)->notNull();
            $table->unique(['student_id', 'equivalency_id', 'completed_on'], 'sis_w20_u0');
            $table->foreign('student_id', 'sis_w20_f0')->references('sis_students', 'id');
            $table->foreign('equivalency_id', 'sis_w20_f1')->references('sis_transfer_equivalencies', 'id');
            $table->foreign('term_id', 'sis_w20_f2')->references('sis_terms', 'id');
            $table->foreign('credit_id', 'sis_w20_f3')->references('sis_academic_credits', 'id');
        });
        $this->schema()->create('sis_journals', static function (CreateTable $table): void {
            $table->string('id', 26)->primary()->notNull();
            $table->string('reference', 80)->notNull();
            $table->string('currency', 3)->notNull();
            $table->string('posted_on', 10)->notNull();
            $table->unique(['reference'], 'sis_w21_u0');
        });
        $this->schema()->create('sis_ledger_accounts', static function (CreateTable $table): void {
            $table->string('id', 26)->primary()->notNull();
            $table->string('code', 80)->notNull();
            $table->string('title', 191)->notNull();
            $table->string('currency', 3)->notNull();
            $table->unique(['code'], 'sis_w22_u0');
        });
        $this->schema()->create('sis_reports', static function (CreateTable $table): void {
            $table->string('id', 26)->primary()->notNull();
            $table->string('owner_user_id', 36)->notNull();
            $table->string('name', 191)->notNull();
            $table->string('program_id', 26);
            $table->string('term_id', 26);
            $table->integer('active');
            $table->foreign('owner_user_id', 'sis_w23_f0')->references('users', 'user_id');
            $table->foreign('program_id', 'sis_w23_f1')->references('sis_programs', 'id');
            $table->foreign('term_id', 'sis_w23_f2')->references('sis_terms', 'id');
        });
        $this->schema()->create('sis_report_shares', static function (CreateTable $table): void {
            $table->string('id', 26)->primary()->notNull();
            $table->string('report_id', 26)->notNull();
            $table->string('user_id', 36)->notNull();
            $table->unique(['report_id', 'user_id'], 'sis_w24_u0');
            $table->foreign('report_id', 'sis_w24_f0')->references('sis_reports', 'id');
            $table->foreign('user_id', 'sis_w24_f1')->references('users', 'user_id');
        });
        $this->schema()->create('sis_campaigns', static function (CreateTable $table): void {
            $table->string('id', 26)->primary()->notNull();
            $table->string('owner_user_id', 36)->notNull();
            $table->text('encrypted_content')->notNull();
            $table->string('status', 16)->notNull();
            $table->integer('version')->notNull();
            $table->foreign('owner_user_id', 'sis_w25_f0')->references('users', 'user_id');
        });
        $this->schema()->create('sis_deliveries', static function (CreateTable $table): void {
            $table->string('id', 26)->primary()->notNull();
            $table->string('campaign_id', 26)->notNull();
            $table->string('recipient_user_id', 36)->notNull();
            $table->string('status', 16)->notNull();
            $table->integer('version')->notNull();
            $table->unique(['campaign_id', 'recipient_user_id'], 'sis_w26_u0');
            $table->foreign('campaign_id', 'sis_w26_f0')->references('sis_campaigns', 'id');
            $table->foreign('recipient_user_id', 'sis_w26_f1')->references('users', 'user_id');
        });
        $this->schema()->create('sis_import_mappings', static function (CreateTable $table): void {
            $table->string('id', 26)->primary()->notNull();
            $table->string('source', 80)->notNull();
            $table->string('external_id', 80)->notNull();
            $table->string('content_hash', 64)->notNull();
            $table->string('course_id', 26)->notNull();
            $table->unique(['source', 'external_id'], 'sis_w27_u0');
            $table->foreign('course_id', 'sis_w27_f0')->references('sis_courses', 'id');
        });
        $this->schema()->create('sis_portal_posts', static function (CreateTable $table): void {
            $table->string('id', 26)->primary()->notNull();
            $table->string('owner_user_id', 36)->notNull();
            $table->string('kind', 12)->notNull();
            $table->string('code', 80)->notNull();
            $table->string('title', 191)->notNull();
            $table->text('content')->notNull();
            $table->string('status', 16)->notNull();
            $table->integer('version')->notNull();
            $table->unique(['code'], 'sis_w28_u0');
            $table->foreign('owner_user_id', 'sis_w28_f0')->references('users', 'user_id');
        });
    }

    public function down(): void
    {
        $tables = [
            'sis_portal_posts',
            'sis_import_mappings',
            'sis_deliveries',
            'sis_campaigns',
            'sis_report_shares',
            'sis_reports',
            'sis_ledger_accounts',
            'sis_journals',
            'sis_transfer_awards',
            'sis_transfer_equivalencies',
            'sis_external_courses',
            'sis_external_institutions',
            'sis_timesheets',
            'sis_employees',
            'sis_booking_slots',
            'sis_bookings',
            'sis_rooms',
            'sis_attendance',
            'sis_scores',
            'sis_assignments',
            'sis_academic_credits',
            'sis_enrollments',
            'sis_restrictions',
            'sis_prerequisites',
            'sis_sections',
            'sis_reg_windows',
            'sis_grades',
            'sis_courses',
            'sis_audit',
        ];
        foreach ($tables as $table) {
            $this->schema()->drop($table);
        }
    }
}
