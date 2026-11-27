<?php

declare(strict_types=1);

namespace Infrastructure\Persistence;

use Domain\Shared\ValueObject\EntityId;

/** Trusted p/a aliases; filter membership before paging and never treat Super as an audience bypass. */
final readonly class SqlContentAudience
{
    public function __construct(private SqlRecords $sql)
    {
    }

    /** @return array{sql:string,parameters:list<string>} */
    public function predicate(?EntityId $owner, bool $announcements = false): array
    {
        $q = $this->sql->quote(...);
        $audience = 'a.' . $q('audience');
        if ($owner === null) {
            return ['sql' => $audience . " = 'public'", 'parameters' => []];
        }
        $ordinary = $announcements ? $audience . " = 'campus'"
        : '(' . $audience . " IN ('public', 'campus') OR " . $audience . ' IS NULL)';
        $roles = 'EXISTS (SELECT 1 FROM ' . $q('sis_portal_audience_roles') . ' targets JOIN '
        . $q('sis_access_user_roles') . ' membership ON membership.' . $q('role_id') . ' = targets.'
        . $q('role_id') . ' JOIN ' . $q('sis_access_roles') . ' roles ON roles.' . $q('id')
        . ' = targets.' . $q('role_id') . ' WHERE targets.' . $q('post_id') . ' = p.' . $q('id')
        . ' AND membership.' . $q('user_id') . ' = reader.' . $q('user_id') . ')';
        $programs = 'EXISTS (SELECT 1 FROM ' . $q('sis_portal_audience_programs') . ' targets JOIN '
        . $q('sis_programs') . ' programs ON programs.' . $q('id') . ' = targets.' . $q('program_id')
        . ' JOIN ' . $q('sis_student_programs') . ' pursuit ON pursuit.' . $q('program_id')
        . ' = programs.' . $q('id') . ' JOIN ' . $q('sis_students') . ' student ON student.' . $q('id')
        . ' = pursuit.' . $q('student_id') . ' JOIN ' . $q('sis_persons') . ' person ON person.' . $q('id')
        . ' = student.' . $q('person_id') . ' WHERE targets.' . $q('post_id') . ' = p.' . $q('id')
        . ' AND person.' . $q('owner_user_id') . ' = reader.' . $q('user_id')
        . ' AND student.' . $q('active') . " = 1 AND pursuit." . $q('status') . " = 'active')";
        $member = 'EXISTS (SELECT 1 FROM ' . $q('users') . ' reader WHERE reader.' . $q('user_id')
        . ' = ? AND reader.' . $q('sign_in_enabled') . ' = 1 AND (('
        . $audience . " = 'roles' AND " . $roles . ') OR (' . $audience . " = 'programs' AND " . $programs . ')))';
        return ['sql' => '(' . $ordinary . ' OR ' . $member . ')', 'parameters' => [$owner->value]];
    }
}
