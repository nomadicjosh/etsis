<?php

declare(strict_types=1);

namespace Tests\Integration;

use Application\Service\DatabaseService;
use Domain\Shared\ValueObject\EntityId;
use Domain\User\Query\FindUserByEmailQuery;
use Domain\User\Query\FindUserByEmailQueryHandler;
use Domain\User\Query\FindUserByIdQuery;
use Domain\User\Query\FindUserByIdQueryHandler;
use Domain\User\Query\FindUserByTokenQuery;
use Domain\User\Query\FindUserByTokenQueryHandler;
use Domain\User\ValueObject\UserId;
use Domain\User\ValueObject\UserToken;
use Infrastructure\Persistence\SqliteConnection;
use PHPUnit\Framework\TestCase;
use Qubus\ValueObjects\Web\EmailAddress;

final class UserQueryIsolationTest extends TestCase
{
    public function testIdentityLookupsKeepTheirUserKeyWithoutChangingLaterDomainWrites(): void
    {
        $connection = new SqliteConnection(['driver' => 'pdo_sqlite', 'dsn' => 'sqlite::memory:']);
        $connection->pdo->exec('CREATE TABLE users (user_id VARCHAR(26) PRIMARY KEY, username TEXT, '
            . 'first_name TEXT, middle_name TEXT, last_name TEXT, email TEXT, role TEXT, token TEXT, '
            . 'sign_in_enabled INTEGER)');
        $connection->pdo->exec('CREATE TABLE fictional_records (id VARCHAR(26) PRIMARY KEY, title TEXT)');
        $id = EntityId::generate()->value;
        $token = new UserToken();
        $query = $connection->pdo->prepare('INSERT INTO users VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1)');
        $query->execute([$id, 'fictional_reader', 'Fictional', '', 'Reader', 'reader@example.invalid',
            'user', $token->toNative()]);
        $db = DatabaseService::fromInstance($connection);
        foreach (
            [
            [new FindUserByEmailQueryHandler($db), new FindUserByEmailQuery([
                'email' => new EmailAddress('reader@example.invalid'),
            ])],
            [new FindUserByIdQueryHandler($db), new FindUserByIdQuery(['userId' => UserId::fromString($id)])],
            [new FindUserByTokenQueryHandler($db), new FindUserByTokenQuery(['token' => $token])],
            ] as [$handler, $request]
        ) {
            $found = $handler->handle($request);
            self::assertNotFalse($found);
            self::assertSame('user_id', $found->getPrimaryKeyname());
            self::assertSame('id', $db->getPrimaryKeyname());
            $record = EntityId::generate()->value;
            $db->table('fictional_records')->insert(['id' => $record, 'title' => 'Fictional independent record']);
            $check = $connection->pdo->prepare('SELECT COUNT(*) FROM fictional_records WHERE id=?');
            $check->execute([$record]);
            self::assertSame(1, (int) $check->fetchColumn());
        }
    }
}
