<?php

declare(strict_types=1);

use Qubus\Expressive\Migration\Migration;
use Qubus\Expressive\Migration\Adapter\ConnectionAwareMigrationAdapter;
use Qubus\Expressive\Schema\AlterTable;

final class AddSisApplicationFormLayouts extends Migration
{
    public function up(): void
    {
        $adapter = $this->getAdapter();
        if (!$adapter instanceof ConnectionAwareMigrationAdapter) {
            throw new LogicException('A connection-aware form migration adapter is required.');
        }
        $mysql = $adapter->connection()->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
        $this->schema()->alter('sis_application_forms', static function (AlterTable $table) use ($mysql): void {
            $table->text('layout_json');
            if ($mysql) {
                $table->toText('fields_json')->set(name: 'size', value: 'medium')
                    ->set(name: 'nullable', value: false);
            }
        });
        // Other supported engines already use large text/LOB storage for these columns.
        if ($mysql) {
            $this->schema()->alter('sis_form_submissions', static function (AlterTable $table): void {
                $table->toText('encrypted_answers')->set(name: 'size', value: 'medium')
                    ->set(name: 'nullable', value: false);
            });
        }
    }

    public function down(): void
    {
        throw new LogicException('Preserve the layouts of published forms and submitted applications.');
    }
}
