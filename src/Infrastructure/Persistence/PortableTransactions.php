<?php

declare(strict_types=1);

namespace Infrastructure\Persistence;

use Closure;
use Domain\Shared\Exception\TransactionAbortedException;
use PDO;
use Qubus\Expressive\Database;
use Throwable;
use WeakMap;

/** Joined transactions avoid driver-specific savepoint and release syntax. */
final class PortableTransactions
{
    /** @var WeakMap<PDO, TransactionScope>|null */
    private static ?WeakMap $scopes = null;

    public static function run(Database $db, Closure $callback): mixed
    {
        $pdo = $db->getConnection()->pdo;
        self::$scopes ??= new WeakMap();
        $scope = self::$scopes[$pdo] ?? null;
        if ($scope !== null) {
            try {
                return $callback();
            } catch (Throwable $error) {
                $scope->rollbackOnly = true;
                throw $error;
            }
        }
        $owned = !$pdo->inTransaction();
        $scope = self::$scopes[$pdo] = new TransactionScope();
        try {
            if ($owned) {
                $pdo->beginTransaction();
            }
            $result = $callback();
            if ($scope->rollbackOnly) {
                throw new TransactionAbortedException('A nested operation failed; the transaction was not committed.');
            }
            if ($owned) {
                $pdo->commit();
            }

            return $result;
        } catch (Throwable $error) {
            // If an external caller owns the transaction, it remains responsible for its rollback.
            if ($owned) {
                self::rollBackIfActive($pdo);
            }
            throw $error;
        } finally {
            unset(self::$scopes[$pdo]);
        }
    }

    private static function rollBackIfActive(PDO $pdo): void
    {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
    }
}
