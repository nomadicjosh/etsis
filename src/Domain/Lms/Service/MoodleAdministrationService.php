<?php

declare(strict_types=1);

namespace Domain\Lms\Service;

use Domain\Audit\Repository\AuditTrail;
use Domain\Lms\Exception\MoodleConnectionUnavailableException;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Security\StudentAccess;
use Domain\Shared\ValueObject\EntityId;
use Throwable;

/** Private metadata only. No credential reads, user lookups, mappings or synchronization commands. */
final readonly class MoodleAdministrationService
{
    public const string MANAGE = "sis:moodle:manage";
    public function __construct(
        private MoodleConnectionProbe $probe,
        private StudentAccess $access,
        private AuditTrail $audit,
    ) {
    }
    /** @return array{enabled:bool} */
    public function status(): array
    {
        $actor = $this->access->requirePermission(self::MANAGE);
        $enabled = $this->probe->enabled();
        $this->actor($actor);
        return ["enabled" => $enabled];
    }
    /** @return array{enabled:bool,capabilities:?array<string,bool>} */
    public function check(): array
    {
        $actor = $this->access->requirePermission(self::MANAGE);
        if (!$this->probe->enabled()) {
            $this->actor($actor);
            return ["enabled" => false, "capabilities" => null];
        }
        // Required consent/action metadata commits before the external read. No network work under SQL locks.
        $this->audit->record($actor, "moodle.connection_check_requested", $actor);
        $this->actor($actor);
        try {
            $result = $this->probe->check();
        } catch (Throwable) {
            $this->actor($actor);
            throw new MoodleConnectionUnavailableException();
        }
        $this->actor($actor);
        if (!$this->probe->enabled()) {
            throw new MoodleConnectionUnavailableException();
        }
        return ["enabled" => true, "capabilities" => $result->describe()];
    }
    private function actor(EntityId $actor): void
    {
        if ($this->access->requirePermission(self::MANAGE)->value !== $actor->value) {
            throw new AccessDeniedException("The administrator changed. Reload before continuing.");
        }
    }
}
