<?php

declare(strict_types=1);

namespace Infrastructure\Persistence\Repository;

use Domain\Registration\Repository\GraduateRegistrationPolicyState;
use Domain\Registration\GraduateRegistrationPolicy;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Infrastructure\Persistence\SqlRecords;
use Qubus\Exception\Exception;

use function Codefy\Framework\Helpers\trans_html;

final readonly class SqlGraduateRegistrationPolicyState implements GraduateRegistrationPolicyState
{
    public function __construct(private SqlRecords $sql)
    {
    }
    public function current(): int
    {
        $row =
        $this->sql->current("sis_graduate_registration_policy_state", GraduateRegistrationPolicy::ID) ??
        throw new CorruptHistoryException();
        $version = filter_var($row["version"], FILTER_VALIDATE_INT);
        if ($version === false || $version < 0 || $version > 2000) {
            throw new CorruptHistoryException();
        }
        return $version;
    }
    /**
     * @throws Exception
     */
    public function advance(int $expected): void
    {
        if (
            $this->sql->update(
                "sis_graduate_registration_policy_state",
                ["version" => $expected + 1],
                ["id" => GraduateRegistrationPolicy::ID, "version" => $expected],
            ) !== 1
        ) {
            throw new ConcurrentModificationException(
                trans_html("The institutional graduate-course registration policy changed. Review again."),
            );
        }
    }
}
