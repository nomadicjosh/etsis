<?php

declare(strict_types=1);

namespace Tests\Integration;

use Domain\Audit\Repository\AuditTrail;
use Domain\Person\Service\NotificationPreferencesService;
use Domain\Person\ValueObject\NotificationPreferences;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Infrastructure\Identity\RandomInstitutionalIdGenerator;
use Infrastructure\Persistence\Repository\SqlNotificationPreferencesRepository;
use Infrastructure\Persistence\Repository\SqlPersonAccountRepository;
use PHPUnit\Framework\TestCase;
use SisFixture;

final class NotificationPreferencesTest extends TestCase
{
    private function fixture(?AuditTrail $audit = null): array
    {
        require_once dirname(__DIR__) . "/Support/SisFixture.php";
        $f = new SisFixture();
        $student = $f->student($f->user());
        $person = $f->admissions->person($student->personId);
        $service = new NotificationPreferencesService(
            new SqlNotificationPreferencesRepository($f->sql),
            $f->admissions,
            new SqlPersonAccountRepository($f->sql, $f->admissions, new RandomInstitutionalIdGenerator()),
            $f->access,
            $f->transactions,
            $audit ?? $f->audit,
        );
        return [$f, $person, $service];
    }

    public function testPreferencesArePerPersonAndDefaultReadsWriteNothing(): void
    {
        [$f, $person, $service] = $this->fixture();
        $before = $f->sql->rows("sis_history_events");
        self::assertSame(NotificationPreferences::DEFAULTS, $service->view($person->id)["preferences"]);
        self::assertSame([], $f->sql->rows("sis_notification_preferences"));
        $saved = $service->save(
            $person->id,
            0,
            new NotificationPreferences(["sound_id" => "piano", "keep_visible" => true, "sound" => true]),
        );
        self::assertFalse($saved["own"]);
        self::assertSame(1, $saved["revision"]);
        $f->access->actor = $person->ownerUserId;
        $f->access->staff = false;
        self::assertSame($person->id->value, $service->currentPerson()->value);
        self::assertTrue($service->view($person->id)["own"]);
        self::assertSame("piano", $service->view($person->id)["preferences"]["sound_id"]);
        self::assertSame($before, $f->sql->rows("sis_history_events"));
        self::assertCount(1, $f->sql->rows("sis_notification_preferences"));
    }

    public function testForeignOwnersCannotReadOrChangeAnotherPersonsPreferences(): void
    {
        [$f, $person, $service] = $this->fixture();
        $f->access->staff = false;
        foreach (["view", "save"] as $action) {
            try {
                $action === "view"
                ? $service->view($person->id)
                : $service->save($person->id, 0, new NotificationPreferences());
                self::fail("A foreign person preference was accessible.");
            } catch (AccessDeniedException) {
                self::assertSame([], $f->sql->rows("sis_notification_preferences"));
            }
        }
    }

    public function testStaleSavesDoNotOverwriteCurrentValuesAndNoOpSavesDoNotBumpRevision(): void
    {
        [, $person, $service] = $this->fixture();
        $values = new NotificationPreferences(["sound" => true, "sound_id" => "bird", "keep_visible" => false]);
        $first = $service->save($person->id, 0, $values);
        self::assertSame($first, $service->save($person->id, 1, $values));
        $this->expectException(ConcurrentModificationException::class);
        $service->save($person->id, 0, new NotificationPreferences());
    }

    public function testInvalidSettingsAndArbitrarySoundSourcesAreRejected(): void
    {
        $invalid = [
            [],
            ["sound" => 1, "sound_id" => "tone", "keep_visible" => false],
            ["sound" => true, "sound_id" => "/private.wav", "keep_visible" => false],
            ["sound" => true, "sound_id" => "tone", "keep_visible" => null],
            NotificationPreferences::DEFAULTS + ["extra" => true],
        ];
        foreach ($invalid as $values) {
            try {
                new NotificationPreferences($values);
                self::fail("Invalid notification preferences were accepted.");
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    public function testRequiredAuditAndFinalOwnershipChecksRollbackPreferenceWrites(): void
    {
        foreach (["audit", "owner", "authority", "stored"] as $failure) {
            $f = $person = null;
            $audit = $this->createStub(AuditTrail::class);
            $audit->method("record")->willReturnCallback(function () use (&$f, &$person, $failure): void {
                match ($failure) {
                    "audit" => throw new \RuntimeException("Fictional required audit failure"),
                    "owner" => $f->sql->update(
                        "sis_persons",
                        ["owner_user_id" => $f->access->actor->value],
                        ["id" => $person->id->value],
                    ),
                    "authority" => ($f->access->staff = false),
                    "stored" => $f->sql->update(
                        "sis_notification_preferences",
                        ["sound_id" => "tone"],
                        ["id" => $person->id->value],
                    ),
                };
            });
            [$f, $person, $service] = $this->fixture($audit);
            $people = $f->sql->rows("sis_persons");
            try {
                $service->save(
                    $person->id,
                    0,
                    new NotificationPreferences(["sound" => true, "sound_id" => "bird", "keep_visible" => false]),
                );
                self::fail("A failed preference write committed.");
            } catch (\RuntimeException | AccessDeniedException | ConcurrentModificationException) {
                self::assertSame([], $f->sql->rows("sis_notification_preferences"));
                self::assertSame($people, $f->sql->rows("sis_persons"));
            }
        }
    }
}
