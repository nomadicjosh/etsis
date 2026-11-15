<?php

declare(strict_types=1);

use Qubus\Expressive\Migration\Migration;
use Qubus\Expressive\Schema\CreateTable;

final class CreateSisAccountSetup extends Migration
{
    public function up(): void
    {
        $this->schema()->create('sis_account_setup', static function (CreateTable $t): void {
            $t->string('id', 26)->primary()->notNull();
            $t->string('person_id', 26)->notNull()->unique('sas_person');
            $t->string('user_id', 36)->notNull()->unique('sas_user');
            $t->string('token_hash', 64)->notNull()->unique('sas_token');
            $t->string('credential_hash', 64)->notNull();
            $t->string('expires_at', 27)->notNull();
            $t->string('requested_at', 27)->notNull();
            $t->string('consumed_at', 27);
            $t->string('state', 12)->notNull();
            $t->text('encrypted_notice')->size('medium');
            $t->text('sealed_grant')->notNull();
            $t->index(['state', 'id'], 'sas_pending');
            $t->foreign('person_id', 'sas_person_fk')->references('sis_persons', 'id');
            $t->foreign('user_id', 'sas_user_fk')->references('users', 'user_id');
        });
    }
    public function down(): void
    {
        throw new LogicException('Account setup records require explicit disposition.');
    }
}
