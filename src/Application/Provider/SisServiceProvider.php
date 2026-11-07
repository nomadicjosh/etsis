<?php

declare(strict_types=1);

namespace Application\Provider;

use Codefy\Framework\Support\CodefyServiceProvider;
use Domain\Admissions\Repository\AdmissionsRepository;
use Domain\Shared\Security\StudentAccess;
use Infrastructure\Persistence\Repository\PdoAdmissionsRepository;
use Infrastructure\Security\GateStudentAccess;

final class SisServiceProvider extends CodefyServiceProvider
{
    public function register(): void
    {
        $bindings = [
            \Domain\Portal\Repository\PaymentRepository::class =>
                \Infrastructure\Persistence\Repository\SqlPaymentRepository::class,
            \Domain\Portal\Service\PaymentGateway::class =>
                \Infrastructure\Payments\StripePaymentGateway::class,
            \Domain\Portal\Repository\CampusRepository::class =>
                \Infrastructure\Persistence\Repository\SqlCampusRepository::class,
            \Domain\Person\Repository\PersonProfileRepository::class =>
                \Infrastructure\Persistence\Repository\SqlPersonProfileRepository::class,
            \Domain\Person\Repository\PersonAccountRepository::class =>
                \Infrastructure\Persistence\Repository\SqlPersonAccountRepository::class,
            \Domain\User\Repository\UserAggregateRepository::class =>
                \Infrastructure\Persistence\Repository\EventSourcedUserRepository::class,
            \Domain\User\Service\UserProjection::class =>
                \Infrastructure\Projection\ExpressiveDbalUserProjection::class,
            \Domain\Catalog\Repository\CatalogRevisionRepository::class =>
                \Infrastructure\Persistence\Repository\SqlCatalogRevisionRepository::class,
            \Domain\Workspace\Repository\OperationsRepository::class =>
                \Infrastructure\Persistence\Repository\SqlOperationsRepository::class,
            \Domain\Workspace\Repository\WorkspaceRepository::class =>
                \Infrastructure\Persistence\Repository\SqlWorkspaceRepository::class,
            \Domain\Admissions\Repository\ApplicationFormRepository::class =>
                \Infrastructure\Persistence\Repository\SqlApplicationFormRepository::class,
            \Domain\Rules\Repository\RuleRepository::class =>
                \Infrastructure\Persistence\Repository\SqlRuleRepository::class,
            \Domain\Import\Service\CourseCsvMapper::class =>
                \Infrastructure\Import\LeagueCourseCsvMapper::class,
            \Domain\Person\Service\InstitutionalIdGenerator::class =>
                \Infrastructure\Identity\RandomInstitutionalIdGenerator::class,
            \Domain\Finance\Repository\BillingRuleRepository::class =>
                \Infrastructure\Persistence\Repository\EventSourcedBillingRuleRepository::class,
            \Domain\Reporting\Repository\ReportRepository::class =>
                \Infrastructure\Persistence\Repository\SqlReportRepository::class,
            \Domain\Import\Repository\CatalogImportRepository::class =>
                \Infrastructure\Persistence\Repository\SqlCatalogImportRepository::class,
            \Domain\Communications\Repository\CampaignRepository::class =>
                \Infrastructure\Persistence\Repository\SqlCampaignRepository::class,
            \Domain\Communications\Service\MessageCipher::class =>
                \Infrastructure\Communications\DefuseMessageCipher::class,
            \Domain\Communications\Service\MailTransport::class =>
                \Infrastructure\Communications\ConfiguredMailTransport::class,
            \Domain\Portal\Repository\PortalRepository::class =>
                \Infrastructure\Persistence\Repository\SqlPortalRepository::class,

            \Domain\Transfer\Repository\TransferRepository::class =>
                \Infrastructure\Persistence\Repository\SqlTransferRepository::class,
            \Domain\Finance\Repository\JournalRepository::class =>
                \Infrastructure\Persistence\Repository\EventSourcedJournalRepository::class,
            \Domain\Shared\Persistence\UnitOfWork::class =>
                \Infrastructure\Persistence\DatabaseUnitOfWork::class,
            \Domain\Audit\Repository\AuditTrail::class =>
                \Infrastructure\Persistence\SqlAuditTrail::class,
            \Domain\Shared\Service\Clock::class =>
                \Infrastructure\SystemClock::class,
            \Domain\Registration\Repository\RegistrationRepository::class =>
                \Infrastructure\Persistence\Repository\SqlRegistrationRepository::class,
            \Domain\Registration\Service\RegistrationBilling::class =>
                \Infrastructure\Persistence\EventSourcedRegistrationBilling::class,
            \Domain\Gradebook\Repository\GradebookRepository::class =>
                \Infrastructure\Persistence\Repository\SqlGradebookRepository::class,
            \Domain\Scheduling\Repository\BookingRepository::class =>
                \Infrastructure\Persistence\Repository\SqlBookingRepository::class,
            \Domain\HumanResources\Repository\TimeRepository::class =>
                \Infrastructure\Persistence\Repository\SqlTimeRepository::class,
        ];
        foreach ($bindings as $port => $adapter) {
            $this->codefy->alias($port, $adapter);
        }
        // Auth configuration translates messages through the bootstrapped application.
        // Resolve it only when the account service is requested, after provider registration.
        $this->codefy->delegate(\Domain\User\Service\PersonAccountService::class, function () {
            return new \Domain\User\Service\PersonAccountService(
                $this->codefy->make(AdmissionsRepository::class),
                $this->codefy->make(\Domain\User\Repository\UserAggregateRepository::class),
                $this->codefy->make(StudentAccess::class),
                $this->codefy->make(\Domain\Shared\Persistence\UnitOfWork::class),
                $this->codefy->make(\Domain\Audit\Repository\AuditTrail::class),
                $this->codefy->make(\Domain\Person\Repository\PersonProfileRepository::class),
                $this->codefy->configContainer->integer('auth.password_min_length', 26),
            );
        });
        $this->codefy->delegate(\Domain\User\Service\PersonAccountEditor::class, function () {
            return new \Domain\User\Service\PersonAccountEditor(
                $this->codefy->make(AdmissionsRepository::class),
                $this->codefy->make(\Domain\User\Repository\UserAggregateRepository::class),
                $this->codefy->make(\Domain\User\Service\UserAccountAccess::class),
                $this->codefy->make(StudentAccess::class),
                $this->codefy->make(\Domain\Audit\Repository\AuditTrail::class),
                $this->codefy->configContainer->integer('auth.password_min_length', 26),
            );
        });
        $this->codefy->define(\Infrastructure\SystemClock::class, [
            ':timezone' => $this->codefy->configContainer->getConfigKey('app.timezone', 'UTC'),
        ]);
        $this->codefy->alias(
            \Domain\Catalog\Repository\CatalogRepository::class,
            \Infrastructure\Persistence\Repository\DbalCatalogRepository::class,
        );
        $this->codefy->alias(
            \Domain\Finance\Repository\AccountRepository::class,
            \Infrastructure\Persistence\Repository\EventSourcedAccountRepository::class,
        );
        $this->codefy->alias(
            \Codefy\Domain\EventSourcing\TransactionalEventStore::class,
            \Infrastructure\Persistence\PdoTransactionalEventStore::class,
        );
        $this->codefy->alias(AdmissionsRepository::class, PdoAdmissionsRepository::class);
        $this->codefy->alias(StudentAccess::class, GateStudentAccess::class);
    }
}
