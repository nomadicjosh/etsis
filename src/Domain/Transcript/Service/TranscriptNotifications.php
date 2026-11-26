<?php

declare(strict_types=1);

namespace Domain\Transcript\Service;

use DateTimeZone;
use Domain\Admissions\Repository\AdmissionsRepository;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Service\Clock;
use Domain\Shared\ValueObject\DerivedIdentity;
use Domain\Shared\ValueObject\EntityId;
use Domain\Transcript\Repository\TranscriptRepository;
use Domain\Transcript\Repository\TranscriptUpdatesRepository;

use function Codefy\Framework\Helpers\trans_html;

/** Called only inside a newly accepted preparation/delivery transaction, never on original-result retries. */
final readonly class TranscriptNotifications
{
    public function __construct(
        private TranscriptUpdatesRepository $updates,
        private TranscriptRepository $records,
        private AdmissionsRepository $people,
        private Clock $clock,
    ) {
    }

    public function record(EntityId $request, EntityId $document, EntityId $operation, string $status): array
    {
        $row = $this->records->request($request);
        $latest = $this->records->latest($request);
        $person = $this->people->person($this->people->student(new EntityId($row["student_id"]))->personId);
        if (
            !in_array($status, ["ready", "fulfilled"], true) ||
            $row["status"] !== $status ||
            (int) $row["version"] < 1 ||
            ($latest["id"] ?? null) !== $document->value ||
            (int) $latest["prepared_version"] !== (int) $row["version"] - (int) ($status === "fulfilled")
        ) {
            throw new ConcurrentModificationException(trans_html("The transcript outcome changed. Review again."));
        }
        $reference = null;
        // A replacement account must never inherit the original request's personal notice.
        if ($person->ownerUserId?->value === $row["owner_user_id"]) {
            $this->updates->replace([
                "id" => DerivedIdentity::from($operation, "transcript.outcome-notification")->value,
                "owner_user_id" => $row["owner_user_id"],
                "person_id" => $person->id->value,
                "student_id" => $row["student_id"],
                "request_id" => $request->value,
                "document_id" => $document->value,
                "revision" => (int) $row["version"],
                "status" => $status,
                "recorded_at" => $this->clock
                    ->now()
                    ->setTimezone(new DateTimeZone("UTC"))
                    ->format("Y-m-d\TH:i:s.u\Z"),
                "read_at" => null,
            ]);
            $reference = $this->updates->current($request);
            if ($reference === null) {
                throw new ConcurrentModificationException(
                    trans_html("The transcript notification was not saved."),
                );
            }
        }
        return [
            "request" => $row,
            "latest" => $latest,
            "person_id" => $person->id->value,
            "owner" => $person->ownerUserId?->value,
            "reference" => $reference,
        ];
    }

    public function verify(#[\SensitiveParameter] array $context): void
    {
        $row = $context["request"];
        $request = new EntityId($row["id"]);
        $person = $this->people->person($this->people->student(new EntityId($row["student_id"]))->personId);
        if (
            $person->id->value !== $context["person_id"] ||
            $person->ownerUserId?->value !== $context["owner"] ||
            $this->records->request($request) !== $row ||
            $this->records->latest($request) !== $context["latest"] ||
            ($context["reference"] !== null && $this->updates->current($request) !== $context["reference"])
        ) {
            throw new ConcurrentModificationException(
                trans_html("The transcript outcome, notification or owner changed before completion."),
            );
        }
    }
}
