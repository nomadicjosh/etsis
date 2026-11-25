<?php

declare(strict_types=1);

namespace Tests\Unit;

use Domain\Registration\ValueObject\ProgramCampusEvidence;
use PHPUnit\Framework\TestCase;

final class ProgramCampusEvidenceTest extends TestCase
{
    public function testRetainedMapOrderingDoesNotChangeEvidenceAndNumericIdentitiesRemainDistinct(): void
    {
        $references = array_fill_keys(['student_id', 'section_id', 'section_version_id', 'pursuit_id',
            'program_id', 'academic_year_id', 'program_version_id', 'campus_id'], '01111111111111111111111111');
        $original = new ProgramCampusEvidence($references);
        self::assertSame($original->data, new ProgramCampusEvidence(array_reverse($references))->data);
        $references['campus_id'] = '01111111111111111111111112';
        self::assertNotSame($original->data, new ProgramCampusEvidence($references)->data);
        self::assertStringNotContainsString($references['campus_id'], json_encode($original, JSON_THROW_ON_ERROR));
    }
}
