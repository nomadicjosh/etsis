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
            \Domain\HumanResources\Repository\PositionRepository::class =>
                \Infrastructure\Persistence\Repository\SqlPositionRepository::class,
            \Domain\HumanResources\Repository\EmploymentDatesRepository::class =>
                \Infrastructure\Persistence\Repository\SqlEmploymentDatesRepository::class,
            \Domain\HumanResources\Repository\HrLibraryRepository::class =>
                \Infrastructure\Persistence\Repository\SqlHrLibraryRepository::class,
            \Domain\Person\Repository\AddressBookRepository::class =>
                \Infrastructure\Persistence\Repository\SqlAddressBookRepository::class,
            \Domain\Audit\Repository\ActorDirectory::class => \Infrastructure\Persistence\SqlActorDirectory::class,
            \Domain\Person\Repository\AccountSetupRepository::class =>
                \Infrastructure\Persistence\Repository\SqlAccountSetupRepository::class,
            \Domain\Catalog\Repository\SpecializationPolicyRepository::class =>
                \Infrastructure\Persistence\Repository\SqlSpecializationPolicyRepository::class,
            \Domain\Catalog\Repository\ProgramSpecializationRepository::class =>
                \Infrastructure\Persistence\Repository\SqlProgramSpecializationRepository::class,
            \Domain\Lms\Repository\MoodleCategoryRepository::class =>
                \Infrastructure\Lms\SqlMoodleCategoryRepository::class,
            \Domain\Lms\Repository\MoodleCourseRequestRepository::class =>
                \Infrastructure\Lms\SqlMoodleCourseRequestRepository::class,
            \Domain\Lms\Service\MoodleAutomationPolicy::class =>
                \Infrastructure\Lms\ConfiguredMoodleSectionSyncPolicy::class,
            \Domain\Lms\Repository\MoodleSectionRepository::class =>
                \Infrastructure\Lms\SqlMoodleSectionRepository::class,
            \Domain\Lms\Service\MoodleSectionGateway::class =>
                \Infrastructure\Lms\RestMoodleGateway::class,
            \Domain\Lms\Service\MoodleSectionSyncPolicy::class =>
                \Infrastructure\Lms\ConfiguredMoodleSectionSyncPolicy::class,
            \Domain\User\Repository\AccountIdentityLookup::class =>
                \Infrastructure\Security\SqlAccountIdentityLookup::class,
            \Domain\User\Repository\SessionRetentionRepository::class =>
                \Infrastructure\Security\SqlSessionRetentionRepository::class,
            \Domain\Lms\Repository\MoodleLaunchRepository::class =>
                \Infrastructure\Lms\SqlMoodleLaunchRepository::class,
            \Domain\Lms\Service\MoodleLaunchPolicy::class =>
                \Infrastructure\Lms\ConfiguredMoodleLaunchPolicy::class,
            \Application\Http\Security\MoodleSessionChannel::class =>
                \Infrastructure\Lms\SignedMoodleSessionChannel::class,
            \Domain\User\Repository\LoginSessionRepository::class =>
                \Infrastructure\Security\SqlLoginSessionRepository::class,
            \Domain\User\Repository\LoginAccountStateReader::class =>
                \Infrastructure\Security\SqlLoginAccountStateReader::class,
            \Application\Http\Security\LoginCookieReader::class =>
                \Infrastructure\Security\FrameworkLoginCookieReader::class,
            \Domain\Lms\Repository\MoodleProvisioningQueue::class =>
                \Infrastructure\Lms\SqlMoodleProvisioningQueue::class,
            \Domain\Lms\Service\MoodleAccountProvisioner::class =>
                \Infrastructure\Lms\RestMoodleGateway::class,
            \Domain\Lms\Service\MoodleAccountReceiptReader::class =>
                \Infrastructure\Lms\RestMoodleGateway::class,
            \Domain\Lms\Repository\MoodleAccountRequestRepository::class =>
                \Infrastructure\Lms\SqlMoodleAccountRequestRepository::class,
            \Domain\Lms\Repository\MoodleAccountDefaults::class =>
                \Infrastructure\Lms\SqlMoodleAccountDefaults::class,
            \Domain\Lms\Service\MoodleAccountRequestPolicy::class =>
                \Infrastructure\Lms\ConfiguredMoodleAccountRequestPolicy::class,
            \Domain\Lms\Repository\MoodlePersonLinkRepository::class =>
                \Infrastructure\Lms\SqlMoodlePersonLinkRepository::class,
            \Domain\Lms\Service\MoodleBoundRecordReader::class =>
                \Infrastructure\Lms\RestMoodleGateway::class,
            \Domain\Lms\Repository\MoodleBindingRepository::class =>
                \Infrastructure\Lms\SqlMoodleBindingRepository::class,
            \Domain\Lms\Service\MoodleInstanceReader::class => \Infrastructure\Lms\RestMoodleGateway::class,
            \Domain\Lms\Service\MoodleRecordReader::class => \Infrastructure\Lms\RestMoodleGateway::class,
            \Domain\Lms\Service\MoodleConnectionProbe::class => \Infrastructure\Lms\RestMoodleGateway::class,
            \Domain\Shared\EventSourcing\HistoricalIntegrityVerifier::class =>
                \Infrastructure\EventSourcing\SqlHistoricalIntegrityVerifier::class,
            \Domain\AcademicRecord\Repository\AcademicProjectionVerifier::class =>
                \Infrastructure\Projection\SqlAcademicProjectionVerifier::class,
            \Domain\Finance\Repository\AccountProjectionVerifier::class =>
                \Infrastructure\Projection\SqlAccountProjectionVerifier::class,
            \Domain\Support\Repository\HistoryCheckRepository::class =>
                \Infrastructure\Persistence\Repository\SqlHistoryCheckRepository::class,
            \Domain\Person\Repository\PersonProjectionVerifier::class =>
                \Infrastructure\Projection\SqlPersonProjectionVerifier::class,
            \Domain\DegreeAudit\Service\DegreeProgressReader::class =>
                \Domain\DegreeAudit\Service\DegreeProgressService::class,
            \Domain\Assistance\Service\AuthoringAssistant::class =>
                \Infrastructure\Assistance\ConfiguredAuthoringAssistant::class,
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
            \Domain\AcademicRecord\Repository\GradeChangeRepository::class =>
                \Infrastructure\Persistence\Repository\SqlGradeChangeRepository::class,
            \Domain\AcademicRecord\Repository\AcademicCreditRepository::class =>
                \Infrastructure\Persistence\Repository\SqlAcademicCreditRepository::class,
            \Domain\AcademicRecord\Repository\WorkPursuitRepository::class =>
                \Infrastructure\Persistence\Repository\SqlWorkPursuitRepository::class,
            \Domain\Gradebook\Repository\AcademicGradeRepository::class =>
                \Infrastructure\Persistence\Repository\SqlAcademicGradeRepository::class,
            \Domain\Gradebook\Repository\GradingScaleRepository::class =>
                \Infrastructure\Persistence\Repository\SqlGradingScaleRepository::class,
            \Domain\Catalog\Repository\CourseSearchRepository::class =>
                \Infrastructure\Persistence\Repository\SqlCourseSearchRepository::class,
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
            \Domain\Registration\Repository\SectionClassificationRepository::class =>
                \Infrastructure\Persistence\Repository\SqlSectionClassificationRepository::class,
            \Domain\Registration\Repository\DropPolicyRepository::class =>
                \Infrastructure\Persistence\Repository\SqlDropPolicyRepository::class,
            \Domain\Transfer\Repository\TransferAwardRepository::class =>
                \Infrastructure\Persistence\Repository\SqlTransferAwardRepository::class,
            \Domain\Student\Repository\HiatusImpactRepository::class =>
                \Infrastructure\Persistence\Repository\SqlHiatusImpactRepository::class,
            \Domain\Student\Repository\HiatusRepository::class =>
                \Infrastructure\Persistence\Repository\SqlHiatusRepository::class,
            \Domain\Registration\Repository\SectionChangeSearch::class =>
                \Infrastructure\Persistence\Repository\SqlSectionChangeSearch::class,
            \Domain\Registration\Repository\RegistrationBatchRepository::class =>
                \Infrastructure\Persistence\Repository\SqlRegistrationBatchRepository::class,
            \Domain\Transfer\Repository\TransferCatalogRepository::class =>
                \Infrastructure\Persistence\Repository\SqlTransferCatalogRepository::class,
            \Domain\Student\Repository\RestrictionRepository::class =>
                \Infrastructure\Persistence\Repository\SqlRestrictionRepository::class,
            \Domain\Office\Repository\OfficeRepository::class =>
                \Infrastructure\Persistence\Repository\SqlOfficeRepository::class,
            \Domain\Student\Repository\StudentActivityRepository::class =>
                \Infrastructure\Persistence\Repository\SqlStudentActivityRepository::class,
            \Domain\Transcript\Service\TranscriptStandingSource::class =>
                \Domain\Student\Service\StudentStandingLedger::class,
            \Domain\Registration\Repository\PrerequisitePermissionRepository::class =>
                \Infrastructure\Persistence\Repository\SqlPrerequisitePermissionRepository::class,
            \Domain\Student\Repository\EnrollmentLoadPolicyRepository::class =>
                \Infrastructure\Persistence\Repository\SqlEnrollmentLoadPolicyRepository::class,
            \Domain\DegreeAudit\Repository\DegreeProgressRecordRepository::class =>
                \Infrastructure\Persistence\Repository\SqlDegreeProgressRecordRepository::class,
            \Domain\Student\Repository\LoadDeterminationRepository::class =>
                \Infrastructure\Persistence\Repository\SqlLoadDeterminationRepository::class,
            \Domain\Student\Repository\EnrollmentLoadRepository::class =>
                \Infrastructure\Persistence\Repository\SqlEnrollmentLoadRepository::class,
            \Domain\Student\Repository\StudentStandingRepository::class =>
                \Infrastructure\Persistence\Repository\SqlStudentStandingRepository::class,
            \Domain\Advising\Repository\AdvisingCheckInRepository::class =>
                \Infrastructure\Persistence\Repository\SqlAdvisingCheckInRepository::class,
            \Domain\Advising\Repository\AdvisorRepository::class =>
                \Infrastructure\Persistence\Repository\SqlAdvisorRepository::class,
            \Domain\Advising\Service\AdvisorProgressAccess::class =>
                \Domain\Advising\Service\AdvisorService::class,
            \Domain\Student\Repository\StudentProgramStatusRepository::class =>
                \Infrastructure\Persistence\Repository\SqlStudentProgramStatusRepository::class,
            \Domain\Student\Repository\StudentPrimaryProgramRepository::class =>
                \Infrastructure\Persistence\Repository\SqlStudentPrimaryProgramRepository::class,
            \Domain\Student\Repository\StudentRecordRepository::class =>
                \Infrastructure\Persistence\Repository\SqlStudentRecordRepository::class,
            \Domain\Student\Repository\StudentSpecializationRepository::class =>
                \Infrastructure\Persistence\Repository\SqlStudentSpecializationRepository::class,
            \Domain\Scheduling\Repository\RoomRepository::class =>
                \Infrastructure\Persistence\Repository\SqlRoomRepository::class,
            \Domain\Scheduling\Repository\BuildingRepository::class =>
                \Infrastructure\Persistence\Repository\SqlBuildingRepository::class,
            \Domain\Scheduling\Repository\BookingCategoryRepository::class =>
                \Infrastructure\Persistence\Repository\SqlBookingCategoryRepository::class,
            \Domain\Scheduling\Repository\BookingSectionRepository::class =>
                \Infrastructure\Persistence\Repository\SqlBookingSectionRepository::class,
            \Domain\Registration\Repository\FacultyScheduleRepository::class =>
                \Infrastructure\Persistence\Repository\SqlFacultyScheduleRepository::class,
            \Domain\Registration\Repository\RegistrationScheduleRepository::class =>
                \Infrastructure\Persistence\Repository\SqlRegistrationScheduleRepository::class,
            \Domain\Registration\Repository\SectionMeetingPlanRepository::class =>
                \Infrastructure\Persistence\Repository\SqlSectionMeetingPlanRepository::class,
            \Domain\Scheduling\Repository\AcademicTimetableRepository::class =>
                \Infrastructure\Persistence\Repository\SqlAcademicTimetableRepository::class,
            \Domain\Scheduling\Repository\PublicEventRepository::class =>
                \Infrastructure\Persistence\Repository\SqlPublicEventRepository::class,
            \Domain\Portal\Repository\PaymentRepository::class =>
                \Infrastructure\Persistence\Repository\SqlPaymentRepository::class,
            \Domain\Portal\Service\PaymentGateway::class =>
                \Infrastructure\Payments\StripePaymentGateway::class,
            \Domain\Person\Repository\EmergencyContactsRepository::class =>
                \Infrastructure\Persistence\Repository\SqlEmergencyContactsRepository::class,
            \Domain\Person\Repository\EducationBackgroundRepository::class =>
                \Infrastructure\Persistence\Repository\SqlEducationBackgroundRepository::class,
            \Domain\Admissions\Repository\AdmissionWithdrawalRepository::class =>
                \Infrastructure\Persistence\Repository\SqlAdmissionWithdrawalRepository::class,
            \Domain\Admissions\Repository\ApplicationRevisionRepository::class =>
                \Infrastructure\Persistence\Repository\SqlApplicationRevisionRepository::class,
            \Domain\Admissions\Repository\AdmissionPolicyRepository::class =>
                \Infrastructure\Persistence\Repository\SqlAdmissionPolicyRepository::class,
            \Domain\Admissions\Repository\AdmissionCaseworkRepository::class =>
                \Infrastructure\Persistence\Repository\SqlAdmissionCaseworkRepository::class,
            \Domain\Admissions\Repository\AdmissionUpdatesRepository::class =>
                \Infrastructure\Persistence\Repository\SqlAdmissionUpdatesRepository::class,
            \Domain\Person\Repository\ProfileUpdateRequestRepository::class =>
                \Infrastructure\Persistence\Repository\SqlProfileUpdateRequestRepository::class,
            \Domain\Person\Repository\PersonContactRepository::class =>
                \Infrastructure\Persistence\Repository\SqlPersonContactRepository::class,
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
            \Domain\Transcript\Repository\TranscriptReleasePolicyRepository::class =>
                \Infrastructure\Persistence\Repository\SqlTranscriptReleasePolicyRepository::class,
            \Domain\Transcript\Repository\TranscriptRepository::class =>
                \Infrastructure\Persistence\Repository\SqlTranscriptRepository::class,
            \Domain\Transcript\Service\TranscriptDocumentRenderer::class =>
                \Infrastructure\Documents\ProtectedTranscriptRenderer::class,
            \Domain\Reporting\Repository\RelationalReportRepository::class =>
                \Infrastructure\Persistence\Repository\SqlRelationalReportRepository::class,
            \Domain\Reporting\Repository\ReportExportRepository::class =>
                \Infrastructure\Persistence\Repository\SqlReportExportRepository::class,
            \Domain\Reporting\Repository\ReportExportCapture::class =>
                \Infrastructure\Reporting\SqlReportExportCapture::class,
            \Domain\Reporting\Repository\RelationalReportSource::class =>
                \Infrastructure\Reporting\SqlRelationalReportSource::class,
            \Domain\Reporting\Repository\ReportLifecycleRepository::class =>
                \Infrastructure\Persistence\Repository\SqlReportLifecycleRepository::class,
            \Domain\Reporting\Repository\ReportCaptureSource::class =>
                \Infrastructure\Reporting\SqlReportCaptureSource::class,
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
            \Domain\Rules\Repository\CourseRuleRepository::class =>
                \Infrastructure\Persistence\Repository\SqlCourseRuleRepository::class,
            \Domain\Import\Service\CatalogCsvMapper::class =>
                \Infrastructure\Import\LeagueCatalogCsvMapper::class,
            \Domain\Person\Service\InstitutionalIdGenerator::class =>
                \Infrastructure\Identity\RandomInstitutionalIdGenerator::class,
            \Domain\Finance\Repository\BillingRuleRepository::class =>
                \Infrastructure\Persistence\Repository\EventSourcedBillingRuleRepository::class,
            \Domain\Finance\Service\TermFeeBilling::class =>
                \Infrastructure\Persistence\EventSourcedRegistrationBilling::class,
            \Domain\Finance\Repository\BillingBatchRepository::class =>
                \Infrastructure\Persistence\Repository\SqlBillingBatchRepository::class,
            \Domain\Reporting\Repository\ReportRepository::class =>
                \Infrastructure\Persistence\Repository\SqlReportRepository::class,
            \Domain\Import\Repository\CatalogImportRepository::class =>
                \Infrastructure\Persistence\Repository\SqlCatalogImportRepository::class,
            \Domain\Communications\Repository\CampaignRepository::class =>
                \Infrastructure\Persistence\Repository\SqlCampaignRepository::class,
            \Domain\Communications\Repository\CampaignDispatchRepository::class =>
                \Infrastructure\Persistence\Repository\SqlCampaignDispatchRepository::class,
            \Domain\Communications\Service\CampaignDeliveryExecutor::class =>
                \Infrastructure\Communications\AuthorizedCampaignExecutor::class,
            \Domain\Communications\Service\MessageCipher::class =>
                \Infrastructure\Communications\DefuseMessageCipher::class,
            \Domain\Admissions\Repository\AdmissionDocumentRepository::class =>
                \Infrastructure\Persistence\Repository\SqlAdmissionDocumentRepository::class,
            \Domain\Admissions\Service\AdmissionDocumentStorage::class =>
                \Infrastructure\Admissions\PrivateAdmissionDocumentStorage::class,
            \Domain\Admissions\Service\AdmissionDocumentScanner::class =>
                \Infrastructure\Admissions\ClamdDocumentScanner::class,
            \Domain\Communications\Service\MailTransport::class =>
                \Infrastructure\Communications\ConfiguredMailTransport::class,
            \Domain\Portal\Service\ContentMarkup::class => \Infrastructure\Portal\SymfonyContentMarkup::class,
            \Domain\Portal\Repository\PortalRepository::class =>
                \Infrastructure\Persistence\Repository\SqlPortalRepository::class,

            \Domain\DegreeAudit\Repository\AcademicInputRepository::class =>
                \Infrastructure\Persistence\Repository\SqlAcademicInputRepository::class,
            \Domain\DegreeAudit\Repository\AcademicWorkRepository::class =>
                \Infrastructure\Persistence\Repository\SqlAcademicWorkRepository::class,
            \Domain\Transfer\Repository\TransferRepository::class =>
                \Infrastructure\Persistence\Repository\SqlTransferRepository::class,
            \Domain\Finance\Repository\JournalRepository::class =>
                \Infrastructure\Persistence\Repository\EventSourcedJournalRepository::class,
            \Domain\Shared\Persistence\UnitOfWork::class =>
                \Infrastructure\Persistence\DatabaseUnitOfWork::class,
            \Domain\Audit\Repository\AuditTrail::class => \Infrastructure\Persistence\SqlAuditTrail::class,
            \Domain\Shared\Service\Clock::class => \Infrastructure\SystemClock::class,
            \Domain\Registration\Repository\CoRequisiteRepository::class =>
                \Infrastructure\Persistence\Repository\SqlCoRequisiteRepository::class,
            \Domain\Registration\Repository\SectionEnrollmentReviewRepository::class =>
                \Infrastructure\Persistence\Repository\SqlSectionEnrollmentReviewRepository::class,
            \Domain\Registration\Repository\RegistrationWindowRepository::class =>
                \Infrastructure\Persistence\Repository\SqlRegistrationWindowRepository::class,
            \Domain\HumanResources\Repository\TimePolicyState::class =>
                \Infrastructure\Persistence\Repository\SqlTimePolicyState::class,
            \Domain\Registration\Repository\SectionContactTimeRepository::class =>
                \Infrastructure\Persistence\Repository\SqlSectionContactTimeRepository::class,
            \Domain\Registration\Repository\ContactHoursPolicyReader::class =>
                \Infrastructure\HumanResources\WorkloadContactPolicyReader::class,
            \Domain\Registration\Waitlist\WaitlistRepository::class =>
                \Infrastructure\Persistence\Waitlist\SqlWaitlistRepository::class,
            \Domain\Registration\Repository\RegistrationRepository::class =>
                \Infrastructure\Persistence\Repository\SqlRegistrationRepository::class,
            \Domain\Registration\Service\RegistrationBilling::class =>
                \Infrastructure\Persistence\EventSourcedRegistrationBilling::class,
            \Domain\Gradebook\Repository\GradeCertificationRoster::class =>
                \Infrastructure\Persistence\Repository\SqlGradeCertificationRoster::class,
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
        $this->codefy->delegate(\Domain\Admissions\Service\AdmissionDocumentPolicy::class, function () {
            $enabled = $this->codefy->configContainer->getConfigKey('admission_documents.enabled', false) === true;
            return new \Domain\Admissions\Service\AdmissionDocumentPolicy(
                $enabled && class_exists(\finfo::class),
                (int) $this->codefy->configContainer->getConfigKey('admission_documents.retention_days', 0),
                $this->codefy->configContainer->getConfigKey('admission_documents.scanner_reviewed', false) === true,
            );
        });
        $this->codefy->delegate(\Domain\Transcript\ValueObject\TranscriptIssuer::class, function () {
            return new \Domain\Transcript\ValueObject\TranscriptIssuer(
                $this->codefy->configContainer->string("transcript.issuer_name", ""),
                $this->codefy->configContainer->string("transcript.issuer_address", ""),
            );
        });
        $this->codefy->delegate(\Domain\Transcript\Service\TranscriptLayoutCatalog::class, function () {
            return new \Infrastructure\Documents\RegisteredTranscriptLayouts(
                $this->codefy->configContainer->string('transcript.default_layout', 'classic-letter'),
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
        $this->codefy->delegate(\Domain\Person\Service\PersonRegistrationService::class, function () {
            return new \Domain\Person\Service\PersonRegistrationService(
                $this->codefy->make(AdmissionsRepository::class),
                $this->codefy->make(\Domain\Person\Repository\PersonAccountRepository::class),
                $this->codefy->make(\Domain\User\Repository\UserAggregateRepository::class),
                $this->codefy->make(\Domain\Person\Service\ProfileLedger::class),
                $this->codefy->make(\Domain\Person\Repository\AccountSetupRepository::class),
                $this->codefy->make(StudentAccess::class),
                $this->codefy->make(\Domain\Shared\Persistence\UnitOfWork::class),
                $this->codefy->make(\Domain\Audit\Repository\AuditTrail::class),
                $this->codefy->make(\Domain\Shared\Service\Clock::class),
                $this->codefy->make(\Domain\Communications\Service\MessageCipher::class),
                $this->codefy->configContainer->string('app.url', ''),
                $this->codefy->configContainer->integer('auth.password_min_length', 26),
            );
        });
        $this->codefy->delegate(\Domain\Person\Service\AccountSetupService::class, function () {
            return new \Domain\Person\Service\AccountSetupService(
                $this->codefy->make(\Domain\Person\Repository\AccountSetupRepository::class),
                $this->codefy->make(\Domain\User\Repository\UserAggregateRepository::class),
                $this->codefy->make(\Domain\Shared\Persistence\UnitOfWork::class),
                $this->codefy->make(\Domain\Shared\Service\Clock::class),
                $this->codefy->make(\Domain\Communications\Service\MessageCipher::class),
                $this->codefy->make(\Domain\Communications\Service\MailTransport::class),
                $this->codefy->make(\Domain\Person\Service\ProfileLedger::class),
                $this->codefy->make(AdmissionsRepository::class),
                $this->codefy->make(StudentAccess::class),
                $this->codefy->make(\Domain\Audit\Repository\AuditTrail::class),
                $this->codefy->make(\Domain\User\Service\UserAccountAccess::class),
                $this->codefy->configContainer->string('app.url', ''),
                $this->codefy->configContainer->integer('auth.password_min_length', 26),
            );
        });
        // Auth configuration translates messages through the bootstrapped application.
        // Resolve it only when the account service is requested, after provider registration.
        $this->codefy->delegate(\Domain\User\Service\PersonAccountEditor::class, function () {
            return new \Domain\User\Service\PersonAccountEditor(
                $this->codefy->make(AdmissionsRepository::class),
                $this->codefy->make(\Domain\User\Repository\UserAggregateRepository::class),
                $this->codefy->make(\Domain\User\Service\UserAccountAccess::class),
                $this->codefy->make(StudentAccess::class),
                $this->codefy->make(\Domain\Audit\Repository\AuditTrail::class),
                $this->codefy->make(\Domain\Person\Service\ProfileLedger::class),
                $this->codefy->make(\Domain\Shared\EventSourcing\HistoricalEventStore::class),
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
        $this->codefy->alias(
            \Domain\Admissions\Repository\SubmissionProjection::class,
            \Infrastructure\Persistence\Repository\SqlSubmissionProjection::class,
        );
        $this->codefy->alias(StudentAccess::class, GateStudentAccess::class);
    }
}
