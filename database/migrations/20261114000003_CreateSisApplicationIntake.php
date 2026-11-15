<?php

declare(strict_types=1);

use Infrastructure\EventSourcing\ProtectedEventCodec;
use Infrastructure\EventSourcing\SubmissionProjectionUpgrade;
use Qubus\Expressive\Migration\Adapter\ConnectionAwareMigrationAdapter;
use Qubus\Expressive\Migration\Migration;
use Qubus\Expressive\Schema\CreateTable;

final class CreateSisApplicationIntake extends Migration
{
    public function up(): void
    {
        $adapter = $this->getAdapter();
        if (!$adapter instanceof ConnectionAwareMigrationAdapter) {
            throw new LogicException('Submission reporting requires a database connection.');
        }
        if (!$this->schema()->hasTable('sis_application_intake', clear: true)) {
            $this->schema()->create('sis_application_intake', static function (CreateTable $table): void {
                $table->string('id', 26)->primary()->notNull();
                $table->string('source_event_id', 26)->notNull();
                $table->date('submitted_on')->notNull();
                $table->string('submitted_at', 27)->notNull();
                $table->string('submission_timezone', 64)->notNull();
                $table->date('recorded_on')->notNull();
                $table->string('recorded_at', 27)->notNull();
                $table->string('channel', 12)->notNull();
                $table->unique('source_event_id', 'sai_event');
                $table->index(['submitted_on', 'id'], 'sai_submitted');
                $table->index(['recorded_on', 'id'], 'sai_recorded');
                $table->foreign('id', 'sai_application_fk')->references('sis_applications', 'id');
            });
        }
        $codec = $this->getObjectMap()['sis.history_codec'] ?? null;
        new SubmissionProjectionUpgrade(
            $adapter->connection()->queryBuilder(),
            $codec instanceof ProtectedEventCodec ? $codec : null,
        )->run();
    }

    public function down(): void
    {
        throw new LogicException('Remove submission report dependencies before explicitly retiring this read model.');
    }
}
