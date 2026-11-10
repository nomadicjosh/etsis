<?php

declare(strict_types=1);

namespace Infrastructure\Persistence\Repository;

use Domain\Admissions\ApplicationForm;
use Domain\Admissions\Repository\ApplicationFormRepository;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\SqlRecords;

final readonly class SqlApplicationFormRepository implements ApplicationFormRepository
{
    public function __construct(private SqlRecords $sql)
    {
    }

    public function get(EntityId $id): ApplicationForm
    {
        $row = $this->sql->one("sis_application_forms", ["id" => $id->value]);

        return new ApplicationForm(
            $id,
            new EntityId($row["program_id"]),
            $row["name"],
            json_decode($row["fields_json"], true, flags: JSON_THROW_ON_ERROR),
            $row["status"],
            (int) $row["version"],
        );
    }

    public function active(EntityId $program): ApplicationForm
    {
        $binding = $this->sql->one("sis_program_forms", ["program_id" => $program->value]);

        return $this->get(new EntityId($binding["form_id"]));
    }

    public function save(ApplicationForm $form, ?int $expectedVersion): void
    {
        $data = [
            "program_id" => $form->programId->value,
            "name" => $form->name,
            "fields_json" => json_encode($form->fields, JSON_THROW_ON_ERROR),
            "status" => $form->status,
            "version" => $form->version,
        ];
        if ($expectedVersion === null) {
            $this->sql->insert("sis_application_forms", ["id" => $form->id->value] + $data);
        } elseif (
            $this->sql->update("sis_application_forms", $data, [
                "id" => $form->id->value,
                "version" => $expectedVersion,
                "status" => "draft",
            ]) !== 1
        ) {
            throw new ConcurrentModificationException("This application form has changed.");
        }
    }

    public function publish(ApplicationForm $form): void
    {
        if (
            $this->sql->update(
                "sis_application_forms",
                ["status" => "published", "version" => $form->version + 1],
                ["id" => $form->id->value, "version" => $form->version, "status" => "draft"],
            ) !== 1
        ) {
            throw new ConcurrentModificationException("This application form has changed.");
        }
        $this->disable($form->programId);
        $this->sql->insert("sis_program_forms", [
            "id" => EntityId::generate()->value,
            "program_id" => $form->programId->value,
            "form_id" => $form->id->value,
        ]);
    }

    public function disable(EntityId $program): void
    {
        $this->sql->execute(
            "DELETE FROM " .
                $this->sql->quote("sis_program_forms") .
                " WHERE " .
                $this->sql->quote("program_id") .
                " = ?",
            [$program->value],
        );
    }

    public function lockPerson(EntityId $person): void
    {
        $q = $this->sql->quote(...);
        $this->sql->execute(
            "UPDATE " .
                $q("sis_persons") .
                " SET " .
                $q("id") .
                " = " .
                $q("id") .
                " WHERE " .
                $q("id") .
                " = ?",
            [$person->value],
        );
        $this->sql->one("sis_persons", ["id" => $person->value]);
    }
    public function lockProgram(EntityId $program): void
    {
        $this->sql->lock("sis_programs", $program->value, "id");
    }

    public function listing(): array
    {
        $q = $this->sql->quote(...);

        return $this->sql->fetch(
            "SELECT f." .
                $q("id") .
                ", f." .
                $q("name") .
                ", f." .
                $q("program_id") .
                ", f." .
                $q("status") .
                ", f." .
                $q("version") .
                ", p." .
                $q("code") .
                " AS " .
                $q("program") .
                ", b." .
                $q("form_id") .
                " AS " .
                $q("enabled_id") .
                " FROM " .
                $q("sis_application_forms") .
                " f JOIN " .
                $q("sis_programs") .
                " p ON p." .
                $q("id") .
                " = f." .
                $q("program_id") .
                " LEFT JOIN " .
                $q("sis_program_forms") .
                " b ON b." .
                $q("form_id") .
                " = f." .
                $q("id") .
                " ORDER BY f." .
                $q("id") .
                " DESC",
            [],
            100,
        );
    }

    public function submission(EntityId $id): ?array
    {
        return $this->sql->rows("sis_form_submissions", ["id" => $id->value], 1)[0] ?? null;
    }

    public function submissionForApplication(EntityId $application): ?array
    {
        return $this->sql->rows("sis_form_submissions", ["application_id" => $application->value], 1)[0] ??
        null;
    }

    public function applicationLabels(EntityId $application): array
    {
        $q = $this->sql->quote(...);
        return $this->sql->fetch(
            "SELECT p." .
                $q("code") .
                " AS " .
                $q("program_code") .
                ", p." .
                $q("title") .
                " AS " .
                $q("program_title") .
                ", t." .
                $q("code") .
                " AS " .
                $q("term_code") .
                " FROM " .
                $q("sis_applications") .
                " a JOIN " .
                $q("sis_programs") .
                " p ON p." .
                $q("id") .
                " = a." .
                $q("program_id") .
                " JOIN " .
                $q("sis_terms") .
                " t ON t." .
                $q("id") .
                " = a." .
                $q("entry_term_id") .
                " WHERE a." .
                $q("id") .
                " = ?",
            [$application->value],
            1,
        )[0];
    }

    public function storeSubmission(
        EntityId $id,
        EntityId $form,
        EntityId $application,
        EntityId $owner,
        string $encrypted,
        string $fingerprint,
    ): void {
        $this->sql->insert("sis_form_submissions", [
            "id" => $id->value,
            "form_id" => $form->value,
            "application_id" => $application->value,
            "owner_user_id" => $owner->value,
            "encrypted_answers" => $encrypted,
            "fingerprint" => $fingerprint,
        ]);
    }
}
