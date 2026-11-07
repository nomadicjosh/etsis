<?php

declare(strict_types=1);

namespace Application\Provider;

use Codefy\Framework\Auth\Auth;
use Codefy\Framework\Auth\Rbac\Resource\StorageResource;
use Codefy\Framework\Auth\Gate;
use Codefy\Framework\Auth\Repository\AuthUserRepository;
use Codefy\Framework\Auth\Sentinel;
use Codefy\Framework\Support\CodefyServiceProvider;
use Domain\Access\Repository\AccessRepository;
use Gettext\Translator;
use Gettext\TranslatorFunctions;
use Infrastructure\Persistence\Repository\PdoAuthUserRespository;
use Infrastructure\Persistence\Repository\SqlAccessRepository;
use Infrastructure\Persistence\UserAuth;
use Infrastructure\Security\Rbac\SqlStorageResource;
use Qubus\Exception\Exception;

final class RbacServiceProvider extends CodefyServiceProvider
{
    public function register(): void
    {
        $translator = new Translator();
        TranslatorFunctions::register($translator);

        // Lazy binding permits console boot before the access migration has run.
        $this->codefy->alias(AccessRepository::class, SqlAccessRepository::class);

        $this->codefy->alias(StorageResource::class, SqlStorageResource::class);
        $this->codefy->share(StorageResource::class);
        $this->codefy->alias(original: Sentinel::class, alias: Auth::class);
        $this->codefy->share(nameOrInstance: Sentinel::class);
        $this->codefy->alias(original: AuthUserRepository::class, alias: PdoAuthUserRespository::class);
        $this->codefy->share(nameOrInstance: AuthUserRepository::class);
        $this->codefy->alias(original: Gate::class, alias: UserAuth::class);
        $this->codefy->share(nameOrInstance: Gate::class);
    }
}
