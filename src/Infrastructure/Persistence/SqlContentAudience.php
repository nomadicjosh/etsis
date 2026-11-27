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
        $member = 'EXISTS (SELECT 1 FROM ' . $q('sis_portal_audience_roles') . ' targets JOIN '
        . $q('sis_access_user_roles') . ' membership ON membership.' . $q('role_id') . ' = targets.'
        . $q('role_id') . ' JOIN ' . $q('sis_access_roles') . ' roles ON roles.' . $q('id')
        . ' = targets.' . $q('role_id') . ' JOIN ' . $q('users') . ' reader ON reader.' . $q('user_id')
        . ' = membership.' . $q('user_id') . ' WHERE targets.' . $q('post_id') . ' = p.' . $q('id')
        . ' AND membership.' . $q('user_id') . ' = ? AND reader.' . $q('sign_in_enabled') . ' = 1)';
        return ['sql' => '(' . $ordinary . " OR (" . $audience . " = 'roles' AND " . $member . '))',
            'parameters' => [$owner->value]];
    }
}
