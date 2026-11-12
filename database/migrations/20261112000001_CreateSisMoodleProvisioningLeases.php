<?php
declare(strict_types=1);
use Qubus\Expressive\Migration\Migration;
use Qubus\Expressive\Schema\AlterTable;
final class CreateSisMoodleProvisioningLeases extends Migration
{
    public function up(): void
    {
        $this->schema()->alter("sis_moodle_provisioning_outbox", static function (AlterTable $t): void {
            $t->integer("lease_until")->notNull()->defaultValue(0);
            $t->integer("attempts")->notNull()->defaultValue(0);
            $t->string("issue_code", 32)->notNull()->defaultValue("none");
        });
    }
    public function down(): void
    {
        throw new LogicException("Retain provisioning outcomes and retry claims.");
    }
}
