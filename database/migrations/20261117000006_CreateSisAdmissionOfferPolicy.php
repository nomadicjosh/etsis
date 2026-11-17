<?php

declare(strict_types=1);

use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\PortableTransactions;
use Infrastructure\Persistence\SqlRecords;
use Qubus\Expressive\Migration\Adapter\ConnectionAwareMigrationAdapter;
use Qubus\Expressive\Migration\Migration;
use Qubus\Expressive\Schema\CreateTable;

final class CreateSisAdmissionOfferPolicy extends Migration
{
    public function up(): void
    {
        $this->schema()->create('sis_admission_offer_policy', static function (CreateTable $t): void {
            $t->string('id', 26)->primary()->notNull();
            $t->integer('require_acceptance')->notNull();
            $t->integer('revision')->notNull();
        });
        $adapter = $this->getAdapter();
        if (!($adapter instanceof ConnectionAwareMigrationAdapter)) {
            throw new LogicException("Admission offer settings require a database connection.");
        }
        $db = $adapter->connection()->queryBuilder();
        $sql = new SqlRecords($db);
        PortableTransactions::run($db, static function () use ($sql): void {
            $sql->insert('sis_admission_offer_policy', [
                'id' => \Domain\Admissions\Repository\AdmissionOfferPolicyRepository::ID,
                'require_acceptance' => 1, 'revision' => 0,
            ]);
            foreach (
                ['sis:admissions:offers' => 'Issue admission offers and record documented applicant responses.',
                'sis:admissions:policy' => 'Configure institutional applicant acceptance policy.',
                ] as $name => $description
            ) {
                $p = $sql->rows("sis_access_permissions", ["name" => $name], 1)[0] ?? null;
                if ($p !== null && (int) $p["is_system"] !== 1) {
                    throw new LogicException(
                        "An admission-offer permission conflicts with a custom definition.",
                    );
                }
                $id = $p["id"] ?? EntityId::generate()->value;
                if ($p === null) {
                    $sql->insert("sis_access_permissions", [
                    "id" => $id,
                    "name" => $name,
                    "description" => $description,
                    "is_system" => 1,
                    ]);
                }
                if (
                    $sql->rows("sis_access_implications", ["ancestor_id" => $id, "descendant_id" => $id], 1) ===
                    []
                ) {
                    $sql->insert("sis_access_implications", [
                    "id" => EntityId::generate()->value,
                    "ancestor_id" => $id,
                    "descendant_id" => $id,
                    ]);
                }
            // Deliberately no role/user grants and no broad "sis" implication.
            }
        });
    }
    public function down(): void
    {
        throw new LogicException('Issued offers retain policy references; plan explicit disposition before removal.');
    }
}
