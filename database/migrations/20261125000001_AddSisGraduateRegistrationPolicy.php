<?php

declare(strict_types=1);

use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\PortableTransactions;
use Infrastructure\Persistence\SqlRecords;
use Qubus\Expressive\Migration\Adapter\ConnectionAwareMigrationAdapter;
use Qubus\Expressive\Migration\Migration;
use Qubus\Expressive\Schema\CreateTable;

final class AddSisGraduateRegistrationPolicy extends Migration
{
    public function up(): void
    {
        if (!$this->schema()->hasTable("sis_graduate_registration_policy_state", clear: true)) {
            $this->schema()->create("sis_graduate_registration_policy_state", static function (
                CreateTable $t,
            ): void {
                $t->string("id", 26)->primary()->notNull();
                $t->integer("version")->notNull();
            });
        }
        if (!$this->schema()->hasTable("sis_graduate_course_approvals", clear: true)) {
            $this->schema()->create("sis_graduate_course_approvals", static function (CreateTable $t): void {
                $t->string("id", 26)->primary()->notNull();
                $t->integer("version")->notNull();
                $t->foreign("id", "sgca_pursuit_fk")->references("sis_student_programs", "id");
            });
        }
        $adapter = $this->getAdapter();
        if (!($adapter instanceof ConnectionAwareMigrationAdapter)) {
            throw new LogicException("Graduate registration policy needs a database connection.");
        }
        $db = $adapter->connection()->queryBuilder();
        PortableTransactions::run($db, static function () use ($db): void {
            $sql = new SqlRecords($db);
            if (
                $sql->rows(
                    "sis_graduate_registration_policy_state",
                    ["id" => \Domain\Registration\GraduateRegistrationPolicy::ID],
                    1,
                ) === []
            ) {
                $sql->insert("sis_graduate_registration_policy_state", [
                    "id" => \Domain\Registration\GraduateRegistrationPolicy::ID,
                    "version" => 0,
                ]);
            }

            $permissions = [
                "sis:registration:graduate-policy:publish" =>
                    "Publish institutional undergraduate access to graduate-course policies.",
                "sis:registration:graduate-advisor-approve" =>
                    "Approve graduate-course enrollment as an assigned advisor.",
                "sis:registration:graduate-override" =>
                    "Authorize registrar overrides for approved graduate-course enrollment.",
            ];
            foreach ($permissions as $name => $description) {
                $permission = $sql->rows("sis_access_permissions", ["name" => $name], 1)[0] ?? null;
                if ($permission !== null && (int) $permission["is_system"] !== 1) {
                    throw new LogicException("Conflicting graduate registration permission.");
                }
                $id = $permission["id"] ?? EntityId::generate()->value;
                if ($permission === null) {
                    $sql->insert("sis_access_permissions", [
                        "id" => $id,
                        "name" => $name,
                        "description" => $description,
                        "is_system" => 1,
                    ]);
                }
                if (
                    $sql->rows(
                        "sis_access_implications",
                        ["ancestor_id" => $id, "descendant_id" => $id],
                        1,
                    ) === []
                ) {
                    $sql->insert("sis_access_implications", [
                        "id" => EntityId::generate()->value,
                        "ancestor_id" => $id,
                        "descendant_id" => $id,
                    ]);
                }
            }
        });
    }

    public function down(): void
    {
        throw new LogicException("Preserve graduate registration permissions and their historical grants.");
    }
}
