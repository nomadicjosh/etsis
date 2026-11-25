<?php

declare(strict_types=1);

use Domain\Student\StandingProposalPolicy;
use Infrastructure\Persistence\PortableTransactions;
use Infrastructure\Persistence\SqlRecords;
use Qubus\Expressive\Migration\Adapter\ConnectionAwareMigrationAdapter;
use Qubus\Expressive\Migration\Migration;
use Qubus\Expressive\Schema\CreateTable;

final class AddSisStandingProposalPolicy extends Migration
{
    public function up(): void
    {
        if (!$this->schema()->hasTable('sis_standing_proposal_policy_state', clear: true)) {
            $this->schema()->create('sis_standing_proposal_policy_state', self::define(...));
        }
        $adapter = $this->getAdapter();
        if (!($adapter instanceof ConnectionAwareMigrationAdapter)) {
            throw new LogicException('Standing proposal policy requires a database connection.');
        }
        $db = $adapter->connection()->queryBuilder();
        PortableTransactions::run($db, static function () use ($db): void {
            $sql = new SqlRecords($db);
            if ($sql->rows('sis_standing_proposal_policy_state', ['id' => StandingProposalPolicy::ID], 1) === []) {
                $sql->insert('sis_standing_proposal_policy_state', [
                    'id' => StandingProposalPolicy::ID, 'version' => 0,
                ]);
            }
        });
    }

    public static function define(CreateTable $t): void
    {
        $t->string('id', 26)->primary()->notNull();
        $t->integer('version')->notNull();
    }

    public function down(): void
    {
        throw new LogicException('Preserve published standing policies and their technical revision state.');
    }
}
