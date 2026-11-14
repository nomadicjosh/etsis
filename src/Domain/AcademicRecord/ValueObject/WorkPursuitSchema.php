<?php

declare(strict_types=1);

namespace Domain\AcademicRecord\ValueObject;

use Domain\Shared\ValueObject\EntityId;
use InvalidArgumentException;

final class WorkPursuitSchema
{
    /** @param array<string,mixed> $payload */
    public static function validate(array $payload): void
    {
        $keys = ['decision_id', 'previous_decision_id', 'action', 'source', 'assignment_id',
            'academic_version', 'work_hash'];
        if (
            count($payload) !== count($keys) || array_diff(array_keys($payload), $keys) !== [] ||
            !in_array(
                $payload['action'],
                ['attribute', 'correct_attribution', 'approve_reuse', 'revoke_reuse'],
                true
            ) ||
            !is_int($payload['academic_version']) || $payload['academic_version'] < 0 ||
            $payload['academic_version'] > 2147483646 || !is_string($payload['work_hash']) ||
            preg_match('/^[a-f0-9]{64}$/D', $payload['work_hash']) !== 1 || !is_array($payload['source'])
        ) {
            throw new InvalidArgumentException('Use a complete academic-work pursuit decision.');
        }
        $source = $payload['source'];
        $fields = ['student_id', 'credit_id', 'origin', 'source_id'];
        if (
            count($source) !== 4 || array_diff(array_keys($source), $fields) !== [] ||
            !in_array($source['origin'], ['institutional', 'transfer'], true)
        ) {
            throw new InvalidArgumentException('Use a consistent academic source.');
        }
        foreach (['student_id', 'credit_id', 'source_id'] as $key) {
            self::id($source[$key]);
        }
        self::id($payload['decision_id']);
        self::id($payload['assignment_id']);
        if ($payload['action'] === 'attribute') {
            if ($payload['previous_decision_id'] !== null) {
                throw new InvalidArgumentException('An initial attribution has no previous pursuit decision.');
            }
        } else {
            self::id($payload['previous_decision_id']);
            if ($payload['previous_decision_id'] === $payload['decision_id']) {
                throw new InvalidArgumentException('Retain a distinct predecessor decision.');
            }
        }
    }

    private static function id(mixed $id): void
    {
        if (!is_string($id)) {
            throw new InvalidArgumentException('Use canonical academic identities.');
        }
        new EntityId($id);
    }
}
