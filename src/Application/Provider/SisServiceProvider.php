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
            \Domain\Catalog\Repository\ProgramDefinitionRepository::class =>
                \Infrastructure\Persistence\Repository\SqlProgramDefinitionRepository::class,
            \Domain\Catalog\Repository\ClassificationRepository::class =>
                \Infrastructure\Persistence\Repository\SqlClassificationRepository::class,
            \Domain\Catalog\Repository\SubjectRepository::class =>
                \Infrastructure\Persistence\Repository\SqlSubjectRepository::class,
            \Domain\Registration\Repository\SectionVersionRepository::class =>
                \Infrastructure\Persistence\Repository\SqlSectionVersionRepository::class,
            \Domain\Registration\Repository\EnrollmentHistoryRepository::class =>
                \Infrastructure\Persistence\Repository\SqlEnrollmentHistoryRepository::class,
            \Domain\Transfer\Repository\TransferCreditRepository::class =>
                \Infrastructure\Persistence\Repository\SqlTransferCreditRepository::class,
            \Domain\AcademicRecord\Repository\AcademicCreditRepository::class =>
                \Infrastructure\Persistence\Repository\SqlAcademicCreditRepository::class,
            \Domain\Gradebook\Repository\AcademicGradeRepository::class =>
                \Infrastructure\Persistence\Repository\SqlAcademicGradeRepository::class,
            \Domain\Gradebook\Repository\GradingScaleRepository::class =>
                \Infrastructure\Persistence\Repository\SqlGradingScaleRepository::class,
            \Domain\DegreeAudit\Repository\ReplacementCourseRepository::class =>
                \Infrastructure\Persistence\Repository\SqlReplacementCourseRepository::class,
            \Domain\DegreeAudit\Repository\RequirementAdjustmentRepository::class =>
                \Infrastructure\Persistence\Repository\SqlRequirementAdjustmentRepository::class,
            \Domain\DegreeAudit\Repository\StudentRequirementsRepository::class =>
                \Infrastructure\Persistence\Repository\SqlStudentRequirementsRepository::class,
            \Domain\DegreeAudit\Repository\CourseGroupRepository::class =>
                \Infrastructure\Persistence\Repository\SqlCourseGroupRepository::class,
            \Domain\DegreeAudit\Repository\RequirementsRepository::class =>
                \Infrastructure\Persistence\Repository\SqlRequirementsRepository::class,
            \Domain\Registration\Repository\SectionCourseBindingRepository::class =>
                \Infrastructure\Persistence\Repository\SqlSectionCourseBindingRepository::class,
            \Domain\Catalog\Repository\CourseTextProjection::class =>
                \Infrastructure\Persistence\Repository\SqlCourseTextProjection::class,
            \Domain\Shared\EventSourcing\HistoricalEventStore::class =>
                \Infrastructure\EventSourcing\SqlHistoricalEventStore::class,
            \Domain\AcademicCalendar\Repository\AcademicCalendarRepository::class =>
                \Infrastructure\Persistence\Repository\SqlAcademicCalendarRepository::class,
            \Domain\Catalog\Repository\OfferingTemplateRepository::class =>
                \Infrastructure\Persistence\Repository\SqlOfferingTemplateRepository::class,
            \Domain\Reporting\Repository\ReportRequestRepository::class =>
                \Infrastructure\Persistence\Repository\SqlReportRequestRepository::class,
            \Domain\Registration\Repository\CourseDropRepository::class =>
                \Infrastructure\Persistence\Repository\SqlCourseDropRepository::class,
            \Domain\Registration\Repository\DropPolicyRepository::class =>
                \Infrastructure\Persistence\Repository\SqlDropPolicyRepository::class,
            \Domain\Transfer\Repository\TransferAwardRepository::class =>
                \Infrastructure\Persistence\Repository\SqlTransferAwardRepository::class,
            \Domain\Student\Repository\HiatusImpactRepository::class =>
                \Infrastructure\Persistence\Repository\SqlHiatusImpactRepository::class,
            \Domain\Student\Repository\HiatusRepository::class =>
                \Infrastructure\Persistence\Repository\SqlHiatusRepository::class,
            \Domain\Registration\Repository\RegistrationBatchRepository::class =>
                \Infrastructure\Persistence\Repository\SqlRegistrationBatchRepository::class,
            \Domain\Transfer\Repository\TransferCatalogRepository::class =>
                \Infrastructure\Persistence\Repository\SqlTransferCatalogRepository::class,
            \Domain\Student\Repository\RestrictionRepository::class =>
                \Infrastructure\Persistence\Repository\SqlRestrictionRepository::class,
            \Domain\Student\Repository\StudentActivityRepository::class =>
                \Infrastructure\Persistence\Repository\SqlStudentActivityRepository::class,
            \Domain\Student\Repository\StudentProgramStatusRepository::class =>
                \Infrastructure\Persistence\Repository\SqlStudentProgramStatusRepository::class,
            \Domain\Student\Repository\StudentPrimaryProgramRepository::class =>
                \Infrastructure\Persistence\Repository\SqlStudentPrimaryProgramRepository::class,
            \Domain\Student\Repository\StudentRecordRepository::class =>
                \Infrastructure\Persistence\Repository\SqlStudentRecordRepository::class,
            \Domain\Scheduling\Repository\RoomRepository::class =>
                \Infrastructure\Persistence\Repository\SqlRoomRepository::class,
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
            \Domain\Finance\Repository\FinancialReviewRepository::class =>
                \Infrastructure\Persistence\Repository\SqlFinancialReviewRepository::class,
            \Domain\Transcript\Repository\TranscriptRepository::class =>
                \Infrastructure\Persistence\Repository\SqlTranscriptRepository::class,
            \Domain\Transcript\Service\TranscriptDocumentRenderer::class =>
                \Infrastructure\Documents\ProtectedTranscriptRenderer::class,
            \Domain\Reporting\Repository\RelationalReportRepository::class =>
                \Infrastructure\Persistence\Repository\SqlRelationalReportRepository::class,
            \Domain\Reporting\Repository\RelationalReportSource::class =>
                \Infrastructure\Reporting\SqlRelationalReportSource::class,
            \Domain\Catalog\Service\CatalogEnrollment::class =>
                \Domain\Catalog\Service\InitialCatalogEnrollment::class,
            \Domain\Catalog\Repository\CatalogYearRepository::class =>
                \Infrastructure\Persistence\Repository\SqlCatalogYearRepository::class,
            \Domain\Catalog\Repository\CatalogWorkflowRepository::class =>
                \Infrastructure\Persistence\Repository\SqlCatalogWorkflowRepository::class,
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
            \Domain\Audit\Repository\AuditTrail::class => \Infrastructure\Persistence\SqlAuditTrail::class,
            \Domain\Shared\Service\Clock::class => \Infrastructure\SystemClock::class,
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
        $this->codefy->delegate(\Domain\Transcript\ValueObject\TranscriptIssuer::class, function () {
            return new \Domain\Transcript\ValueObject\TranscriptIssuer(
                $this->codefy->configContainer->string("transcript.issuer_name", ""),
                $this->codefy->configContainer->string("transcript.issuer_address", ""),
            );
        });
        $this->codefy->delegate(\Domain\Student\ValueObject\HiatusPolicy::class, function () {
            return new \Domain\Student\ValueObject\HiatusPolicy(
                $this->codefy->configContainer->array(
                    "student.hiatus_proposals",
                    \Domain\Student\ValueObject\HiatusPolicy::DEFAULTS,
                ),
            );
        });
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
                $this->codefy->configContainer->integer("auth.password_min_length", 26),
            );
        });
        $this->codefy->delegate(\Domain\User\Service\PersonAccountEditor::class, function () {
            return new \Domain\User\Service\PersonAccountEditor(
                $this->codefy->make(AdmissionsRepository::class),
                $this->codefy->make(\Domain\User\Repository\UserAggregateRepository::class),
                $this->codefy->make(\Domain\User\Service\UserAccountAccess::class),
                $this->codefy->make(StudentAccess::class),
                $this->codefy->make(\Domain\Audit\Repository\AuditTrail::class),
                $this->codefy->configContainer->integer("auth.password_min_length", 26),
            );
        });
        $this->codefy->define(\Infrastructure\SystemClock::class, [
            ":timezone" => $this->codefy->configContainer->getConfigKey("app.timezone", "UTC"),
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
