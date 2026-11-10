<?php

declare(strict_types=1);

namespace Infrastructure\Persistence\Repository;

use DomainException;
use Domain\Admissions\AdmissionApplication;
use Domain\Admissions\Enum\ApplicationStatus;
use Domain\Admissions\Repository\AdmissionsRepository;
use Domain\Person\Exception\InstitutionalIdUnavailableException;
use Domain\Person\Person;
use Domain\Person\ValueObject\InstitutionalId;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\RecordNotFoundException;
use Domain\Shared\ValueObject\EntityId;
use Domain\Student\Student;
use Domain\Student\StudentProgram;
use Infrastructure\Persistence\PortableTransactions;
use Infrastructure\Persistence\SqlRecords;
use PDO;
use PDOException;
use Qubus\Expressive\Database;

final readonly class PdoAdmissionsRepository implements AdmissionsRepository
{
    public function __construct(private Database $db)
    {
    }

    public function addPerson(Person $person): void
    {
        if ($person->ownerUserId !== null) {
            $this->record("users", "user_id", $person->ownerUserId);
        }
        PortableTransactions::run($this->db, function () use ($person): void {
            if ($person->ownerUserId !== null) {
                $sql = new SqlRecords($this->db);
                $q = $sql->quote(...);
                $sql->execute(
                    "UPDATE " .
                        $q("users") .
                        " SET " .
                        $q("user_id") .
                        " = " .
                        $q("user_id") .
                        " WHERE " .
                        $q("user_id") .
                        " = ?",
                    [$person->ownerUserId->value],
                );
                if ($sql->rows("sis_persons", ["owner_user_id" => $person->ownerUserId->value], 1) !== []) {
                    throw new DomainException("This sign-in account already has a person record.");
                }
            }
            $this->insert("sis_persons", [
                "id" => $person->id->value,
                "owner_user_id" => $person->ownerUserId?->value,
                "given_name" => $person->givenName,
                "family_name" => $person->familyName,
                "directory_restricted" => (int) $person->directoryRestricted,
            ]);
            try {
                $this->insert("sis_person_identifiers", [
                    "id" => EntityId::generate()->value,
                    "person_id" => $person->id->value,
                    "institutional_id" => $person->institutionalId->value,
                    "version" => $person->identityVersion,
                ]);
            } catch (PDOException $error) {
                $this->identityConstraint($error);
            }
        });
    }

    public function person(EntityId $id): Person
    {
        $row = $this->record("sis_persons", "id", $id);
        $identity = new SqlRecords($this->db)->one("sis_person_identifiers", ["person_id" => $id->value]);

        return new Person(
            $id,
            $row["owner_user_id"] !== null ? new EntityId($row["owner_user_id"]) : null,
            $row["given_name"],
            $row["family_name"],
            new InstitutionalId($identity["institutional_id"]),
            (bool) $row["directory_restricted"],
            (int) $identity["version"],
        );
    }

    public function personByInstitutionalId(InstitutionalId $id): Person
    {
        $identity = new SqlRecords($this->db)->one("sis_person_identifiers", [
            "institutional_id" => $id->value,
        ]);

        return $this->person(new EntityId($identity["person_id"]));
    }

    public function saveInstitutionalId(Person $person, int $expectedVersion): void
    {
        if ($person->identityVersion !== $expectedVersion + 1) {
            throw new ConcurrentModificationException("Identity updates must advance their version once.");
        }
        try {
            $changed = new SqlRecords($this->db)->update(
                "sis_person_identifiers",
                [
                    "institutional_id" => $person->institutionalId->value,
                    "version" => $person->identityVersion,
                ],
                ["person_id" => $person->id->value, "version" => $expectedVersion],
            );
        } catch (PDOException $error) {
            $this->identityConstraint($error);
        }
        if ($changed !== 1) {
            throw new ConcurrentModificationException("The person identity changed. Reload before editing.");
        }
    }

    private function identityConstraint(PDOException $error): never
    {
        if (in_array((string) $error->getCode(), ["23000", "23505"], true)) {
            // Keep driver errors and identifier values out of client responses and logs.
            throw new InstitutionalIdUnavailableException("The institutional ID is already assigned.");
        }
        throw $error;
    }

    public function addApplication(AdmissionApplication $application): void
    {
        $this->record("sis_persons", "id", $application->personId);
        $this->record("sis_programs", "id", $application->programId);
        $this->record("sis_terms", "id", $application->entryTermId);
        try {
            $this->insert("sis_applications", [
                "id" => $application->id->value,
                "person_id" => $application->personId->value,
                "program_id" => $application->programId->value,
                "entry_term_id" => $application->entryTermId->value,
                "status" => $application->status->value,
                "version" => $application->version,
            ]);
        } catch (PDOException $error) {
            if (in_array((string) $error->getCode(), ["23000", "23505"], true)) {
                throw new ConcurrentModificationException(
                    "This application identity is unavailable. Start a new application.",
                );
            }
            throw $error;
        }
    }

    public function application(EntityId $id): AdmissionApplication
    {
        $row = $this->record("sis_applications", "id", $id);

        return new AdmissionApplication(
            $id,
            new EntityId($row["person_id"]),
            new EntityId($row["program_id"]),
            new EntityId($row["entry_term_id"]),
            ApplicationStatus::from($row["status"]),
            (int) $row["version"],
        );
    }

    public function saveDecision(AdmissionApplication $application, int $expectedVersion): void
    {
        if (
            !in_array(
                $application->status,
                [ApplicationStatus::Accepted, ApplicationStatus::Rejected],
                true,
            ) ||
            $application->version !== $expectedVersion + 1
        ) {
            throw new DomainException("An application decision must advance its version once.");
        }
        $this->transition($application, $expectedVersion, ApplicationStatus::Submitted, $application->status);
    }

    public function lockPerson(EntityId $id): void
    {
        $this->lock("sis_persons", $id);
    }
    public function lockApplication(EntityId $id): void
    {
        $this->lock("sis_applications", $id);
    }
    private function lock(string $table, EntityId $id): void
    {
        $sql = new SqlRecords($this->db);
        $q = $sql->quote(...);
        $sql->execute(
            "UPDATE " . $q($table) . " SET " . $q("id") . " = " . $q("id") . " WHERE " . $q("id") . " = ?",
            [$id->value],
        );
        $sql->one($table, ["id" => $id->value]);
    }
    public function studentForPerson(EntityId $person): ?EntityId
    {
        $r = new SqlRecords($this->db)->rows("sis_students", ["person_id" => $person->value], 1)[0] ?? null;
        return $r === null ? null : new EntityId($r["id"]);
    }
    public function projectMatriculation(
        AdmissionApplication $application,
        Student $student,
        StudentProgram $assignment,
    ): void {
        if (
            $application->status !== ApplicationStatus::Accepted ||
            !$student->active ||
            $student->personId->value !== $application->personId->value ||
            $student->programId->value !== $application->programId->value ||
            $student->entryTermId->value !== $application->entryTermId->value ||
            $assignment->studentId->value !== $student->id->value ||
            $assignment->programId->value !== $student->programId->value ||
            $assignment->entryTermId->value !== $student->entryTermId->value ||
            $assignment->status !== "active"
        ) {
            throw new DomainException("The student and first program must inherit the accepted application.");
        }
        $this->transition(
            $application,
            $application->version,
            ApplicationStatus::Accepted,
            ApplicationStatus::Matriculated,
        );
        $this->insert("sis_students", [
            "id" => $student->id->value,
            "person_id" => $student->personId->value,
            "entry_term_id" => $student->entryTermId->value,
            "active" => 1,
            "primary_program_id" => $student->programId->value,
        ]);
        $this->insert("sis_student_programs", [
            "id" => $assignment->id->value,
            "student_id" => $student->id->value,
            "program_id" => $student->programId->value,
            "entry_term_id" => $student->entryTermId->value,
            "status" => "active",
        ]);
    }

    public function student(EntityId $id): Student
    {
        $student = $this->record("sis_students", "id", $id);

        return new Student(
            $id,
            new EntityId($student["person_id"]),
            new EntityId($student["primary_program_id"]),
            new EntityId($student["entry_term_id"]),
            (bool) $student["active"],
        );
    }

    private function transition(
        AdmissionApplication $application,
        int $version,
        ApplicationStatus $from,
        ApplicationStatus $to,
    ): void {
        $statement = $this->db
            ->getConnection()
            ->pdo->prepare(
                sprintf(
                    "UPDATE %s SET %s = ?, %s = ? WHERE %s = ? AND %s = ? AND %s = ?",
                    $this->quote("sis_applications"),
                    $this->quote("status"),
                    $this->quote("version"),
                    $this->quote("id"),
                    $this->quote("version"),
                    $this->quote("status"),
                ),
            );
        $statement->execute([$to->value, $version + 1, $application->id->value, $version, $from->value]);
        if ($statement->rowCount() !== 1) {
            throw new ConcurrentModificationException("The application changed. Reload before trying again.");
        }
    }

    /** @return array<string, mixed> */
    private function record(string $table, string $key, EntityId $id): array
    {
        $statement = $this->db
            ->getConnection()
            ->pdo->prepare(
                sprintf("SELECT * FROM %s WHERE %s = ?", $this->quote($table), $this->quote($key)),
            );
        $statement->execute([$id->value]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            throw new RecordNotFoundException("Record not found.");
        }

        return $row;
    }

    /** @param array<string, int|string|null> $data */
    private function insert(string $table, array $data): void
    {
        $statement = $this->db
            ->getConnection()
            ->pdo->prepare(
                sprintf(
                    "INSERT INTO %s (%s) VALUES (%s)",
                    $this->quote($table),
                    implode(", ", array_map($this->quote(...), array_keys($data))),
                    implode(", ", array_fill(0, count($data), "?")),
                ),
            );
        $statement->execute(array_values($data));
    }

    private function quote(string $identifier): string
    {
        return $this->db->getConnection()->quoteIdentifier($identifier);
    }
}
