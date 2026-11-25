<?php

declare(strict_types=1);

$read = static function (string $path, int $status = 200) use ($app, $get): array {
    $response = $app->router->match(
        $get->withUri(new \Laminas\Diactoros\Uri("https://campus.example.invalid" . $path)),
    );
    if (
        $response->getStatusCode() !== $status ||
        !str_contains($response->getHeaderLine("Cache-Control"), "private, no-store") ||
        $response->hasHeader("phpdebugbar-id")
    ) {
        throw new RuntimeException(
            "Waitlist read lost authorization, private response or debug suppression.",
        );
    }
    return json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
};
$postData = static function (
    string $path,
    array $data,
    int $status = 200
) use (
    $teachingPost,
): array {
    $response = $teachingPost($path, $data);
    if ($response->getStatusCode() !== $status) {
        throw new RuntimeException(
            "Waitlist mutation expected " . $status . ", got " . $response->getStatusCode(),
        );
    }
    return json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
};
$section = $sis->section(capacity: 1);
$filler = $sis->student($sis->user());
$sis->service->register($filler->id, $section->id, true);
$path = "/sis/sections/" . $section->id->value . "/waitlist/";
$policy = $read($path . "policy/");
if ($policy["policy"]["enabled"]) {
    throw new RuntimeException("A section implicitly allowed waitlisting.");
}
$policy["policy"]["enabled"] = true;
$proof = $postData($path . "policy/review/", [
    "policy" => $policy["policy"],
    "version" => 0,
    "reason" => "Fictional section waitlisting approval.",
]);
$postData($path . "policy/confirm/", $proof);
$owner = $sis->user();
$student = $sis->student($owner);
$other = $sis->student($sis->user());
$sis->access->actor = $owner;
$sis->access->staff = false;
$ownerPath = "/portal/api/waitlists/" . $student->id->value . "/";
$sectionPath = $ownerPath . "sections/" . $section->id->value . "/";
$proof = $postData($sectionPath . "review/", ["action" => "requested", "entry_id" => null]);
$accepted = $postData($sectionPath . "confirm/", $proof);
if ($read($sectionPath)["rank"] !== 1 || count($read($ownerPath)["items"]) !== 1) {
    throw new RuntimeException("Owner waitlist place was not retained.");
}
$read("/portal/api/waitlists/" . $other->id->value . "/", 403);
$postData(
    $path . "review/",
    ["student_id" => $student->id->value, "action" => "requested", "entry_id" => null],
    403,
);
$sis->access->staff = true;
$read("/portal/api/waitlists/" . $other->id->value . "/", 403);
$queue = $read($path);
if ($queue["items"][0]["student_id"] !== $student->id->value) {
    throw new RuntimeException("Staff queue lost accepted order.");
}
$removed = $postData($path . "review/", [
    "student_id" => $student->id->value,
    "entry_id" => $accepted["entry_id"],
    "action" => "removed",
    "reason" => "Fictional restricted removal reason.",
]);
$postData($path . "confirm/", ["student_id" => $student->id->value] + $removed);
$sis->access->staff = false;
$mine = $read($ownerPath);
if (
    $mine["items"][0]["status"] !== "removed" ||
    str_contains(json_encode($mine, JSON_THROW_ON_ERROR), "restricted removal reason")
) {
    throw new RuntimeException("Owner request history lost its safe outcome.");
}
$sis->access->staff = true;
$entries = [];
foreach ([$student, $other] as $waiting) {
    $r = $postData($path . 'review/', [
        'student_id' => $waiting->id->value, 'entry_id' => null, 'action' => 'requested',
    ]);
    $entries[] = $postData($path . 'confirm/', ['student_id' => $waiting->id->value] + $r)['entry_id'];
}
$sis->sql->update('sis_sections', ['capacity' => 2], ['id' => $section->id->value]);
$bypassInput = ['student_id' => $other->id->value, 'entry_id' => $entries[1],
    'reason' => 'Fictional restricted bypass <b>reason</b>'];
$sis->access->staff = false;
$sis->access->grants = ['sis:registration:manage', 'sis:registration:waitlist'];
$postData($path . 'bypass/review/', $bypassInput, 403);
$sis->access->grants[] = \Domain\Registration\Waitlist\WaitlistBypass::DUTY;
$r = $postData($path . 'bypass/review/', $bypassInput);
if (!$r['can_confirm'] || $r['earlier_requests'] !== 1) {
    throw new RuntimeException('The actual bypass provider lost its normal registration assessment.');
}
$command = array_intersect_key($r, array_flip(['operation_id', 'student_id', 'person_id', 'entry_id',
    'version', 'assessment_revision', 'reason', 'review_token']));
$accepted = $postData($path . 'bypass/confirm/', $command);
$history = $read($path . 'bypass/history/');
if (
    count($history['items']) !== 1 || $history['items'][0]['reason'] !== $bypassInput['reason'] ||
    $history['items'][0]['enrollment_id'] !== $accepted['enrollment_id']
) {
    throw new RuntimeException('Restricted bypass history lost its accepted evidence.');
}
if ($postData($path . 'bypass/confirm/', $command) !== $accepted) {
    throw new RuntimeException('An accepted bypass changed its receipt.');
}
$sis->access->staff = true;
if ($read($path)['items'][0]['entry_id'] !== $entries[0]) {
    throw new RuntimeException('A bypass removed an earlier waiting request.');
}
$sis->access->staff = false;
$sis->access->grants = [];
$read($path . 'bypass/history/', 403);
$sis->access->actor = $sis->admissions->person($other->personId)->ownerUserId;
$visible = $read('/portal/api/waitlists/' . $other->id->value . '/');
if (
    $visible['items'][0]['status'] !== 'registered' ||
    str_contains(json_encode($visible, JSON_THROW_ON_ERROR), 'restricted bypass')
) {
    throw new RuntimeException('Owner waitlist outcomes exposed private bypass evidence.');
}
echo "Waitlist route checks passed.\n";

// New outcome delivery/read/deletion uses the real account-only private notification routes.
$noticeStaff = $sis->access->actor;
$sis->access->staff = true;
$noticeOwner = $sis->user();
$noticeStudent = $sis->student($noticeOwner);
$noticeSection = $sis->section(capacity: 1);
$sis->service->register($sis->student($sis->user())->id, $noticeSection->id);
$noticeWaitlists = $sis->waitlists();
$noticePolicy = $noticeWaitlists->reviewPolicy(
    $noticeSection->id,
    new \Domain\Registration\Waitlist\WaitlistPolicy(true, 50)->describe(),
    0,
    'Fictional notice policy.'
);
$noticeWaitlists->confirmPolicy(
    $noticeSection->id,
    new \Domain\Shared\ValueObject\EntityId($noticePolicy['operation_id']),
    $noticePolicy['policy'],
    $noticePolicy['version'],
    $noticePolicy['reason'],
    $noticePolicy['review_token']
);
$noticeEntry = null;
foreach (['requested', 'removed'] as $noticeAction) {
    $noticePlan = $noticeWaitlists->review(
        $noticeStudent->id,
        $noticeSection->id,
        $noticeAction,
        true,
        $noticeEntry,
        $noticeAction === 'removed' ? 'Private fictional registrar reason.' : null
    );
    $noticeWaitlists->confirm(
        $noticeStudent->id,
        $noticeSection->id,
        new \Domain\Shared\ValueObject\EntityId($noticePlan['operation_id']),
        $noticeAction,
        true,
        $noticePlan['revision'],
        $noticePlan['review_token'],
        $noticePlan['reason']
    );
    $noticeEntry = new \Domain\Shared\ValueObject\EntityId($noticePlan['entry_id']);
}
$sis->access->actor = $noticeOwner;
$sis->access->staff = false;
$noticePath = '/portal/api/registration-updates/';
$noticePacket = $read($noticePath);
$noticeItem = $noticePacket['items'][0] ?? throw new RuntimeException('The owner notification feed did not resolve.');
if (
    $noticeItem['status'] !== 'removed' || array_key_exists('entry_id', $noticeItem)
    || str_contains(json_encode($noticePacket), 'Private fictional registrar reason.')
) {
    throw new RuntimeException('The owner notification feed disclosed restricted decision evidence.');
}
$noticeEvents = $sis->sql->rows('sis_history_events');
$noticeRead = $teachingPost($noticePath . $noticeItem['id'] . '/read/', ['revision' => $noticeItem['revision']]);
$noticeDelete = $teachingPost($noticePath . $noticeItem['id'] . '/delete/', ['revision' => $noticeItem['revision']]);
if (
    $noticeRead->getStatusCode() !== 200 || $noticeDelete->getStatusCode() !== 200
    || $read($noticePath)['items'] !== []
    || $noticeEvents !== $sis->sql->rows('sis_history_events')
) {
    throw new RuntimeException('Owner read/deletion failed private CSRF routing or changed business history.');
}
$sis->access->actor = $noticeStaff;
$sis->access->staff = true;


// Coordinated queue allocation reuses the actual private group-registration routes and CSRF boundary.
$groupSection = $sis->section(capacity: 1);
$groupFiller = $sis->student($sis->user());
$sis->service->register($groupFiller->id, $groupSection->id, true);
$groupPath = '/sis/sections/' . $groupSection->id->value . '/waitlist/';
$groupPolicy = $postData($groupPath . 'policy/review/', [
    'policy' => new \Domain\Registration\Waitlist\WaitlistPolicy(true, 50)->describe(), 'version' => 0,
    'reason' => 'Fictional coordinated queue policy.',
]);
$postData($groupPath . 'policy/confirm/', $groupPolicy);
$groupStudents = [$sis->student($sis->user()), $sis->student($sis->user())];
$groupStudents = array_reverse($groupStudents);
foreach ($groupStudents as $groupStudent) {
    $groupJoin = $postData($groupPath . 'review/', [
        'student_id' => $groupStudent->id->value, 'action' => 'requested', 'entry_id' => null,
    ]);
    $postData($groupPath . 'confirm/', ['student_id' => $groupStudent->id->value] + $groupJoin);
}
$groupDrop = $sis->drops->review($groupFiller->id, $groupSection->id);
$sis->drops->commit(
    $groupFiller->id,
    $groupSection->id,
    new \Domain\Shared\ValueObject\EntityId($groupDrop['enrollment_id']),
    $groupDrop['version'],
    new \Domain\Shared\ValueObject\EntityId($groupDrop['operation_id']),
    $groupDrop['revision'],
    $groupDrop['effective_on']
);
$sis->sql->update('sis_sections', ['capacity' => 2], ['id' => $groupSection->id->value]);
$groupInput = ['section_id' => $groupSection->id->value,
    'student_ids' => array_map(static fn($student): string => $student->id->value, $groupStudents)];
$groupReview = $postData('/sis/registration-batches/review/', $groupInput);
if (array_column(array_column($groupReview['students'], 'waitlist'), 'can_register') !== [true, true]) {
    throw new RuntimeException('The real group route did not recognize selected queue predecessors.');
}
$groupCommit = $groupInput + array_intersect_key(
    $groupReview,
    array_flip(['operation_id', 'revision', 'review_token'])
);
$sis->access->staff = false;
$sis->access->grants = ['sis:registration:waitlist', 'sis:student:read'];
$postData('/sis/registration-batches/commit/', $groupCommit, 403);
$sis->access->staff = true;
$sis->access->grants = [];
$groupResult = $postData('/sis/registration-batches/commit/', $groupCommit);
if (
    $groupResult['created'] !== 2
    || array_column($groupResult['registrations'], 'student_id') !== $groupInput['student_ids']
    || $read($groupPath)['waiting_count'] !== 0
    || $postData('/sis/registration-batches/commit/', $groupCommit) !== $groupResult
) {
    throw new RuntimeException('The real group route lost accepted FIFO, atomic results or its original receipt.');
}
