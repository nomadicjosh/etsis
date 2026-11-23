<?php

declare(strict_types=1);

namespace Application\Provider;

use Codefy\Framework\Application;
use Codefy\Framework\Support\CodefyServiceProvider;
use Domain\Shared\Operations\MaintenanceMode;
use Infrastructure\Operations\FileMaintenanceMode;
use Infrastructure\SystemClock;
use Gettext\Translator;
use Gettext\TranslatorFunctions;
use Codefy\Framework\Providers\LocalizationServiceProvider;

final class MaintenanceServiceProvider extends CodefyServiceProvider
{
    /** Available before providers, routing, sessions, credentials or database connections are resolved. */
    public static function mode(Application $application): MaintenanceMode
    {
        return new FileMaintenanceMode($application->storagePath() . '/framework/maintenance', new SystemClock());
    }

    /** The early response must not boot unrelated providers merely to translate its fixed public text. */
    public static function localization(Application $application): void
    {
        if (!function_exists('d__')) {
            TranslatorFunctions::register(new Translator());
            new LocalizationServiceProvider($application)->register();
        }
    }

    public function register(): void
    {
        $this->codefy->share(self::mode($this->codefy));
        $this->codefy->alias(MaintenanceMode::class, FileMaintenanceMode::class);
    }
}
