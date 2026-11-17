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
            $row["program_id"] === null ? null : new EntityId($row["program_id"]),
            $row["name"],
            json_decode($row["fields_json"], true, flags: JSON_THROW_ON_ERROR),
            $row["status"],
            (int) $row["version"],
            $row['academic_level_id'] === null ? null : new EntityId($row['academic_level_id']),
        );
    }

    public function active(EntityId $program): ApplicationForm
    {
        $bindings = $this->sql->rows('sis_program_forms', ['program_id' => $program->value], 1);
        if ($bindings !== []) {
            return $this->boundForm($bindings[0]['form_id'], 'program', $program->value);
        }
        $classes = $this->sql->rows('sis_program_details', ['id' => $program->value], 1);
        $level = $classes[0]['academic_level_id'] ?? null;
        if ($level === null) {
            throw new \Domain\Shared\Exception\RecordNotFoundException('No application form is enabled.');
        }
        $binding = $this->sql->one('sis_level_forms', ['academic_level_id' => $level]);
        return $this->boundForm($binding['form_id'], 'academic_level', $level);
    }

    private function boundForm(string $id, string $kind, string $target): ApplicationForm
    {
        $form = $this->get(new EntityId($id));
        if ($form->status !== 'published' || $form->targetKind() !== $kind || $form->targetId()->value !== $target) {
            throw new \Domain\Shared\EventSourcing\CorruptHistoryException();
        }
        return $form;
    }

    public function save(ApplicationForm $form, ?int $expectedVersion): void
    {
        $data = [
            "program_id" => $form->programId?->value,
            'academic_level_id' => $form->academicLevelId?->value,
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
        $level = $form->academicLevelId !== null;
        if ($level) {
            $this->disableLevel($form->targetId());
        } else {
            $this->disable($form->targetId());
        }
        $this->sql->insert($level ? 'sis_level_forms' : 'sis_program_forms', [
            "id" => EntityId::generate()->value,
            ($level ? 'academic_level_id' : 'program_id') => $form->targetId()->value,
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

    public function lockProgram(EntityId $program): void
    {
        $this->sql->lock("sis_programs", $program->value, "id");
    }

    public function lockLevel(EntityId $level): void
    {
        $this->sql->lock('sis_catalog_classifications', $level->value, 'id');
        $this->sql->one('sis_catalog_classifications', ['id' => $level->value, 'kind' => 'academic_level']);
    }

    public function disableLevel(EntityId $level): void
    {
        $this->sql->execute('DELETE FROM ' . $this->sql->quote('sis_level_forms') . ' WHERE '
            . $this->sql->quote('academic_level_id') . ' = ?', [$level->value]);
    }

    public function levels(): array
    {
        $q = $this->sql->quote(...);
        return $this->sql->fetch('SELECT ' . $q('id') . ', ' . $q('code') . ', ' . $q('title')
            . ' FROM ' . $q('sis_catalog_classifications') . ' WHERE ' . $q('kind') . ' = ? AND '
            . $q('active') . ' = ? ORDER BY ' . $q('code'), ['academic_level', 1], 100);
    }

    public function listing(): array
    {
        $q = $this->sql->quote(...);
        return $this->sql->fetch(
            'SELECT f.' . $q('id') . ', f.' . $q('name') . ', f.' . $q('program_id')
            . ', f.' . $q('academic_level_id') . ', f.' . $q('status') . ', f.' . $q('version')
            . ', COALESCE(p.' . $q('code') . ', c.' . $q('title') . ') AS ' . $q('program')
            . ', COALESCE(b.' . $q('form_id') . ', l.' . $q('form_id') . ') AS ' . $q('enabled_id')
            . ' FROM ' . $q('sis_application_forms') . ' f LEFT JOIN ' . $q('sis_programs') . ' p ON p.'
            . $q('id') . ' = f.' . $q('program_id') . ' LEFT JOIN ' . $q('sis_catalog_classifications')
            . ' c ON c.' . $q('id') . ' = f.' . $q('academic_level_id') . ' LEFT JOIN '
            . $q('sis_program_forms') . ' b ON b.' . $q('form_id') . ' = f.' . $q('id') . ' LEFT JOIN '
            . $q('sis_level_forms') . ' l ON l.' . $q('form_id') . ' = f.' . $q('id')
            . ' ORDER BY f.' . $q('id') . ' DESC',
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
        try {
            $this->sql->insert("sis_form_submissions", [
                "id" => $id->value,
                "form_id" => $form->value,
                "application_id" => $application->value,
                "owner_user_id" => $owner->value,
                "encrypted_answers" => $encrypted,
                "fingerprint" => $fingerprint,
            ]);
        } catch (\PDOException $error) {
            if (in_array((string) $error->getCode(), ["23000", "23505"], true)) {
                throw new \Domain\Shared\Exception\ConcurrentModificationException(
                    "This submission identity is unavailable. Start a new submission.",
                );
            }
            throw $error;
        }
    }
}
