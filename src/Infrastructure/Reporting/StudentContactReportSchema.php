<?php

declare(strict_types=1);

namespace Infrastructure\Reporting;

use LogicException;

use function Codefy\Framework\Helpers\trans;

/** Approved current contact data only; never expose arbitrary account columns or profile payloads. */
final class StudentContactReportSchema
{
    public const string PERMISSION = 'sis:reporting:contact';

    public static function extend(array $datasets): array
    {
        foreach (['students', 'programs', 'applications', 'credits', 'accounts'] as $id) {
            if (($datasets[$id]['relationships']['person']['table'] ?? null) !== 'sis_persons') {
                throw new LogicException('The contact source requires the canonical person relationship.');
            }
            $datasets[$id]['relationships']['account_contact'] = [
                'table' => 'users', 'parent' => 'person', 'left' => 'owner_user_id', 'right' => 'user_id',
                'permissions' => [self::PERMISSION], 'label' => trans('Linked account contact'),
            ];
            $datasets[$id]['fields']['current_account_email'] = [
                'label' => trans('Current account email'), 'type' => 'string', 'relation' => 'account_contact',
                'column' => 'email', 'permissions' => [self::PERMISSION],
            ];
        }
        return $datasets;
    }
}
