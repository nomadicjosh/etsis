<?php

declare(strict_types=1);

namespace Domain\Lms\Service;

use Domain\Lms\ValueObject\MoodleBinding;
use Domain\Gradebook\ValueObject\ExternalCourseGrade;
use Domain\Lms\ValueObject\MoodleExternalId;

interface MoodleGradeGateway
{
    public function courseGrade(
        MoodleBinding $binding,
        MoodleExternalId $course,
        MoodleExternalId $user,
    ): ExternalCourseGrade;
}
