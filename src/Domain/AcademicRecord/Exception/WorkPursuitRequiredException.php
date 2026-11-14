<?php

declare(strict_types=1);

namespace Domain\AcademicRecord\Exception;

final class WorkPursuitRequiredException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('Academic work needs an explicit pursuit attribution.');
    }
}
