<?php

declare(strict_types=1);

namespace Domain\Shared\EventSourcing;

/** Bounded physical inventory of the single journal and its protected support rows; no projections. */
interface HistoryInventory
{
    /** @return array{streams:int,events:int,operations:int,digest:string} */
    public function fingerprint(int $rowLimit): array;
}
