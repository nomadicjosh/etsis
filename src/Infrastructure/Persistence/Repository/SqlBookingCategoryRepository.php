<?php

declare(strict_types=1);

namespace Infrastructure\Persistence\Repository;

use Domain\Scheduling\BookingCategory;
use Domain\Scheduling\Repository\BookingCategoryRepository;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\SqlRecords;
use PDOException;

final readonly class SqlBookingCategoryRepository implements BookingCategoryRepository
{
    public function __construct(private SqlRecords $sql)
    {
    }

    public function get(EntityId $id): BookingCategory
    {
        $row = $this->sql->one('sis_booking_categories', ['id' => $id->value]);
        return new BookingCategory(
            $id,
            $row['code'],
            $row['name'],
            $row['tone'],
            (bool) $row['active'],
            (int) $row['version']
        );
    }

    public function lock(EntityId $id): void
    {
        $this->sql->lock('sis_booking_categories', $id->value, 'version');
    }

    public function add(BookingCategory $category): void
    {
        $this->write($category, null);
    }

    public function save(BookingCategory $category, int $expectedVersion): void
    {
        $this->write($category, $expectedVersion);
    }

    private function write(BookingCategory $category, ?int $expectedVersion): void
    {
        $data = $category->details();
        $data['active'] = (int) $category->active;
        try {
            if ($expectedVersion === null) {
                $this->sql->insert('sis_booking_categories', $data);
            } else {
                unset($data['id']);
                if (
                    $this->sql->update(
                        'sis_booking_categories',
                        $data,
                        ['id' => $category->id->value, 'version' => $expectedVersion]
                    ) !== 1
                ) {
                    throw new ConcurrentModificationException('Reload the category before editing.');
                }
            }
        } catch (PDOException $error) {
            if (in_array((string) $error->getCode(), ['23000', '23505'], true)) {
                throw new ValidationException([['field' => 'code',
                    'message' => 'This category code is already in use.']]);
            }
            throw $error;
        }
    }
}
