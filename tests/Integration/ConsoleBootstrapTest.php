<?php

declare(strict_types=1);

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class ConsoleBootstrapTest extends TestCase
{
    public function testStandingProposalsUseActualProvidersIndependentDutiesCsrfAndPrivateEvidence(): void
    {
        $process = new Process([PHP_BINARY, '-d', 'memory_limit=96M',
            dirname(__DIR__) . '/Support/ConsoleStatusSmoke.php', 'check-standing-proposals']);
        $process->setTimeout(15);
        $process->run();
        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
    }

    public function testProgramCampusRuleUsesActualProvidersOwnerPrivacyAndCsrf(): void
    {
        $process = new Process([PHP_BINARY, '-d', 'memory_limit=96M',
            dirname(__DIR__) . '/Support/ConsoleStatusSmoke.php', 'check-program-campus']);
        $process->setTimeout(15);
        $process->run();
        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
    }

    public function testCurriculumVerificationUsesActualProvidersExclusiveSelectorsPrivateRoutesAndCsrf(): void
    {
        $process = new Process([PHP_BINARY, '-d', 'memory_limit=96M',
            dirname(__DIR__) . '/Support/ConsoleStatusSmoke.php', 'check-curriculum-projections']);
        $process->setTimeout(15);
        $process->run();
        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
    }

    public function testGraduateRegistrationUsesRealProvidersSeparateDutiesPrivateResponsesAndCsrf(): void
    {
        $process = new Process([PHP_BINARY, '-d', 'memory_limit=96M',
            dirname(__DIR__) . '/Support/ConsoleStatusSmoke.php', 'check-graduate-registration']);
        $process->setTimeout(15);
        $process->run();
        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
    }

    public function testPublicProgramDiscoveryUsesExplicitPublicationCsrfAndPrivatePublishingAuthority(): void
    {
        $process = new Process([PHP_BINARY, '-d', 'memory_limit=96M',
            dirname(__DIR__) . '/Support/ConsoleStatusSmoke.php', 'check-program-discovery']);
        $process->setTimeout(12);
        $process->run();
        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
    }

    public function testLoadPublicationChoicesUseActualProvidersCsrfAndIndependentPolicyAuthority(): void
    {
        $process = new Process([PHP_BINARY, '-d', 'memory_limit=96M',
            dirname(__DIR__) . '/Support/ConsoleStatusSmoke.php', 'check-enrollment-load']);
        $process->setTimeout(12);
        $process->run();
        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
    }

    public function testAnnouncementRoutesKeepPrivateOwnerStateCsrfAndExistingPublications(): void
    {
        $process = new Process([PHP_BINARY, '-d', 'memory_limit=96M',
            dirname(__DIR__) . '/Support/ConsoleStatusSmoke.php', 'check-announcements']);
        $process->setTimeout(12);
        $process->run();
        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
    }

    public function testContentPublicationRoutesAndNativeBlocksShareAuthorityPrivacyAndVisibility(): void
    {
        $process = new Process([PHP_BINARY, '-d', 'memory_limit=96M',
            dirname(__DIR__) . '/Support/ConsoleStatusSmoke.php', 'check-content-publication']);
        $process->setTimeout(15);
        $process->run();
        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
    }

    public function testPrerequisiteExceptionRoutesKeepIndependentAuthorityCsrfAndPrivateHistory(): void
    {
        $process = new Process([PHP_BINARY, '-d', 'memory_limit=96M',
            dirname(__DIR__) . '/Support/ConsoleStatusSmoke.php', 'check-prerequisite-permissions']);
        $process->setTimeout(12);
        $process->run();
        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
    }

    public function testRuntimeDegreeAuditUsesActualProvidersCsrfAndPrivateReadOnlyResponses(): void
    {
        $process = new Process([PHP_BINARY, '-d', 'memory_limit=96M',
            dirname(__DIR__) . '/Support/ConsoleStatusSmoke.php', 'check-degree-trials']);
        $process->setTimeout(10);
        $process->run();
        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
    }
    public function testCourseRuleRoutesPreserveProviderCsrfPrivacyAndCatalogAuthority(): void
    {
        $process = new Process([PHP_BINARY, '-d', 'memory_limit=96M',
            dirname(__DIR__) . '/Support/ConsoleStatusSmoke.php', 'check-course-rules']);
        $process->setTimeout(10);
        $process->run();
        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
    }
    public function testMoodleResolutionRoutesUseActualProvidersPrivateResponsesAndCsrf(): void
    {
        $process = new Process([PHP_BINARY, '-d', 'memory_limit=96M',
            dirname(__DIR__) . '/Support/ConsoleStatusSmoke.php', 'check-moodle-resolution']);
        $process->setTimeout(10);
        $process->run();
        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
    }

    public function testHistoryVerificationRegistersLazilyAndReturnsPrivateBoundedResults(): void
    {
        $process = new Process([PHP_BINARY, '-d', 'memory_limit=96M',
            dirname(__DIR__) . '/Support/ConsoleStatusSmoke.php', 'check-history-verification']);
        $process->setTimeout(10);
        $process->run();
        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
    }

    public function testIndependentHistoryCheckpointsUseActualLazyReadOnlyProvidersAndPrivateFiles(): void
    {
        $process = new Process([PHP_BINARY, '-d', 'memory_limit=96M',
            dirname(__DIR__) . '/Support/ConsoleStatusSmoke.php', 'check-history-anchor']);
        $process->setTimeout(12);
        $process->run();
        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
    }

    public function testAccountProjectionVerificationUsesActualLazyProvidersAndReadOnlyCommands(): void
    {
        $process = new Process([PHP_BINARY, '-d', 'memory_limit=96M',
            dirname(__DIR__) . '/Support/ConsoleStatusSmoke.php', 'check-account-projections']);
        $process->setTimeout(10);
        $process->run();
        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
    }

    public function testReportRetentionIsRegisteredPreviewOnlyByDefaultAndBounded(): void
    {
        $process = new Process([PHP_BINARY, '-d', 'memory_limit=96M',
            dirname(__DIR__) . '/Support/ConsoleStatusSmoke.php', 'check-report-retention']);
        $process->setTimeout(10);
        $process->run();
        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
    }

    public function testReportExportsUseActualProvidersPrivateRoutesCsrfAndBackgroundAuthority(): void
    {
        $process = new Process([PHP_BINARY, '-d', 'memory_limit=96M',
            dirname(__DIR__) . '/Support/ConsoleStatusSmoke.php', 'check-report-exports']);
        $process->setTimeout(12);
        $process->run();
        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
    }

    public function testSessionRetentionIsRegisteredPreviewOnlyByDefaultAndBounded(): void
    {
        $process = new Process([PHP_BINARY, '-d', 'memory_limit=96M',
            dirname(__DIR__) . '/Support/ConsoleStatusSmoke.php', 'check-session-retention']);
        $process->setTimeout(10);
        $process->run();
        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
    }

    public function testRealLoginLogoutRevocationAndReauthenticationKeepPrivateCsrfBoundaries(): void
    {
        $process = new Process([PHP_BINARY, '-d', 'memory_limit=96M',
            dirname(__DIR__) . '/Support/ConsoleStatusSmoke.php', 'check-login-sessions']);
        $process->setTimeout(10);
        $process->run();
        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
    }
    public function testDisabledMoodleCheckResolvesThroughActualProvidersWithoutDatabaseOrNetworkUse(): void
    {
        $process = new Process([PHP_BINARY, '-d', 'memory_limit=96M',
            dirname(__DIR__) . '/Support/ConsoleStatusSmoke.php', 'check-moodle']);
        $process->setTimeout(6);
        $process->run();
        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
    }
    public function testWebhookCanResolveBeforeBootingTheHttpRouter(): void
    {
        $process = new Process([PHP_BINARY, '-d', 'memory_limit=96M',
            dirname(__DIR__) . '/Support/ConsoleStatusSmoke.php', 'check-early-webhook']);
        $process->setTimeout(6);
        $process->run();
        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
    }

    public function testPrerequisiteGroupsUseActualProvidersPrivateRoutesAndCsrf(): void
    {
        $process = new Process([PHP_BINARY, '-d', 'memory_limit=96M',
            dirname(__DIR__) . '/Support/ConsoleStatusSmoke.php', 'check-prerequisite-groups']);
        $process->setTimeout(10);
        $process->run();
        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
    }

    public function testProgramClassificationRoutesUseActualProvidersCsrfAndPrivatePublicationHistory(): void
    {
        $process = new Process([PHP_BINARY, '-d', 'memory_limit=96M',
            dirname(__DIR__) . '/Support/ConsoleStatusSmoke.php', 'check-program-classifications']);
        $process->setTimeout(10);
        $process->run();
        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
    }

    public function testPortalRoutesCsrfAndNativeVihzhuoBlocksBootWithinBoundedMemory(): void
    {
        $process = new Process([PHP_BINARY, '-d', 'memory_limit=128M',
            dirname(__DIR__) . '/Support/ConsoleStatusSmoke.php', 'check-portal']);
        // This growing whole-workspace fixture makes hundreds of HTTP calls in one bounded process.
        $process->setTimeout(25);
        $process->run();
        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
    }

    public function testAdmissionChecklistReadinessUsesAnIsolatedPrivateHttpFixture(): void
    {
        $process = new Process([
            PHP_BINARY,
            "-d",
            "memory_limit=96M",
            dirname(__DIR__) . "/Support/ConsoleStatusSmoke.php",
            "check-admission-readiness",
        ]);
        $process->setTimeout(10);
        $process->run();
        self::assertSame(
            0,
            $process->getExitCode(),
            $process->getErrorOutput(),
        );
    }
    public function testSectionClassificationsUseActualProvidersCsrfAndPrivatePublications(): void
    {
        $process = new Process([PHP_BINARY, '-d', 'memory_limit=96M',
            dirname(__DIR__) . '/Support/ConsoleStatusSmoke.php', 'check-section-classifications']);
        $process->setTimeout(10);
        $process->run();
        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
    }

    public function testGradeChangesUseActualProvidersCsrfIndependentAuthorityAndPrivateResponses(): void
    {
        $process = new Process([PHP_BINARY, '-d', 'memory_limit=96M',
            dirname(__DIR__) . '/Support/ConsoleStatusSmoke.php', 'check-grade-changes']);
        $process->setTimeout(10);
        $process->run();
        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
    }
    public function testTransferCorrectionsUseActualProvidersCsrfExpiringReviewsAndPrivateResponses(): void
    {
        $process = new Process([PHP_BINARY, '-d', 'memory_limit=96M',
            dirname(__DIR__) . '/Support/ConsoleStatusSmoke.php', 'check-transfer-corrections']);
        $process->setTimeout(10);
        $process->run();
        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
    }

    public function testRegisteredCreditsUseActualProvidersCsrfIndependentAuthorityAndPrivateLoadViews(): void
    {
        $process = new Process([PHP_BINARY, '-d', 'memory_limit=96M',
            dirname(__DIR__) . '/Support/ConsoleStatusSmoke.php', 'check-registered-credits']);
        $process->setTimeout(10);
        $process->run();
        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
    }

    public function testEarnedCeusUseActualProvidersCsrfIndependentAuthorityAndPrivateResponses(): void
    {
        $process = new Process([PHP_BINARY, '-d', 'memory_limit=96M',
            dirname(__DIR__) . '/Support/ConsoleStatusSmoke.php', 'check-earned-ceus']);
        $process->setTimeout(10);
        $process->run();
        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
    }

    public function testCeusUseActualProvidersCsrfIndependentAuthorityAndPrivateResponses(): void
    {
        $process = new Process([PHP_BINARY, '-d', 'memory_limit=96M',
            dirname(__DIR__) . '/Support/ConsoleStatusSmoke.php', 'check-ceus']);
        $process->setTimeout(10);
        $process->run();
        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
    }

    public function testCreditPoliciesUseActualProvidersCsrfIndependentAuthorityAndPrivateResponses(): void
    {
        $process = new Process([PHP_BINARY, '-d', 'memory_limit=96M',
            dirname(__DIR__) . '/Support/ConsoleStatusSmoke.php', 'check-credit-policies']);
        $process->setTimeout(10);
        $process->run();
        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
    }

    public function testWebsitePublishingUsesActualProvidersCsrfPrivateTransportAndIndependentAuthority(): void
    {
        $process = new Process([PHP_BINARY, '-d', 'memory_limit=96M',
            dirname(__DIR__) . '/Support/ConsoleStatusSmoke.php', 'check-website-publications']);
        $process->setTimeout(10);
        $process->run();
        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
    }

    public function testCurriculumReportingUsesActualProvidersPrivateTransportAndIndependentDataPermissions(): void
    {
        $process = new Process([PHP_BINARY, '-d', 'memory_limit=96M',
            dirname(__DIR__) . '/Support/ConsoleStatusSmoke.php', 'check-curriculum-reports']);
        $process->setTimeout(10);
        $process->run();
        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
    }

    public function testComponentMajorLinksUseActualProvidersExplicitBodiesAndPrivateSelection(): void
    {
        $process = new Process([PHP_BINARY, '-d', 'memory_limit=96M',
            dirname(__DIR__) . '/Support/ConsoleStatusSmoke.php', 'check-component-major-links']);
        $process->setTimeout(10);
        $process->run();
        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
    }

    public function testAcademicOrganizationUsesActualProvidersCsrfPrivateResponsesAndScopedAuthority(): void
    {
        $process = new Process([PHP_BINARY, '-d', 'memory_limit=96M',
            dirname(__DIR__) . '/Support/ConsoleStatusSmoke.php', 'check-academic-organization']);
        $process->setTimeout(10);
        $process->run();
        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
    }

    public function testCurriculumComponentsUseActualProvidersCsrfRetainedSelectionAndPrivateResponses(): void
    {
        $process = new Process([PHP_BINARY, '-d', 'memory_limit=96M',
            dirname(__DIR__) . '/Support/ConsoleStatusSmoke.php', 'check-curriculum-components']);
        $process->setTimeout(10);
        $process->run();
        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
    }

    public function testReinstatementUsesActualProvidersCsrfIndependentDutiesAndPrivateResponses(): void
    {
        $process = new Process([PHP_BINARY, '-d', 'memory_limit=96M',
            dirname(__DIR__) . '/Support/ConsoleStatusSmoke.php', 'check-enrollment-reinstatement']);
        $process->setTimeout(10);
        $process->run();
        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
    }

    public function testWaitlistsUseActualProvidersCsrfStrictOwnerScopeAndPrivateResponses(): void
    {
        $process = new Process([PHP_BINARY, '-d', 'memory_limit=96M',
            dirname(__DIR__) . '/Support/ConsoleStatusSmoke.php', 'check-waitlists']);
        $process->setTimeout(10);
        $process->run();
        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
    }

    public function testWorkloadPoliciesUseActualProvidersCsrfOwnerPrivacyAndIndependentDuties(): void
    {
        $process = new Process([PHP_BINARY, '-d', 'memory_limit=96M',
            dirname(__DIR__) . '/Support/ConsoleStatusSmoke.php', 'check-workload-policy']);
        $process->setTimeout(10);
        $process->run();
        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
    }

    public function testClassGradeCertificationUsesActualProvidersCsrfAndPrivateScopedReads(): void
    {
        $process = new Process([PHP_BINARY, '-d', 'memory_limit=96M',
            dirname(__DIR__) . '/Support/ConsoleStatusSmoke.php', 'check-grade-certification']);
        $process->setTimeout(10);
        $process->run();
        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
    }

    public function testGradebookCalculationUsesActualProvidersCsrfAndPrivateReads(): void
    {
        $process = new Process([PHP_BINARY, '-d', 'memory_limit=96M',
            dirname(__DIR__) . '/Support/ConsoleStatusSmoke.php', 'check-gradebook-calculation']);
        $process->setTimeout(10);
        $process->run();
        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
    }

    public function testRegistrationTimetableUsesActualProvidersCsrfOwnerChecksAndIndependentPolicyAuthority(): void
    {
        $process = new Process([PHP_BINARY, '-d', 'memory_limit=96M',
            dirname(__DIR__) . '/Support/ConsoleStatusSmoke.php', 'check-registration-schedule']);
        $process->setTimeout(10);
        $process->run();
        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
    }

    public function testTranscriptReviewsUseActualProvidersCsrfAndPrivateDocuments(): void
    {
        $process = new Process([PHP_BINARY, '-d', 'memory_limit=96M',
            dirname(__DIR__) . '/Support/ConsoleStatusSmoke.php', 'check-transcript-reviews']);
        $process->setTimeout(10);
        $process->run();
        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
    }

    public function testHiatusHistoryAndReturnUseActualProvidersCsrfAndPrivateEvidence(): void
    {
        $process = new Process([PHP_BINARY, '-d', 'memory_limit=96M',
            dirname(__DIR__) . '/Support/ConsoleStatusSmoke.php', 'check-hiatus-history']);
        $process->setTimeout(10);
        $process->run();
        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
    }

    public function testDropPolicyPublicationUsesActualProvidersCsrfAndPrivateHistory(): void
    {
        $process = new Process([PHP_BINARY, '-d', 'memory_limit=96M',
            dirname(__DIR__) . '/Support/ConsoleStatusSmoke.php', 'check-drop-policy']);
        $process->setTimeout(10);
        $process->run();
        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
    }

    public function testProfileUpdatesUseTheConfiguredEventStoreThroughTheCommandBus(): void
    {
        $process = new Process([PHP_BINARY, '-d', 'memory_limit=96M',
            dirname(__DIR__) . '/Support/ConsoleStatusSmoke.php', 'check-profile-command']);
        $process->setTimeout(8);
        $process->run();
        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
    }

    public function testCourseRouteResolvesDependenciesAndReturnsJson(): void
    {
        $process = new Process([PHP_BINARY, '-d', 'memory_limit=96M',
            dirname(__DIR__) . '/Support/ConsoleStatusSmoke.php', 'check-course-route']);
        $process->setTimeout(6);
        $process->run();
        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
    }

    public function testMigrationStatusBootsWithinBoundedMemoryWithoutRunningMigrations(): void
    {
        $process = new Process([PHP_BINARY, '-d', 'memory_limit=96M',
            dirname(__DIR__) . '/Support/ConsoleStatusSmoke.php', 'migrate:status']);
        $process->setTimeout(6);
        $process->run();
        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        self::assertStringContainsString('CreateSisWorkspaceTables', $process->getOutput());
        self::assertStringContainsString('| down', $process->getOutput());
        self::assertStringNotContainsString('| up', $process->getOutput());
    }

    public function testAuthenticationAliasResolvesWithDatabaseStorage(): void
    {
        $process = new Process([PHP_BINARY, '-d', 'memory_limit=96M',
            dirname(__DIR__) . '/Support/ConsoleStatusSmoke.php', 'check-authentication']);
        $process->setTimeout(6);
        $process->run();
        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
    }
    public function testCourseworkPolicyUsesActualProvidersCsrfAndIndependentAuthority(): void
    {
        $process = new Process([PHP_BINARY, '-d', 'memory_limit=96M',
            dirname(__DIR__) . '/Support/ConsoleStatusSmoke.php', 'check-coursework-grade-policy']);
        $process->setTimeout(10);
        $process->run();
        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
    }

    public function testGroupEligibilityUsesActualProvidersCsrfAndPrivateCatalogAuthority(): void
    {
        $process = new Process([PHP_BINARY, '-d', 'memory_limit=96M',
            dirname(__DIR__) . '/Support/ConsoleStatusSmoke.php', 'check-group-work-eligibility']);
        $process->setTimeout(10);
        $process->run();
        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
    }

    public function testRecordedProgressUsesActualProvidersCsrfPrivateResponsesAndStrictOwnerScope(): void
    {
        $process = new Process([PHP_BINARY, '-d', 'memory_limit=96M',
            dirname(__DIR__) . '/Support/ConsoleStatusSmoke.php', 'check-recorded-progress']);
        $process->setTimeout(15);
        $process->run();
        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
    }

    public function testPrimaryPositionUsesActualProvidersCsrfAndPrivateOwnerScope(): void
    {
        $process = new Process([PHP_BINARY, '-d', 'memory_limit=96M',
            dirname(__DIR__) . '/Support/ConsoleStatusSmoke.php', 'check-primary-position']);
        $process->setTimeout(15);
        $process->run();
        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
    }

    public function testHistoricalStudentFinanceUsesActualProvidersCsrfAndSourcePermissions(): void
    {
        $process = new Process([PHP_BINARY, '-d', 'memory_limit=96M',
            dirname(__DIR__) . '/Support/ConsoleStatusSmoke.php', 'check-historical-finance']);
        $process->setTimeout(15);
        $process->run();
        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
    }
}
