<?php

declare(strict_types=1);

use Domain\Advising\Service\AdvisingAppointmentService;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\PortableTransactions;
use Infrastructure\Persistence\SqlRecords;
use Qubus\Expressive\Migration\Adapter\ConnectionAwareMigrationAdapter;
use Qubus\Expressive\Migration\Migration;
use Qubus\Expressive\Schema\CreateTable;

final class CreateSisAdvisingAppointments extends Migration
{
    public static function define(CreateTable $t): void
    {
        $t->string('id', 26)->primary()->notNull();
        foreach (['student_id', 'pursuit_id', 'host_person_id'] as $field) {
            $t->string($field, 26)->notNull();
        }
        $t->string('owner_user_id', 36)->notNull();
        $t->string('status', 12)->notNull();
        $t->integer('version')->notNull();
        $t->string('starts_at', 20)->notNull();
        $t->string('ends_at', 20)->notNull();
        $t->text('sealed_data')->size('medium')->notNull();
        $t->index(['student_id', 'pursuit_id', 'status', 'id'], 'saa_pursuit_page');
        $t->index(['student_id', 'status', 'starts_at'], 'saa_student_time');
        $t->index(['host_person_id', 'status', 'starts_at'], 'saa_host_time');
        $t->foreign('student_id', 'saa_student_fk')->references('sis_students', 'id');
        $t->foreign('pursuit_id', 'saa_pursuit_fk')->references('sis_student_programs', 'id');
        $t->foreign('host_person_id', 'saa_host_fk')->references('sis_persons', 'id');
    }

    public function up(): void
    {
        if (!$this->schema()->hasTable('sis_advising_appointments', clear: true)) {
            $this->schema()->create('sis_advising_appointments', self::define(...));
        }
        $adapter = $this->getAdapter();
        if (!($adapter instanceof ConnectionAwareMigrationAdapter)) {
            throw new LogicException('Advising appointments require a database connection.');
        }
        $db = $adapter->connection()->queryBuilder();
        PortableTransactions::run($db, static function () use ($db): void {
            $sql = new SqlRecords($db);
            $name = AdvisingAppointmentService::PERMISSION;
            $existing = $sql->rows('sis_access_permissions', ['name' => $name], 1)[0] ?? null;
            if ($existing !== null && (int) $existing['is_system'] !== 1) {
                throw new LogicException('Conflicting advising appointment permission.');
            }
            $id = $existing['id'] ?? EntityId::generate()->value;
            if ($existing === null) {
                $sql->insert('sis_access_permissions', ['id' => $id, 'name' => $name,
                    'description' => 'Schedule advising appointments and record attendance outcomes.',
                        'is_system' => 1]);
            }
            if ($sql->rows('sis_access_implications', ['ancestor_id' => $id, 'descendant_id' => $id], 1) === []) {
                $sql->insert('sis_access_implications', ['id' => EntityId::generate()->value,
                    'ancestor_id' => $id, 'descendant_id' => $id]);
            }
        });
    }

    public function down(): void
    {
        throw new LogicException('Preserve appointment facts until institution-approved retention/disposition.');
    }
}
