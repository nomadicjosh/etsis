<?php

declare(strict_types=1);

namespace Application\Provider;

use Codefy\Framework\Support\CodefyServiceProvider;
use Codefy\Framework\Scheduler\Mutex\FileLocker;
use Codefy\Framework\Scheduler\Mutex\Locker;

final class AppServiceProvider extends CodefyServiceProvider
{
    public function register(): void
    {
        $this->codefy->share(new FileLocker($this->codefy->storagePath() . '/scheduler-locks'));
        $this->codefy->alias(Locker::class, FileLocker::class);
    }
}
