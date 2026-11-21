<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class ConsoleBootstrapTest extends TestCase
{
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
        $process = new Process([PHP_BINARY, '-d', 'memory_limit=96M',
            dirname(__DIR__) . '/Support/ConsoleStatusSmoke.php', 'check-portal']);
        // This growing whole-workspace fixture makes hundreds of HTTP calls in one bounded process.
        $process->setTimeout(15);
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
}
