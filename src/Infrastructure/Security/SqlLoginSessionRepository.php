<?php

declare(strict_types=1);

namespace Infrastructure\Security;

use Domain\User\Repository\LoginSessionRepository;
use Domain\User\LoginSession;
use Domain\Shared\ValueObject\EntityId;
use Domain\Shared\Exception\ConcurrentModificationException;
use Infrastructure\Persistence\SqlRecords;
use PDOException;

final readonly class SqlLoginSessionRepository implements LoginSessionRepository
{
    public function __construct(private SqlRecords $sql)
    {
    }
    public function forCookie(#[\SensitiveParameter] string $hash): ?LoginSession
    {
        return $this->find(["cookie_hash" => $hash]);
    }
    public function byId(EntityId $id): ?LoginSession
    {
        return $this->find(["id" => $id->value]);
    }
    public function add(LoginSession $session): void
    {
        try {
            $this->sql->insert("sis_login_sessions", [
                "id" => $session->id->value,
                "user_id" => $session->user->value,
                "cookie_hash" => $session->cookieHash,
                "account_revision" => $session->accountRevision,
                "expires_at" => $this->date($session->expiresAt),
                "observed_at" => $this->date($session->observedAt),
                "revoked" => $session->revokedAt === null ? 0 : 1,
                "revoked_at" => $session->revokedAt === null ? null : $this->date($session->revokedAt),
            ]);
        } catch (PDOException) {
            throw new ConcurrentModificationException("The sign-in reference could not be saved.");
        }
    }
    public function revoke(EntityId $id, int $at): void
    {
        $this->sql->update(
            "sis_login_sessions",
            ["revoked" => 1, "revoked_at" => $this->date($at)],
            ["id" => $id->value, "revoked" => 0],
        );
    }
    /** @param array<string,string> $criteria */
    private function find(array $criteria): ?LoginSession
    {
        $row = $this->sql->rows("sis_login_sessions", $criteria, 1)[0] ?? null;
        if ($row === null) {
            return null;
        }
        if (
            !in_array((string) $row["revoked"], ["0", "1"], true) ||
            ((int) $row["revoked"] === 0 && $row["revoked_at"] !== null)
        ) {
            throw new \RuntimeException("Invalid stored sign-in state.");
        }
        return new LoginSession(
            new EntityId($row["id"]),
            new EntityId($row["user_id"]),
            $row["cookie_hash"],
            $row["account_revision"],
            $this->timestamp($row["expires_at"]),
            $this->timestamp($row["observed_at"]),
            (int) $row["revoked"] === 1 ? $this->timestamp($row["revoked_at"]) : null,
        );
    }
    private function date(int $at): string
    {
        return new \DateTimeImmutable("@" . $at)->format("Y-m-d\TH:i:s\Z");
    }
    private function timestamp(mixed $value): int
    {
        if (!is_string($value)) {
            throw new \RuntimeException("Invalid stored sign-in time.");
        }
        $date = \DateTimeImmutable::createFromFormat("!Y-m-d\TH:i:s\Z", $value, new \DateTimeZone("UTC"));
        if ($date === false || $date->format("Y-m-d\TH:i:s\Z") !== $value) {
            throw new \RuntimeException("Invalid stored sign-in time.");
        }
        return $date->getTimestamp();
    }
}
