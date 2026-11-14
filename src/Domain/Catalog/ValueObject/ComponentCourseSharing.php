<?php

declare(strict_types=1);

namespace Domain\Catalog\ValueObject;

/** Independent from sharing courses across groups inside one curriculum component. */
enum ComponentCourseSharing: string
{
    case OneComponent = 'one_component';
    case MultipleComponents = 'multiple_components';
}
