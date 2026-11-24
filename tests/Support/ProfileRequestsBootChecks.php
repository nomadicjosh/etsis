<?php

declare(strict_types=1);

// Actual routes and providers with fictional accounts, configuration and in-memory storage only.
require_once __DIR__ . '/PersonManagementFixture.php';
$management = new PersonManagementFixture();
$httpSis = $management->sis;
require __DIR__ . "/SisHttpFixture.php";
$app->delegate(\Domain\User\Repository\UserAggregateRepository::class, static fn () => $management->users);
$staff = $sis->access->actor;
$owner = $sis->user();
$person = $sis->student($owner)->personId;
$base = "/sis/ui/people/" . $person->value . "/update-requests/";
$id = \Domain\Shared\ValueObject\EntityId::generate();
$events = $sis->sql->rows("sis_history_events");
$created = $teachingPost($base, [
    "request_id" => $id->value,
    "area" => "education",
    "message" => "Fictional private next step.",
]);
if ($created->getStatusCode() !== 200) {
    throw new RuntimeException(
        "Profile request creation failed through actual CSRF-protected providers.",
    );
}
$read = $app->router->match($get->withUri(new \Laminas\Diactoros\Uri($base)));
if (
    $read->getStatusCode() !== 200 ||
    !str_contains($read->getHeaderLine("Cache-Control"), "no-store") ||
    str_contains((string) $read->getBody(), "phpdebugbar") ||
    !str_contains((string) $read->getBody(), "Fictional private next step.")
) {
    throw new RuntimeException("The private staff profile-request response failed.");
}
$sis->access->actor = $owner;
$sis->access->staff = false;
$sis->access->grants = [];
$ownRead = $app->router->match(
    $get->withUri(new \Laminas\Diactoros\Uri("/portal/api/profile-update-requests/")),
);
if (
    $ownRead->getStatusCode() !== 200 ||
    str_contains((string) $ownRead->getBody(), "owner_user_id") ||
    str_contains((string) $ownRead->getBody(), "receipts") ||
    !str_contains($ownRead->getHeaderLine("Cache-Control"), "no-store")
) {
    throw new RuntimeException("Owner request listing leaked internal data or failed ownership.");
}
if ($events !== $sis->sql->rows('sis_history_events')) {
    throw new RuntimeException('Education follow-up fabricated profile facts before an actual edit.');
}
$education = $teachingPost('/portal/api/education/', [
    'version' => 0, 'reason' => '', 'entries' => [
        ['key' => 'school_one', 'institution' => 'Fictional corrected school'],
    ],
]);
if ($education->getStatusCode() !== 200) {
    throw new RuntimeException('The owner education edit failed through the actual protected provider.');
}
$events = $sis->sql->rows('sis_history_events');
$filtered = $app->router->match($get->withUri(new \Laminas\Diactoros\Uri(
    '/portal/api/profile-update-requests/?area=education',
))->withQueryParams(['area' => 'education']));
$filteredData = json_decode((string) $filtered->getBody(), true, flags: JSON_THROW_ON_ERROR);
if (
    $filtered->getStatusCode() !== 200 || count($filteredData['items']) !== 1
    || $filteredData['items'][0]['area'] !== 'education'
) {
    throw new RuntimeException('The real owner education-only follow-up route failed.');
}
$invalidFilter = $app->router->match($get->withUri(new \Laminas\Diactoros\Uri(
    '/portal/api/profile-update-requests/?area=credential_award',
))->withQueryParams(['area' => 'credential_award']));
if ($invalidFilter->getStatusCode() !== 422) {
    throw new RuntimeException('An unknown follow-up area filter was accepted.');
}
$response = $teachingPost("/portal/api/profile-update-requests/" . $id->value . "/respond/", [
    "version" => 0,
    "message" => "Fictional details already correct.",
]);
if ($response->getStatusCode() !== 200) {
    throw new RuntimeException("Owner response failed its actual private route.");
}
$denied = $teachingPost($base . $id->value . "/resolve/", [
    "version" => 1,
    "action" => "close",
    "message" => "Cannot self-approve.",
]);
if ($denied->getStatusCode() !== 403) {
    throw new RuntimeException("Owning a request granted staff closure authority.");
}
$sis->access->actor = $staff;
$sis->access->staff = true;
$closed = $teachingPost($base . $id->value . "/resolve/", [
    "version" => 1,
    "action" => "close",
    "message" => "Fictional reviewed outcome.",
]);
if ($closed->getStatusCode() !== 200) {
    throw new RuntimeException("Staff closure failed the actual provider route.");
}
$finished = $app->router->match(
    $get->withUri(new \Laminas\Diactoros\Uri($base))->withQueryParams(["finished" => "1"]),
);
$data = json_decode((string) $finished->getBody(), true, flags: JSON_THROW_ON_ERROR);
if (
    $finished->getStatusCode() !== 200 ||
    ($data["items"][0]["status"] ?? null) !== "closed" ||
    str_contains((string) $finished->getBody(), "phpdebugbar") ||
    $events !== $sis->sql->rows("sis_history_events")
) {
    throw new RuntimeException(
        "Finished paging changed profile facts or lost the private query boundary.",
    );
}


// Identity follow-up reuses genuine person/account events; replies do not change canonical identity.
$identityPerson = $management->person();
$identityBase = '/sis/ui/people/' . $identityPerson->id->value . '/update-requests/';
$identityRequest = \Domain\Shared\ValueObject\EntityId::generate();
$identityJournal = $sis->sql->rows('sis_history_events');
if (
    $teachingPost($identityBase, ['request_id' => $identityRequest->value, 'area' => 'identity',
    'message' => 'Fictional recorded name needs review.'])->getStatusCode() !== 200
) {
    throw new RuntimeException('The identity follow-up could not be created through the real private route.');
}
$sis->access->actor = $identityPerson->ownerUserId;
$sis->access->staff = false;
$sis->access->grants = [];
$identityRoute = $get->withUri(new \Laminas\Diactoros\Uri('/portal/api/profile-update-requests/identity/'));
$identityRead = $app->router->match($identityRoute);
$identity = json_decode((string) $identityRead->getBody(), true, flags: JSON_THROW_ON_ERROR);
if (
    $identityRead->getStatusCode() !== 200
    || array_keys($identity) !== ['given_name', 'family_name', 'institutional_id']
    || $identity['given_name'] !== 'Alex' || !str_contains($identityRead->getHeaderLine('Cache-Control'), 'no-store')
    || str_contains((string) $identityRead->getBody(), 'phpdebugbar')
) {
    throw new RuntimeException('The owner identity reader failed verification, minimization or privacy.');
}
if (
    $teachingPost('/portal/api/profile-update-requests/' . $identityRequest->value . '/respond/', [
    'version' => 0, 'message' => 'Fictional revised spelling: Alexis Example.',
    ])->getStatusCode() !== 200 || $identityJournal !== $sis->sql->rows('sis_history_events')
) {
    throw new RuntimeException('An identity reply changed a canonical identity or failed its protected route.');
}
$profilePath = '/sis/ui/people/' . $identityPerson->id->value . '/profile/';
if ($app->router->match($get->withUri(new \Laminas\Diactoros\Uri($profilePath)))->getStatusCode() !== 403) {
    throw new RuntimeException('A follow-up owner gained staff identity editing authority.');
}
$sis->access->actor = $owner; // Another account cannot select the original person through an owner query.
$otherIdentity = $app->router->match($identityRoute->withQueryParams(['person_id' => $identityPerson->id->value]));
if (
    $otherIdentity->getStatusCode() !== 200
    || str_contains((string) $otherIdentity->getBody(), $identityPerson->institutionalId->value)
) {
    throw new RuntimeException('An owner identity query selected another person.');
}
$sis->access->actor = $staff;
$sis->access->staff = true;
$profileResponse = $app->router->match($get->withUri(new \Laminas\Diactoros\Uri($profilePath)));
$profile = json_decode((string) $profileResponse->getBody(), true, flags: JSON_THROW_ON_ERROR);
$change = ['given_name' => 'Alexis', 'family_name' => 'Example', 'directory_restricted' => true,
    'revision' => $profile['revision'], 'reason' => 'Fictional evidence for the name correction.'];
$withoutReason = $change;
unset($withoutReason['reason']);
if (
    $teachingPost($profilePath, $withoutReason)->getStatusCode() !== 422
    || $identityJournal !== $sis->sql->rows('sis_history_events')
) {
    throw new RuntimeException('A profile correction without its reason wrote canonical facts.');
}
if ($teachingPost($profilePath, $change)->getStatusCode() !== 200) {
    throw new RuntimeException('The existing canonical name editor failed its private CSRF-protected write.');
}
$changedJournal = $sis->sql->rows('sis_history_events');
$account = $sis->sql->one('users', ['user_id' => $identityPerson->ownerUserId->value]);
if (
    $account['first_name'] !== 'Alexis' || $changedJournal === $identityJournal
    || $sis->admissions->person($identityPerson->id)->givenName !== 'Alexis'
    || $teachingPost($identityBase . $identityRequest->value . '/resolve/', [
        'version' => 1, 'action' => 'close', 'message' => 'Fictional evidence verified and canonical name corrected.',
    ])->getStatusCode() !== 200 || $changedJournal !== $sis->sql->rows('sis_history_events')
) {
    throw new RuntimeException(
        'Name correction lost canonical account history or request closure became an identity decision.',
    );
}
$sis->access->actor = $identityPerson->ownerUserId;
$sis->access->staff = false;
$sis->access->grants = [];
$updatedIdentity = json_decode(
    (string) $app->router->match($identityRoute)->getBody(),
    true,
    flags: JSON_THROW_ON_ERROR,
);
if (
    $updatedIdentity['given_name'] !== 'Alexis'
    || $updatedIdentity['institutional_id'] !== $identity['institutional_id']
) {
    throw new RuntimeException(
        'The owner identity reader did not retain the stable identifier after the genuine correction.',
    );
}
