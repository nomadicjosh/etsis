<?php

declare(strict_types=1);

namespace Application\Provider;

use Application\Http\Security\RegistrationCounterLock;
use Codefy\Framework\Support\CodefyServiceProvider;
use Codefy\Framework\Scheduler\Mutex\FileLocker;
use Codefy\Framework\Scheduler\Mutex\Locker;
use Infrastructure\Security\FileRegistrationCounterLock;

final class AppServiceProvider extends CodefyServiceProvider
{
    public function register(): void
    {
        $this->codefy->alias(
            \Domain\User\Service\PublicRegistrationPolicyProvider::class,
            \Infrastructure\Security\ConfiguredPublicRegistrationPolicy::class,
        );
        $this->codefy->share(new FileRegistrationCounterLock(
            $this->codefy->storagePath() . '/framework/security/registration-locks',
        ));
        $this->codefy->alias(RegistrationCounterLock::class, FileRegistrationCounterLock::class);
        $this->codefy->alias(
            'registration.throttle',
            \Application\Http\Middleware\PublicRegistrationThrottleMiddleware::class,
        );
        $this->codefy->share(new FileLocker($this->codefy->storagePath() . '/scheduler-locks'));
        $this->codefy->alias(Locker::class, FileLocker::class);
    }
}
