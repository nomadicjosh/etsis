<?php

declare(strict_types=1);

namespace Infrastructure\Persistence\Repository;

use Domain\DegreeAudit\Repository\TransferInstitutionDirectory;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\SqlRecords;

final readonly class SqlTransferInstitutionDirectory implements TransferInstitutionDirectory
{
    public function __construct(private SqlRecords $sql)
    {
    }
    public function page(string $search, ?EntityId $after = null): array
    {
        $q = $this->sql->quote(...);
        $where = [];
        $parameters = [];
        if ($search !== "") {
            $pattern = "%" . strtr($search, ["!" => "!!", "%" => "!%", "_" => "!_"]) . "%";
            $where[] =
            "(LOWER(" .
            $q("code") .
            ") LIKE LOWER(?) ESCAPE '!' OR LOWER(" .
            $q("name") .
            ") LIKE LOWER(?) ESCAPE '!')";
            array_push($parameters, $pattern, $pattern);
        }
        if ($after !== null) {
            $where[] = $q("id") . " < ?";
            $parameters[] = $after->value;
        }
        return $this->sql->fetch(
            "SELECT " .
                $q("id") .
                ", " .
                $q("code") .
                ", " .
                $q("name") .
                " FROM " .
                $q("sis_external_institutions") .
                ($where === [] ? "" : " WHERE " . implode(" AND ", $where)) .
                " ORDER BY " .
                $q("id") .
                " DESC",
            $parameters,
            26,
        );
    }
    public function get(EntityId $id): array
    {
        $row = $this->sql->one("sis_external_institutions", ["id" => $id->value]);
        return ["id" => $row["id"], "code" => $row["code"], "name" => $row["name"]];
    }
    public function lock(EntityId $id): void
    {
        $this->sql->lock("sis_external_institutions", $id->value, "id");
    }
}
