<?php

declare(strict_types=1);

use Domain\Admissions\Repository\AdmissionPolicyRepository;
use Infrastructure\Persistence\SqlRecords;
use Qubus\Expressive\Migration\Adapter\ConnectionAwareMigrationAdapter;
use Qubus\Expressive\Migration\Migration;
use Qubus\Expressive\Schema\AlterTable;
use Qubus\Expressive\Schema\CreateTable;

final class CreateSisAdmissionWithdrawalRequests extends Migration
{
    public function up(): void
    {
        $adapter = $this->getAdapter();
        if (!$adapter instanceof ConnectionAwareMigrationAdapter) {
            throw new LogicException('Admissions configuration needs a database connection.');
        }
        $sql = new SqlRecords($adapter->connection()->queryBuilder());
        $permission = $sql->one('sis_access_permissions', ['name' => 'sis:admissions:policy']);
        if ((int) $permission['is_system'] !== 1) {
            throw new LogicException('Institutional policy authority conflicts with a custom permission.');
        }
        $old = $sql->rows('sis_admission_offer_policy', [], 2);
        if (
            count($old) !== 1 || $old[0]['id'] !== AdmissionPolicyRepository::ID
            || !in_array((string) $old[0]['require_acceptance'], ['0', '1'], true)
            || filter_var($old[0]['revision'], FILTER_VALIDATE_INT) === false || (int) $old[0]['revision'] < 0
        ) {
            throw new LogicException(
                'Review existing admissions policy before this explicit configuration conversion.'
            );
        }
        $this->schema()->create('sis_admission_policy', static function (CreateTable $t): void {
            $t->string('id', 26)->primary()->notNull();
            $t->integer('require_acceptance')->notNull();
            $t->integer('offer_revision')->notNull();
            $t->string('withdrawal_mode', 20)->notNull();
            $t->integer('withdrawal_revision')->notNull();
        });
        $values = ['id' => $old[0]['id'], 'require_acceptance' => (int) $old[0]['require_acceptance'],
            'offer_revision' => (int) $old[0]['revision'], 'withdrawal_mode' => 'request', 'withdrawal_revision' => 0];
        $sql->insert('sis_admission_policy', $values);
        $saved = $sql->one('sis_admission_policy', ['id' => $values['id']]);
        foreach ($values as $key => $value) {
            if ((string) $saved[$key] !== (string) $value) {
                throw new LogicException('The admissions policy copy could not be verified. Original settings remain.');
            }
        }
        $this->schema()->alter('sis_applications', static function (AlterTable $t): void {
            // Operational request ordering only, not an invented earlier academic/application event.
            $t->integer('withdrawal_revision')->notNull()->defaultValue(0);
        });
        $this->schema()->create('sis_admission_withdrawal_requests', static function (CreateTable $t): void {
            $t->string('id', 26)->primary()->notNull();
            $t->string('application_id', 26)->notNull();
            $t->string('owner_user_id', 26)->notNull();
            $t->string('status', 20)->notNull();
            $t->integer('version')->notNull();
            $t->text('sealed_data')->notNull();
            $t->index(['application_id', 'status', 'id'], 'sawr_application_state');
            $t->index(['status', 'id'], 'sawr_pending_queue');
            $t->foreign('application_id', 'sawr_application_fk')->references('sis_applications', 'id');
        });
        $sql->update(
            'sis_access_permissions',
            ['description' => 'Configure institutional admissions policies.'],
            ['id' => $permission['id']]
        );
        // This removes a verified, explicitly converted configuration row, never journal facts.
        $this->schema()->drop('sis_admission_offer_policy');
    }
    public function down(): void
    {
        throw new LogicException('Retain request decisions and institutional settings; plan disposition explicitly.');
    }
}
