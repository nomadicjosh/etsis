<?php

declare(strict_types=1);

namespace Domain\User\Query;

use Codefy\QueryBus\Query;
use Codefy\QueryBus\QueryHandler;
use Qubus\Expressive\Database;

final readonly class FindUserByIdQueryHandler implements QueryHandler
{
    public function __construct(private Database $db)
    {
    }

    public function handle(FindUserByIdQuery|Query $query): Database|bool
    {
        /** @var FindUserByIdQuery $query */

        return $this->db
            ->table(tableName: 'users')
            ->setStructure(primaryKeyName: 'user_id')
            ->where(condition: 'user_id = ?', parameters: $query->userId->toNative())
            ->findOne();
    }
}
