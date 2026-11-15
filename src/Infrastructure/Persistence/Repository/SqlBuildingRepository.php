<?php

declare(strict_types=1);

namespace Infrastructure\Persistence\Repository;

use Domain\Scheduling\Building;
use Domain\Scheduling\Repository\BuildingRepository;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\SqlRecords;
use PDOException;

final readonly class SqlBuildingRepository implements BuildingRepository
{
    public function __construct(private SqlRecords $sql)
    {
    }

    public function get(EntityId $id): Building
    {
        $row = $this->sql->one('sis_buildings', ['id' => $id->value]);
        return new Building($id, $row['code'], $row['name']);
    }

    public function lock(EntityId $id): void
    {
        $this->sql->lock('sis_buildings', $id->value, 'code');
    }

    public function add(Building $building): void
    {
        $this->write($building, true);
    }

    public function save(Building $building): void
    {
        $this->write($building, false);
    }

    private function write(Building $building, bool $new): void
    {
        $data = ['code' => $building->code, 'name' => $building->name];
        try {
            if ($new) {
                $this->sql->insert('sis_buildings', ['id' => $building->id->value] + $data);
            } else {
                $this->sql->update('sis_buildings', $data, ['id' => $building->id->value]);
            }
        } catch (PDOException $error) {
            if (in_array((string) $error->getCode(), ['23000', '23505'], true)) {
                throw new ValidationException([['field' => 'code',
                    'message' => 'This building code is already in use.']]);
            }
            throw $error;
        }
    }
}
