<?php

declare(strict_types=1);

namespace Infrastructure\Persistence\Repository;

use Codefy\Framework\Auth\Repository\AuthUserRepository;
use Codefy\Framework\Auth\UserSession;
use Codefy\Framework\Support\Password;
use Qubus\Config\ConfigContainer;
use Qubus\Exception\Exception;
use Qubus\Expressive\Connection;
use Qubus\Http\Session\SessionEntity;

use function sprintf;

class PdoAuthUserRespository implements AuthUserRepository
{
    public function __construct(private Connection $connection, protected ConfigContainer $config)
    {
    }

    /**
     * @inheritdoc
     * @throws Exception
     */
    public function authenticate(string $credential, #[\SensitiveParameter] ?string $password = null): ?SessionEntity
    {
        /** @var array{identity: string, role: string, token: string, password: string} $fields */
        $fields = $this->config->getConfigKey(key: 'auth.pdo.fields');

        /** @var string $table */
        $table = $this->config->getConfigKey('auth.pdo.table');

        $sql = sprintf(
            "SELECT * FROM %s WHERE %s = :identity",
            $table,
            $fields['identity']
        );

        $stmt = $this->connection->pdo->prepare($sql);
        if (false === $stmt) {
            return null;
        }

        $stmt->bindParam(':identity', $credential);
        $stmt->execute();

        /** @var object{'token': string|null, 'password': string}|false $result */
        $result = $stmt->fetchObject();
        if (! $result) {
            return null;
        }

        /** @var string $passwordHash */
        $passwordHash = ($result->{$fields['password']} ?? '');

        if (Password::verify(password: $password ?? '', hash: $passwordHash)) {
            $token = $result->{$fields['token']} ?? null;
            if (!is_string($token) || $token === '') {
                return null;
            }

            if (Password::needsRehash($passwordHash)) {
                // Compare the old hash too, so a concurrent password change is never overwritten.
                $update = $this->connection->pdo->prepare(sprintf(
                    'UPDATE %s SET %s = :hash WHERE %s = :identity AND %s = :old_hash',
                    $table,
                    $fields['password'],
                    $fields['identity'],
                    $fields['password']
                ));
                $update->execute([
                    'hash' => Password::hash($password ?? ''),
                    'identity' => $credential,
                    'old_hash' => $passwordHash,
                ]);
            }

            $user = new UserSession();
            $user
                ->withToken($token);

            return $user;
        }

        return null;
    }

    /**
     * @inheritDoc
     * @throws Exception
     */
    public function find(string $token): bool|null|object
    {
        /** @var string $table */
        $table = $this->config->getConfigKey('auth.pdo.table');
        $tokenField = $this->config->string('auth.pdo.fields.token', 'token');
        $sql = sprintf(
            "SELECT * FROM %s WHERE %s = :token",
            $table,
            $tokenField,
        );

        $stmt = $this->connection->pdo->prepare($sql);
        if (false === $stmt) {
            return null;
        }

        $stmt->bindParam(':token', $token);
        $stmt->execute();

        return $stmt->fetchObject();
    }
}
