<?php

declare(strict_types=1);

namespace Domain\Portal\ValueObject;

use Domain\Shared\Exception\ValidationException;
use Domain\Shared\ValueObject\EntityId;

use function Codefy\Framework\Helpers\trans;

/** Explicit recipient identities; roles and active student pursuits are independent audience choices. */
final readonly class ContentAudience
{
    /** @var list<string> */
    public array $roles;
    /** @var list<string> */
    public array $programs;

    /** @param array<mixed> $roles
     * @param array<mixed> $programs
     */
    public function __construct(public string $mode, string $kind, array $roles, array $programs = [])
    {
        if (
            !array_is_list($programs) || count($programs) > 10 ||
            ($mode === 'programs' && ($kind !== 'news' || $programs === [])) ||
            ($mode !== 'programs' && $programs !== [])
        ) {
            self::invalidPrograms();
        }
        if (
            !in_array($mode, ['public', 'campus', 'roles', 'programs'], true) || !array_is_list($roles) ||
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
        $ids = [];
        foreach ($programs as $program) {
            if (!is_string($program) || isset($ids[$program])) {
                self::invalidPrograms();
            }
            try {
                $ids[$program] = new EntityId($program)->value;
            } catch (\InvalidArgumentException) {
                self::invalidPrograms();
            }
        }
        ksort($ids, SORT_STRING);
        $this->programs = array_values($ids);
    }

    private static function invalidPrograms(): never
    {
        throw new ValidationException([['field' => 'audience_programs', 'message' => trans(
            'Choose one to ten distinct programs for program-targeted news, '
            . 'or clear the programs for another audience.',
        )]]);
    }

    private static function invalid(): never
    {
        throw new ValidationException([['field' => 'audience_roles', 'message' => trans(
            'Choose one to ten distinct account roles for role-targeted news, or clear the roles for another audience.',
        )]]);
    }
}
