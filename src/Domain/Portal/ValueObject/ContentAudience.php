<?php

declare(strict_types=1);

namespace Domain\Portal\ValueObject;

use Domain\Shared\Exception\ValidationException;
use Domain\Shared\ValueObject\EntityId;

use function Codefy\Framework\Helpers\trans;

/** Explicit role identities, never permission implications or inferred person capabilities. */
final readonly class ContentAudience
{
    /** @var list<string> */
    public array $roles;

    /** @param array<mixed> $roles */
    public function __construct(public string $mode, string $kind, array $roles)
    {
        if (
            !in_array($mode, ['public', 'campus', 'roles'], true) || !array_is_list($roles) ||
            count($roles) > 10 || ($mode === 'roles' && ($kind !== 'news' || $roles === [])) ||
            ($mode !== 'roles' && $roles !== [])
        ) {
            self::invalid();
        }
        $ids = [];
        foreach ($roles as $role) {
            if (!is_string($role) || isset($ids[$role])) {
                self::invalid();
            }
            try {
                $ids[$role] = new EntityId($role)->value;
            } catch (\InvalidArgumentException) {
                self::invalid();
            }
        }
        ksort($ids, SORT_STRING);
        $this->roles = array_values($ids);
    }

    private static function invalid(): never
    {
        throw new ValidationException([['field' => 'audience_roles', 'message' => trans(
            'Choose one to ten distinct account roles for role-targeted news, or clear the roles for another audience.',
        )]]);
    }
}
