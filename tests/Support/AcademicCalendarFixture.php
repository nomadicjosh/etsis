<?php

declare(strict_types=1);

require_once __DIR__ . '/SisFixture.php';

use Domain\AcademicCalendar\ValueObject\TermType;
use Domain\Shared\ValueObject\EntityId;

final class AcademicCalendarFixture
{
    public SisFixture $sis;
    public function __construct(?SisFixture $sis = null) { $this->sis = $sis ?? new SisFixture(); }
    public function type(string $code = 'FA', string $kind = 'standard', string $start = '08-15', string $end = '12-15', string $drop = '08-29', string $anchor = 'START_YEAR'): TermType
    {
        $type = new TermType(EntityId::generate(), $code, $code . ' {{' . $anchor . '}}', '{{' . $anchor . '_SHORT}}/' . $code, $kind,
            ['starts_on' => ['year' => $anchor, 'month_day' => $start], 'ends_on' => ['year' => $anchor, 'month_day' => $end], 'add_drop_ends_on' => ['year' => $anchor, 'month_day' => $drop]]);
        $this->sis->calendar()->saveType($type, -1); return $type;
    }
    public function year(int $start): array
    {
        $review = $this->sis->calendar()->review($start); $ids = array_column($review['terms'], 'id', 'term_type_id');
        return $this->sis->calendar()->create(new EntityId($review['id']), new EntityId($review['operation_id']), $start, $ids, $review['revision']);
    }
}
