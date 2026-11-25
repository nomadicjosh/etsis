<?php

declare(strict_types=1);

namespace Tests\Support;

use LogicException;
use PDO;

/** Copies installed DDL only into an owned empty database. No application row can enter a fixture. */
final readonly class PostgresQualificationSchema
{
    public function __construct(private PDO $source, private PDO $target)
    {
    }

    public function copy(array $tables): array
    {
        $definitions = $foreign = [];
        $dependencies = array_fill_keys($tables, []);
        $this->target->beginTransaction();
        try {
            foreach ($tables as $table) {
                $this->quote($table);
                if ($table !== 'users' && !preg_match('/^sis_[a-z0-9_]+$/D', $table)) {
                    throw new LogicException('Only the declared SIS fixture tables can be copied.');
                }
                $columns = $this->read('SELECT a.attname,format_type(a.atttypid,a.atttypmod) AS type, '
                    . 'a.attnotnull,a.attidentity,a.attgenerated,pg_get_expr(d.adbin,d.adrelid) AS default_value, '
                    . 'co.collname FROM pg_attribute a JOIN pg_class c ON c.oid=a.attrelid '
                    . 'JOIN pg_namespace n ON n.oid=c.relnamespace LEFT JOIN pg_attrdef d '
                    . 'ON d.adrelid=c.oid AND d.adnum=a.attnum LEFT JOIN pg_collation co ON co.oid=a.attcollation '
                    . "WHERE n.nspname='public' AND c.relname=? AND a.attnum>0 AND NOT a.attisdropped "
                    . 'ORDER BY a.attnum', $table);
                if ($columns === []) {
                    throw new LogicException('An installed table is missing from the native schema.');
                }
                if ($table === 'users') {
                    $id = array_values(array_filter(
                        $columns,
                        static fn (array $c): bool => $c['attname'] === 'user_id'
                    ));
                    if (count($id) !== 1) {
                        throw new LogicException('Use the installed sign-in key definition.');
                    }
                    $this->target->exec('CREATE TABLE users (' . $this->column($id[0]) . ' PRIMARY KEY, '
                        . 'email VARCHAR(191), role VARCHAR(80), first_name VARCHAR(191), last_name VARCHAR(191), '
                        . 'sign_in_enabled INTEGER NOT NULL DEFAULT 1)');
                    continue;
                }
                $this->target->exec('CREATE TABLE ' . $this->quote($table) . ' (' .
                    implode(', ', array_map($this->column(...), $columns)) . ')');
                $definitions[$table] = $this->read('SELECT k.conname,k.contype,pg_get_constraintdef(k.oid) AS ddl, '
                    . 'p.relname AS parent,pn.nspname AS parent_schema FROM pg_constraint k '
                    . 'JOIN pg_class c ON c.oid=k.conrelid JOIN pg_namespace n ON n.oid=c.relnamespace '
                    . 'LEFT JOIN pg_class p ON p.oid=k.confrelid LEFT JOIN pg_namespace pn ON pn.oid=p.relnamespace '
                    . "WHERE n.nspname='public' AND c.relname=? ORDER BY k.conname", $table);
            }
            foreach ($definitions as $table => $constraints) {
                foreach ($constraints as $constraint) {
                    $ddl = 'ALTER TABLE ' . $this->quote($table) . ' ADD CONSTRAINT ' .
                    $this->quote($constraint['conname']) . ' ' . $constraint['ddl'];
                    if ($constraint['contype'] === 'f') {
                        if (
                            $constraint['parent_schema'] !== 'public' ||
                            !array_key_exists($constraint['parent'], $dependencies)
                        ) {
                            throw new LogicException('Foreign keys must remain inside the declared fixture tables.');
                        }
                        $foreign[] = $ddl;
                        if ($constraint['parent'] !== $table) {
                            $dependencies[$table][$constraint['parent']] = true;
                        }
                    } elseif (in_array($constraint['contype'], ['p', 'c'], true)) {
                        $this->target->exec($ddl);
                    } elseif (!in_array($constraint['contype'], ['u', 'n'], true)) {
                        // Unique indexes and column NOT NULL declarations are already copied separately.
                        throw new LogicException('Review an unsupported native constraint.');
                    }
                }
                $indexes = $this->read('SELECT pg_get_indexdef(i.indexrelid) AS ddl FROM pg_index i '
                    . 'JOIN pg_class c ON c.oid=i.indrelid JOIN pg_namespace n ON n.oid=c.relnamespace '
                    . "WHERE n.nspname='public' AND c.relname=? AND NOT i.indisprimary ORDER BY i.indexrelid", $table);
                foreach ($indexes as $index) {
                    $this->target->exec($index['ddl']);
                }
            }
            foreach ($foreign as $ddl) {
                $this->target->exec($ddl);
            }
            $order = self::insertionOrder($dependencies);
            $this->target->commit();
            return $order;
        } catch (\Throwable $error) {
            if ($this->target->inTransaction()) {
                $this->target->rollBack();
            }
            throw $error;
        }
    }

    /** Preserve normal foreign-key enforcement while seeding parents before their children. */
    public static function insertionOrder(array $dependencies): array
    {
        $done = [];
        while ($dependencies !== []) {
            $progress = false;
            foreach ($dependencies as $table => $parents) {
                if (array_diff_key($parents, $done) === []) {
                    $done[$table] = true;
                    unset($dependencies[$table]);
                    $progress = true;
                }
            }
            if (!$progress) {
                throw new LogicException('Cyclic or external fixture dependencies require explicit qualification.');
            }
        }
        return array_keys($done);
    }

    private function column(array $column): string
    {
        if (
            $column['attidentity'] !== '' || $column['attgenerated'] !== '' ||
            str_contains($column['default_value'] ?? '', 'nextval(')
        ) {
            throw new LogicException('Serial or generated SIS columns require explicit native fixture support.');
        }
        $ddl = $this->quote($column['attname']) . ' ' . $column['type'];
        if ($column['collname'] !== null && $column['collname'] !== 'default') {
            throw new LogicException('Native qualification requires ordinary default column collations.');
        }
        if ($column['default_value'] !== null) {
            $ddl .= ' DEFAULT ' . $column['default_value'];
        }
        return $ddl . ($column['attnotnull'] ? ' NOT NULL' : '');
    }

    private function read(string $sql, string $table): array
    {
        $query = $this->source->prepare($sql);
        $query->execute([$table]);
        return $query->fetchAll(PDO::FETCH_ASSOC);
    }

    private function quote(string $name): string
    {
        if (!preg_match('/^[a-z][a-z0-9_]{0,62}$/D', $name)) {
            throw new LogicException('Review an unsupported native identifier.');
        }
        return '"' . $name . '"';
    }
}
