<?php

declare(strict_types=1);

use Qubus\Expressive\Migration\Migration;
use Qubus\Expressive\Schema\CreateTable;

final class CreateSisAcademicWorkPursuits extends Migration
{
    public function up(): void
    {
        if (!$this->schema()->hasTable('sis_work_pursuit_scopes', true)) {
            $this->schema()->create('sis_work_pursuit_scopes', static function (CreateTable $table): void {
                $table->string('id', 26)->primary()->notNull();
                $table->string('student_id', 26)->notNull();
                $table->integer('version')->notNull();
                $table->text('projection_json')->notNull();
                $table->index(['student_id', 'id'], 'swps_student');
                // No current-credit FK: early-drop projections can be removed while attribution is retained.
            });
        }
        $adapter = $this->getAdapter();
        if (!$adapter instanceof \Qubus\Expressive\Migration\Adapter\ConnectionAwareMigrationAdapter) {
            throw new LogicException('Academic-work permissions require a database connection.');
        }
        $sql = new \Infrastructure\Persistence\SqlRecords($adapter->connection()->queryBuilder());
        \Infrastructure\Persistence\PortableTransactions::run(
            $adapter->connection()->queryBuilder(),
            static function () use ($sql): void {
                $name = 'sis:academic-work:scope';
                $old = $sql->rows('sis_access_permissions', ['name' => $name], 1)[0] ?? null;
                if ($old !== null) {
                    if ((int) $old['is_system'] !== 1) {
                        throw new LogicException('Academic-work scope permission conflicts with a custom definition.');
                    }
                    return;
                }
                $id = \Domain\Shared\ValueObject\EntityId::generate()->value;
                $sql->insert('sis_access_permissions', ['id' => $id, 'name' => $name,
                    'description' => 'Attribute academic work to pursuits and approve or revoke cross-pursuit reuse.',
                    'is_system' => 1]);
                $sql->insert('sis_access_implications', ['id' => \Domain\Shared\ValueObject\EntityId::generate()->value,
                    'ancestor_id' => $id, 'descendant_id' => $id]);
            }
        );
    }
    public function down(): void
    {
        throw new LogicException('Retained academic-work attribution must not be discarded.');
    }
}
