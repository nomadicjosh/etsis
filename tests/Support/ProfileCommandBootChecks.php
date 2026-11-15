<?php

declare(strict_types=1);

// Included only by the isolated bootstrap smoke process; all data and keys are fictional.
require_once __DIR__ . "/PersonManagementFixture.php";
$people = new PersonManagementFixture();
$sis = $people->sis;
$person = $people->person();
$other = $people->person();
$sis->access->actor = $person->ownerUserId;
$sis->access->staff = false;
$sis->access->grants = ["admin:profile"];
$app->delegate(\Qubus\Expressive\Connection::class, static fn() => $sis->connection);
$app->delegate(\Qubus\Expressive\Database::class, static fn() => $sis->connection->queryBuilder());
$app->delegate(\Infrastructure\Persistence\SqlRecords::class, static fn() => $sis->sql);
$app->delegate(
    \Infrastructure\EventSourcing\ProtectedEventCodec::class,
    static fn() => new \Infrastructure\EventSourcing\ProtectedEventCodec(
        EventStoreFixture::config($sis->connection->queryBuilder()),
    ),
);
$app->delegate(\Domain\Shared\Persistence\UnitOfWork::class, static fn() => $sis->transactions);
$app->delegate(\Domain\Communications\Service\MessageCipher::class, static fn() => $sis->dropCipher);
$app->delegate(\Domain\Shared\Security\StudentAccess::class, static fn() => $sis->access);
$app->delegate(\Domain\Admissions\Repository\AdmissionsRepository::class, static fn() => $sis->admissions);
$app->delegate(\Domain\Access\Repository\AccessRepository::class, static fn() => $people->access);

// Exercise the actual command container before HTTP normalization can hide an injection error.
\Codefy\Framework\Helpers\command(
    new \Domain\User\Command\UpdateUserCommand(
        data: [
            "userId" => new \Domain\User\ValueObject\UserId($person->ownerUserId->value),
            "firstName" => new \Qubus\ValueObjects\StringLiteral\StringLiteral("Robin"),
            "middleName" => new \Qubus\ValueObjects\StringLiteral\StringLiteral(""),
            "lastName" => new \Qubus\ValueObjects\StringLiteral\StringLiteral("Example"),
            "email" => new \Qubus\ValueObjects\Web\EmailAddress("robin@example.invalid"),
        ],
    ),
);

$app->delegate(
    \Codefy\Framework\Auth\Gate::class,
    static fn() => new class ($sis, $person->ownerUserId) implements \Codefy\Framework\Auth\Gate {
        public function __construct(private SisFixture $sis, private \Domain\Shared\ValueObject\EntityId $owner)
        {
        }
        public function can(string $permissionName, array $ruleParams = []): bool
        {
            return $permissionName === "admin:profile";
        }
        public function current(): object|bool|null
        {
            return (object) $this->sis->sql->one("users", ["user_id" => $this->owner->value]);
        }
        public function guest(): bool
        {
            return false;
        }
        public function isLoggedIn(): bool
        {
            return true;
        }
    },
);
$app->router->baseMiddleware = [
    "sis.privacy",
    "sis.json",
    "firewall",
    "csrf.protection",
    "bind.request",
    "php.debugbar",
];
$otherBefore = $sis->sql->one("users", ["user_id" => $other->ownerUserId->value]);
$request = new \Qubus\Http\ServerRequest([], [], "https://campus.example.invalid/admin/profile/update/", "PUT")
    ->withHeader("Content-Type", "application/x-www-form-urlencoded")
    ->withAttribute(
        \Codefy\Framework\Http\Middleware\Csrf\CsrfTokenMiddleware::CSRF_SESSION_ATTRIBUTE,
        "fictionalcsrftoken",
    )
    ->withParsedBody([
        "user_id" => $other->ownerUserId->value,
        "first_name" => "Jamie",
        "middle_name" => "",
        "last_name" => "Example",
        "email" => "jamie@example.invalid",
    ]);
$beforeEvents = $sis->sql->rows("sis_history_events");
$rejected = $app->router->match($request);
if ($rejected->getStatusCode() !== 403 || $sis->sql->rows("sis_history_events") !== $beforeEvents) {
    throw new RuntimeException(
        "Profile CSRF expected HTTP 403 without events; received HTTP " . $rejected->getStatusCode(),
    );
}
$response = $app->router->match($request->withHeader("X-CSRF-Token", "fictionalcsrftoken"));
if (
    $response->getStatusCode() !== 302 ||
    $response->getHeaderLine("Location") !== "/admin/profile/" ||
    !str_contains($response->getHeaderLine("Cache-Control"), "no-store")
) {
    throw new RuntimeException(
        "Profile save expected a private HTTP 302 redirect; received HTTP " . $response->getStatusCode(),
    );
}
$saved = $sis->sql->one("users", ["user_id" => $person->ownerUserId->value]);
$updatedPerson = $sis->admissions->person($person->id);
if (
    $saved["first_name"] !== "Jamie" ||
    $saved["email"] !== "jamie@example.invalid" ||
    $updatedPerson->givenName !== "Jamie" ||
    $otherBefore !== $sis->sql->one("users", ["user_id" => $other->ownerUserId->value])
) {
    throw new RuntimeException("Profile command failed to update its owner or changed another account.");
}
$restored = $people->users->loadAggregateRoot(new \Domain\User\ValueObject\UserId($person->ownerUserId->value));
if (
    (string) $restored->emailAddress() !== "jamie@example.invalid" ||
    (string) $restored->name()->getFirstName() !== "Jamie" ||
    $sis->sql->tableExists("event_store")
) {
    throw new RuntimeException("Profile updates did not replay from the single protected event table.");
}
$framework = $app->make(\Codefy\Domain\EventSourcing\TransactionalEventStore::class);
$history = iterator_to_array(
    $framework->getAggregateHistoryFor(new \Domain\User\ValueObject\UserId($person->ownerUserId->value)),
);
if (count($history) !== 5 || count($sis->sql->rows("sis_history_events")) !== count($beforeEvents) + 3) {
    throw new RuntimeException("Profile event history was duplicated or missing.");
}
// A failed projection must roll back both the profile and its authoritative event writes.
$beforeFailure = [];
foreach (["users", "sis_persons", "sis_history_events", "sis_history_streams", "sis_history_operations"] as $table) {
    $beforeFailure[$table] = $sis->sql->rows($table);
}
try {
    \Codefy\Framework\Helpers\command(
        new \Domain\User\Command\UpdateUserCommand(
            data: [
                "userId" => new \Domain\User\ValueObject\UserId($person->ownerUserId->value),
                "firstName" => new \Qubus\ValueObjects\StringLiteral\StringLiteral("Rollback"),
                "middleName" => new \Qubus\ValueObjects\StringLiteral\StringLiteral(""),
                "lastName" => new \Qubus\ValueObjects\StringLiteral\StringLiteral("Example"),
                "email" => new \Qubus\ValueObjects\Web\EmailAddress($otherBefore["email"]),
            ],
        ),
    );
    throw new RuntimeException("A duplicate email projection unexpectedly succeeded.");
} catch (PDOException) {
    foreach ($beforeFailure as $table => $rows) {
        if ($sis->sql->rows($table) !== $rows) {
            throw new RuntimeException("A failed profile projection committed partial changes.");
        }
    }
}
$newPassword = "fictional-new-profile-password-long-enough";
$passwordRequest = $request->withHeader("X-CSRF-Token", "fictionalcsrftoken")->withParsedBody([
    "user_id" => $other->ownerUserId->value,
    "password" => $newPassword,
    "confirm_password" => $newPassword,
]);
$passwordResponse = $app->router->match($passwordRequest);
$passwordSaved = $sis->sql->one("users", ["user_id" => $person->ownerUserId->value]);
if (
    $passwordResponse->getStatusCode() !== 302 ||
    $passwordResponse->getHeaderLine("Location") !== "/login/" ||
    !\Codefy\Framework\Support\Password::verify($newPassword, $passwordSaved["password"]) ||
    $saved["token"] === $passwordSaved["token"] ||
    $otherBefore !== $sis->sql->one("users", ["user_id" => $other->ownerUserId->value])
) {
    throw new RuntimeException("Profile password update failed to protect the owner or rotate credentials.");
}
$replayed = \Domain\User\User::reconstituteFromEventStream(
    $framework->getAggregateHistoryFor(new \Domain\User\ValueObject\UserId($person->ownerUserId->value)),
);
if (
    (string) $replayed->password() !== $passwordSaved["password"] ||
    (string) $replayed->token() !== $passwordSaved["token"]
) {
    throw new RuntimeException("Changed profile credentials did not replay from protected history.");
}
// Exercise the real setup controller/provider binding and global CSRF/privacy middleware.
$app->make(\Codefy\Framework\Http\Middleware\Csrf\CsrfTokenMiddleware::class);
$setupGet = new \Qubus\Http\ServerRequest([], [], 'https://campus.example.invalid/portal/password-setup/', 'GET');
$setupPage = $app->router->match($setupGet);
if (
    $setupPage->getStatusCode() !== 200
    || !str_contains($setupPage->getHeaderLine('Cache-Control'), 'no-store')
    || $setupPage->getHeaderLine('Referrer-Policy') !== 'no-referrer'
    || !str_contains((string) $setupPage->getBody(), 'account-setup-form')
    || str_contains((string) $setupPage->getBody(), 'phpdebugbar')
) {
    throw new RuntimeException('Initial account setup did not resolve as a private portal form.');
}
$setupPost = new \Qubus\Http\ServerRequest([], [], 'https://campus.example.invalid/portal/password-setup/', 'POST')
    ->withHeader('Content-Type', 'application/x-www-form-urlencoded')
    ->withAttribute(\Codefy\Framework\Http\Middleware\Csrf\CsrfTokenMiddleware::CSRF_SESSION_ATTRIBUTE, 'fictionalcsrftoken')
    ->withParsedBody(['token' => str_repeat('a', 64), 'password' => 'fictional', 'confirm_password' => 'different']);
$setupEvents = $sis->sql->rows('sis_history_events');
if ($app->router->match($setupPost)->getStatusCode() !== 403) {
    throw new RuntimeException('Account setup bypassed CSRF.');
}
$invalidSetup = $app->router->match($setupPost->withHeader('X-CSRF-Token', 'fictionalcsrftoken'));
if (
    $invalidSetup->getStatusCode() !== 200 || $setupEvents !== $sis->sql->rows('sis_history_events')
    || !str_contains((string) $invalidSetup->getBody(), 'This link is unavailable')
) {
    throw new RuntimeException('Rejected account setup wrote credential history or lost its accessible error.');
}
// Staff contact maintenance uses real routes/providers and does not borrow credential authority.
$contactRequest = new \Qubus\Http\ServerRequest([], [], '/sis/ui/people/' . $other->id->value . '/contact/', 'GET')
    ->withHeader('Accept', 'application/json');
$beforeContact = $sis->sql->rows('sis_history_events');
if ($app->router->match($contactRequest)->getStatusCode() !== 403) {
    throw new RuntimeException('Profile permission alone exposed another person contact.');
}
$sis->access->grants = ['sis:person:manage'];
$contactResponse = $app->router->match($contactRequest);
if (
    $contactResponse->getStatusCode() !== 200
    || !str_contains($contactResponse->getHeaderLine('Cache-Control'), 'no-store')
    || str_contains((string) $contactResponse->getBody(), 'phpdebugbar')
) {
    throw new RuntimeException('Staff contact reader did not resolve privately through the configured provider.');
}
$contactBody = json_decode((string) $contactResponse->getBody(), true, flags: JSON_THROW_ON_ERROR);
$contactPost = $contactRequest->withMethod('POST')
    ->withHeader('Content-Type', 'application/x-www-form-urlencoded')
    ->withAttribute(\Codefy\Framework\Http\Middleware\Csrf\CsrfTokenMiddleware::CSRF_SESSION_ATTRIBUTE, 'fictionalcsrftoken')
    ->withParsedBody(['preferred_name' => 'Fictional contact', 'phone' => '001234',
        'address' => "Fictional campus\nFictional town", 'version' => $contactBody['version'],
        'person_id' => $person->id->value]);
if (
    $app->router->match($contactPost)->getStatusCode() !== 403
    || $beforeContact !== $sis->sql->rows('sis_history_events')
) {
    throw new RuntimeException('Staff contact mutation bypassed CSRF or a read wrote history.');
}
$savedContact = $app->router->match($contactPost->withHeader('X-CSRF-Token', 'fictionalcsrftoken'));
if (
    $savedContact->getStatusCode() !== 200
    || !str_contains($savedContact->getHeaderLine('Cache-Control'), 'no-store')
    || $otherBefore !== $sis->sql->one('users', ['user_id' => $other->ownerUserId->value])
) {
    throw new RuntimeException('Staff contact update failed or changed credentials.');
}
$verifiedContact = $app->make(\Domain\Person\Service\PersonContactService::class)->staffView($other->id);
if (
    $verifiedContact['phone'] !== '001234' || $verifiedContact['version'] !== 1
    || count($sis->sql->rows('sis_history_events')) !== count($beforeContact) + 2
) {
    throw new RuntimeException('The routed contact change did not retain the intended person and revision.');
}
$sis->access->grants = [];
if ($app->router->match($contactRequest)->getStatusCode() !== 403) {
    throw new RuntimeException('Contact reads survived permission revocation.');
}
// Emergency contacts use the same person identity and distinct staff/owner authorization paths.
$emergencyGet = $contactRequest->withUri(new \Laminas\Diactoros\Uri(
    '/sis/ui/people/' . $other->id->value . '/emergency-contacts/'
));
if ($app->router->match($emergencyGet)->getStatusCode() !== 403) {
    throw new RuntimeException('Emergency contact reads bypassed staff permission.');
}
$sis->access->grants = ['sis:person:manage'];
if ($app->router->match($emergencyGet)->getStatusCode() !== 200) {
    throw new RuntimeException('Emergency contact reader failed to resolve through actual providers.');
}
$emergencyPost = $emergencyGet->withMethod('POST')
    ->withHeader('Content-Type', 'application/x-www-form-urlencoded')
    ->withAttribute(\Codefy\Framework\Http\Middleware\Csrf\CsrfTokenMiddleware::CSRF_SESSION_ATTRIBUTE, 'fictionalcsrftoken')
    ->withParsedBody(['contacts' => [['name' => 'Fictional Relative', 'phone' => '001234']], 'version' => 0]);
$beforeEmergency = $sis->sql->rows('sis_history_events');
if (
    $app->router->match($emergencyPost)->getStatusCode() !== 403
    || $beforeEmergency !== $sis->sql->rows('sis_history_events')
) {
    throw new RuntimeException('Emergency contact mutation bypassed CSRF.');
}
$emergencySaved = $app->router->match($emergencyPost->withHeader('X-CSRF-Token', 'fictionalcsrftoken'));
if (
    $emergencySaved->getStatusCode() !== 200
    || !str_contains($emergencySaved->getHeaderLine('Cache-Control'), 'no-store')
) {
    throw new RuntimeException('Emergency contact update failed its routed private write.');
}
$sis->access->grants = [];
$myEmergencyGet = $emergencyGet->withUri(new \Laminas\Diactoros\Uri('/portal/api/emergency-contacts/'));
$myEmergencyResponse = $app->router->match($myEmergencyGet);
if (
    $myEmergencyResponse->getStatusCode() !== 200
    || json_decode((string) $myEmergencyResponse->getBody(), true, flags: JSON_THROW_ON_ERROR)['contacts'] !== []
) {
    throw new RuntimeException('The owner endpoint exposed the staff-selected person instead of the signed-in owner.');
}
$myEmergencyPost = $emergencyPost->withUri(new \Laminas\Diactoros\Uri('/portal/api/emergency-contacts/'))
    ->withHeader('X-CSRF-Token', 'fictionalcsrftoken')->withParsedBody([
        'contacts' => [['name' => 'Fictional Owner Contact', 'phone' => '005678']],
        'version' => 0, 'person_id' => $other->id->value,
    ]);
if ($app->router->match($myEmergencyPost)->getStatusCode() !== 200) {
    throw new RuntimeException('The owner emergency contact writer failed without staff grants.');
}
$ownerEmergency = $app->make(\Domain\Person\Service\EmergencyContactsService::class)->view($person->id);
if ($ownerEmergency['contacts'][0]['phone'] !== '005678') {
    throw new RuntimeException('The owner emergency contact endpoint used a supplied person identity.');
}
$sis->access->grants = ['sis:person:manage'];
$otherEmergency = $app->make(\Domain\Person\Service\EmergencyContactsService::class)->staffView($other->id);
if (
    $otherEmergency['contacts'][0]['phone'] !== '001234'
    || $otherBefore !== $sis->sql->one('users', ['user_id' => $other->ownerUserId->value])
) {
    throw new RuntimeException('Emergency contact editing changed another person or credential record.');
}
// The real provider/controller path requires account authority, explicit state, and CSRF.
$sis->access->grants = ['sis:person:manage', 'admin:edit:user'];
$accountEditor = $app->make(\Domain\User\Service\PersonAccountEditor::class);
$account = $accountEditor->view($other->id);
$signInPost = $emergencyPost->withUri(new \Laminas\Diactoros\Uri(
    '/sis/ui/people/' . $other->id->value . '/account/sign-in/'
))
    ->withParsedBody(['sign_in_enabled' => false, 'version' => $account['version'],
        'operation_id' => \Domain\Shared\ValueObject\EntityId::generate()->value,
        'reason' => 'Fictional account review', 'person_id' => $person->id->value]);
$signInEvents = $sis->sql->rows('sis_history_events');
if (
    $app->router->match($signInPost)->getStatusCode() !== 403
    || $signInEvents !== $sis->sql->rows('sis_history_events')
) {
    throw new RuntimeException('Sign-in changes bypassed CSRF.');
}
foreach ([null, 'unrecognized', []] as $badState) {
    $badBody = $signInPost->getParsedBody();
    if ($badState === null) {
        unset($badBody['sign_in_enabled']);
    } else {
        $badBody['sign_in_enabled'] = $badState;
    }
    $invalidDecision = $app->router->match($signInPost
        ->withHeader('X-CSRF-Token', 'fictionalcsrftoken')->withParsedBody($badBody));
    if (
        $invalidDecision->getStatusCode() !== 422
        || $signInEvents !== $sis->sql->rows('sis_history_events')
    ) {
        throw new RuntimeException('A missing or malformed sign-in state became a decision.');
    }
}
$signInResponse = $app->router->match($signInPost->withHeader('X-CSRF-Token', 'fictionalcsrftoken'));
if (
    $signInResponse->getStatusCode() !== 200
    || !str_contains($signInResponse->getHeaderLine('Cache-Control'), 'no-store')
    || $accountEditor->view($other->id)['sign_in_enabled'] !== false
    || (int) $sis->sql->one('users', ['user_id' => $person->ownerUserId->value])['sign_in_enabled'] !== 1
) {
    throw new RuntimeException('The routed sign-in change lost privacy or used a supplied owner.');
}
$sis->access->grants = ['sis:person:manage'];
if ($app->router->match($signInPost->withHeader('X-CSRF-Token', 'fictionalcsrftoken'))->getStatusCode() !== 403) {
    throw new RuntimeException('Person directory access implied account access.');
}
// Person history is a curated read, requiring both private-record and audit authority.
$historyPost = $signInPost->withUri(new \Laminas\Diactoros\Uri(
    '/sis/ui/people/' . $other->id->value . '/history/'
))
    ->withParsedBody(['after' => 0, 'person_id' => $person->id->value]);
$beforeHistoryRead = $sis->sql->rows('sis_history_events');
if ($app->router->match($historyPost)->getStatusCode() !== 403) {
    throw new RuntimeException('A private profile history read bypassed CSRF.');
}
$historyPost = $historyPost->withHeader('X-CSRF-Token', 'fictionalcsrftoken');
if ($app->router->match($historyPost)->getStatusCode() !== 403) {
    throw new RuntimeException('Person management implied audit-history permission.');
}
$sis->access->grants = ['sis:person:manage', 'sis:audit:read'];
$historyResponse = $app->router->match($historyPost);
if (
    $historyResponse->getStatusCode() !== 200
    || !str_contains($historyResponse->getHeaderLine('Cache-Control'), 'no-store')
    || str_contains((string) $historyResponse->getBody(), 'phpdebugbar')
    || $beforeHistoryRead !== $sis->sql->rows('sis_history_events')
) {
    throw new RuntimeException('The history reader failed actual provider/privacy/read-only boundaries.');
}
$historyBody = json_decode((string) $historyResponse->getBody(), true, flags: JSON_THROW_ON_ERROR);
if (
    $historyBody['items'][0]['category'] !== 'Emergency contacts'
    || str_contains((string) $historyResponse->getBody(), 'Fictional Relative')
    || str_contains((string) $historyResponse->getBody(), '001234')
) {
    throw new RuntimeException('The history reader used a supplied owner or disclosed private contact values.');
}
$sis->access->grants = ['sis:audit:read'];
if ($app->router->match($historyPost)->getStatusCode() !== 403) {
    throw new RuntimeException('Audit authority implied private person access.');
}
// Dated address routes share the canonical person, private responses and CSRF.
$addressGet = $contactRequest->withUri(new \Laminas\Diactoros\Uri(
    '/sis/ui/people/' . $other->id->value . '/addresses/'
));
if ($app->router->match($addressGet)->getStatusCode() !== 403) {
    throw new RuntimeException('Dated addresses bypassed person-management authority.');
}
$sis->access->grants = ['sis:person:manage'];
$addressRead = $app->router->match($addressGet);
if (
    $addressRead->getStatusCode() !== 200
    || !str_contains($addressRead->getHeaderLine('Cache-Control'), 'no-store')
) {
    throw new RuntimeException('Dated addresses did not resolve privately through the real providers.');
}
$addressData = json_decode((string) $addressRead->getBody(), true, flags: JSON_THROW_ON_ERROR);
$address = ['id' => $addressData['new_ids'][0], 'purpose' => 'mailing', 'lines' => 'Fictional staff address',
    'locality' => 'Example', 'region' => '', 'postal_code' => '00123', 'country_code' => 'US',
    'valid_from' => '2026-01-01', 'valid_until' => null];
$addressPost = $emergencyPost->withUri($addressGet->getUri())
    ->withParsedBody(['addresses' => [$address], 'version' => 0, 'person_id' => $person->id->value]);
$beforeAddresses = $sis->sql->rows('sis_history_events');
$usersBeforeAddresses = $sis->sql->rows('users');
if (
    $app->router->match($addressPost)->getStatusCode() !== 403
    || $beforeAddresses !== $sis->sql->rows('sis_history_events')
) {
    throw new RuntimeException('Dated address changes bypassed CSRF.');
}
$addressSaved = $app->router->match($addressPost->withHeader('X-CSRF-Token', 'fictionalcsrftoken'));
if (
    $addressSaved->getStatusCode() !== 200
    || !str_contains($addressSaved->getHeaderLine('Cache-Control'), 'no-store')
    || $usersBeforeAddresses !== $sis->sql->rows('users')
) {
    throw new RuntimeException('The dated address mutation lost privacy or changed sign-in records.');
}
$sis->access->grants = [];
$ownerAddresses = $addressGet->withUri(new \Laminas\Diactoros\Uri('/portal/api/addresses/'));
$ownerAddressRead = $app->router->match($ownerAddresses);
if (
    $ownerAddressRead->getStatusCode() !== 200
    || json_decode((string) $ownerAddressRead->getBody(), true, flags: JSON_THROW_ON_ERROR)['addresses'] !== []
) {
    throw new RuntimeException('The owner address reader returned another person.');
}
$address['id'] = \Domain\Shared\ValueObject\EntityId::generate()->value;
$address['lines'] = 'Fictional owner address';
$ownerAddressPost = $addressPost->withUri($ownerAddresses->getUri())
    ->withHeader('X-CSRF-Token', 'fictionalcsrftoken')
    ->withParsedBody(['addresses' => [$address], 'version' => 0, 'person_id' => $other->id->value]);
if (
    $app->router->match($ownerAddressPost)->getStatusCode() !== 200
    || $app->make(\Domain\Person\Service\PersonAddressService::class)->view()['addresses'][0]['lines']
        !== 'Fictional owner address'
) {
    throw new RuntimeException('The owner address writer accepted a supplied person identity.');
}
// Employment history resolves through the real providers without changing the parent/account.
$sis->access->grants = ['sis:hr:manage'];
$employeePost = $emergencyPost->withUri(new \Laminas\Diactoros\Uri('/sis/employees/'))
    ->withoutHeader('X-CSRF-Token')
    ->withParsedBody(['person_id' => $other->id->value, 'job_title' => 'Fictional assistant']);
$beforeEmployment = $sis->sql->rows('sis_history_events');
if (
    $app->router->match($employeePost)->getStatusCode() !== 403
    || $beforeEmployment !== $sis->sql->rows('sis_history_events')
) {
    throw new RuntimeException('Employment creation bypassed CSRF.');
}
$employeeCreated = $app->router->match($employeePost->withHeader('X-CSRF-Token', 'fictionalcsrftoken'));
if (
    $employeeCreated->getStatusCode() !== 201
    || !str_contains($employeeCreated->getHeaderLine('Cache-Control'), 'no-store')
) {
    throw new RuntimeException('Employment creation did not resolve privately through the real providers.');
}
$employeeId = json_decode((string) $employeeCreated->getBody(), true, flags: JSON_THROW_ON_ERROR)['id'];
$employeeGet = $contactRequest->withUri(new \Laminas\Diactoros\Uri('/sis/employees/' . $employeeId . '/'));
$employeeResponse = $app->router->match($employeeGet);
$employeeData = json_decode((string) $employeeResponse->getBody(), true, flags: JSON_THROW_ON_ERROR);
if (
    $employeeResponse->getStatusCode() !== 200 || $employeeData['earlier_history_unknown'] !== false
    || $employeeData['person_id'] !== $other->id->value
    || !str_contains($employeeResponse->getHeaderLine('Cache-Control'), 'no-store')
    || str_contains((string) $employeeResponse->getBody(), 'phpdebugbar')
) {
    throw new RuntimeException('The employment reader lost its private parent/history boundary.');
}
$employeeEdit = $employeePost->withUri(new \Laminas\Diactoros\Uri('/sis/employees/' . $employeeId . '/edit/'))
    ->withParsedBody(['job_title' => 'Fictional librarian', 'active' => false,
        'revision' => $employeeData['revision'], 'reason' => 'Fictional employment decision',
        'person_id' => $person->id->value]);
$beforeEmploymentEdit = $sis->sql->rows('sis_history_events');
if (
    $app->router->match($employeeEdit)->getStatusCode() !== 403
    || $beforeEmploymentEdit !== $sis->sql->rows('sis_history_events')
) {
    throw new RuntimeException('Employment editing bypassed CSRF.');
}
$employeeEdit = $employeeEdit->withHeader('X-CSRF-Token', 'fictionalcsrftoken');
$withoutReason = $employeeEdit->getParsedBody();
unset($withoutReason['reason']);
if (
    $app->router->match($employeeEdit->withParsedBody($withoutReason))->getStatusCode() !== 422
    || $beforeEmploymentEdit !== $sis->sql->rows('sis_history_events')
) {
    throw new RuntimeException('Employment editing accepted a missing reason.');
}
$employmentUsers = $sis->sql->rows('users');
$employmentPeople = $sis->sql->rows('sis_persons');
$editedEmployee = $app->router->match($employeeEdit);
$afterEmploymentRead = $app->router->match($employeeGet);
$afterEmployment = json_decode((string) $afterEmploymentRead->getBody(), true, flags: JSON_THROW_ON_ERROR);
if (
    $editedEmployee->getStatusCode() !== 200 || $afterEmployment['active'] !== false
    || $afterEmployment['job_title'] !== 'Fictional librarian' || $afterEmployment['person_id'] !== $other->id->value
    || count($sis->sql->rows('sis_history_events')) !== count($beforeEmploymentEdit) + 1
    || $employmentUsers !== $sis->sql->rows('users') || $employmentPeople !== $sis->sql->rows('sis_persons')
    || str_contains((string) $afterEmploymentRead->getBody(), 'Fictional employment decision')
    || !str_contains($editedEmployee->getHeaderLine('Cache-Control'), 'no-store')
) {
    throw new RuntimeException('Employment editing lost history, privacy or the canonical parent.');
}
$sis->access->grants = [];
if ($app->router->match($employeeGet)->getStatusCode() !== 403) {
    throw new RuntimeException('Another person read employment without HR authority.');
}
$sis->access->actor = $other->ownerUserId;
if ($app->router->match($employeeGet)->getStatusCode() !== 200) {
    throw new RuntimeException('Employment inactivity blocked owner history access.');
}
$weekPost = $employeeEdit->withUri(new \Laminas\Diactoros\Uri('/sis/employees/' . $employeeId . '/time-week/'))
    ->withParsedBody(['week' => '2026-08-12', 'person_id' => $person->id->value]);
$beforeWeek = $sis->sql->rows('sis_history_events');
if ($app->router->match($weekPost->withoutHeader('X-CSRF-Token'))->getStatusCode() !== 403) {
    throw new RuntimeException('The private weekly time review bypassed CSRF.');
}
$weeklyResponse = $app->router->match($weekPost);
$weeklyData = json_decode((string) $weeklyResponse->getBody(), true, flags: JSON_THROW_ON_ERROR);
if (
    $weeklyResponse->getStatusCode() !== 200 || $weeklyData['week'] !== '2026-08-10'
    || $weeklyData['employee']['person_id'] !== $other->id->value || count($weeklyData['days']) !== 7
    || !str_contains($weeklyResponse->getHeaderLine('Cache-Control'), 'no-store')
    || str_contains((string) $weeklyResponse->getBody(), 'phpdebugbar')
    || $beforeWeek !== $sis->sql->rows('sis_history_events')
) {
    throw new RuntimeException('The weekly time review lost its private owner/read-only boundary.');
}
if ($app->router->match($weekPost->withParsedBody(['week' => '2026-02-30']))->getStatusCode() !== 422) {
    throw new RuntimeException('The weekly time review accepted an invalid calendar date.');
}
$employmentHistory = $weekPost
    ->withUri(new \Laminas\Diactoros\Uri('/sis/employees/' . $employeeId . '/history/'))
    ->withParsedBody(['after' => 0, 'person_id' => $person->id->value]);
foreach ([[], ['sis:hr:manage'], ['sis:audit:read']] as $grants) {
    $sis->access->grants = $grants;
    if ($app->router->match($employmentHistory)->getStatusCode() !== 403) {
        throw new RuntimeException('Employment ownership or one permission granted the other history permission.');
    }
}
$sis->access->grants = ['sis:hr:manage', 'sis:audit:read'];
if ($app->router->match($employmentHistory->withoutHeader('X-CSRF-Token'))->getStatusCode() !== 403) {
    throw new RuntimeException('Employment change history bypassed CSRF.');
}
$employmentHistoryResponse = $app->router->match($employmentHistory);
$employmentHistoryData = json_decode((string) $employmentHistoryResponse->getBody(), true, flags: JSON_THROW_ON_ERROR);
if (
    $employmentHistoryResponse->getStatusCode() !== 200 || count($employmentHistoryData['items']) !== 2
    || $employmentHistoryData['items'][0]['job_title'] !== 'Fictional librarian'
    || $employmentHistoryData['items'][1]['job_title'] !== 'Fictional assistant'
    || !str_contains($employmentHistoryResponse->getHeaderLine('Cache-Control'), 'no-store')
    || str_contains((string) $employmentHistoryResponse->getBody(), 'phpdebugbar')
    || str_contains((string) $employmentHistoryResponse->getBody(), 'Fictional employment decision')
    || str_contains((string) $employmentHistoryResponse->getBody(), 'actor_id')
    || $beforeWeek !== $sis->sql->rows('sis_history_events')
) {
    throw new RuntimeException('Employment history lost its curated/private/read-only boundary.');
}
if ($app->router->match($employmentHistory->withParsedBody(['after' => 1]))->getStatusCode() !== 422) {
    throw new RuntimeException('Employment history accepted an invalid page boundary.');
}
// HR reference definitions use the real provider/controller path and remain audited CRUD.
$gradeId = \Domain\Shared\ValueObject\EntityId::generate()->value;
$gradeBody = ['id' => $gradeId, 'code' => 'H.1', 'title' => 'Fictional hourly grade', 'currency' => 'USD',
    'basis' => 'hourly', 'minimum_minor' => 1500, 'maximum_minor' => 5000, 'active' => true, 'version' => null];
$gradePost = $employeeEdit->withUri(new \Laminas\Diactoros\Uri('/sis/hr/pay-grades/save/'))
    ->withParsedBody($gradeBody);
$hrEvents = $sis->sql->rows('sis_history_events');
$sis->access->grants = ['sis:audit:read'];
if ($app->router->match($gradePost)->getStatusCode() !== 403 || $sis->sql->rows('sis_hr_pay_grades') !== []) {
    throw new RuntimeException('Audit access implied HR setup authority.');
}
$sis->access->grants = ['sis:hr:manage'];
if ($app->router->match($gradePost->withoutHeader('X-CSRF-Token'))->getStatusCode() !== 403) {
    throw new RuntimeException('HR setup bypassed CSRF.');
}
$gradeSaved = $app->router->match($gradePost);
if (
    $gradeSaved->getStatusCode() !== 200
    || !str_contains($gradeSaved->getHeaderLine('Cache-Control'), 'no-store')
    || json_decode((string) $gradeSaved->getBody(), true, flags: JSON_THROW_ON_ERROR)['version'] !== 0
) {
    throw new RuntimeException('HR setup failed through the real providers.');
}
$jobId = \Domain\Shared\ValueObject\EntityId::generate()->value;
$jobPost = $gradePost->withUri(new \Laminas\Diactoros\Uri('/sis/hr/jobs/save/'))
    ->withParsedBody(['id' => $jobId, 'code' => 'LIB.ASST', 'title' => 'Fictional library default', 'currency' => 'USD',
        'basis' => 'hourly', 'active' => true, 'pay_grade_id' => $gradeId, 'rate_minor' => 2500,
        'weekly_minutes' => 2250, 'version' => null]);
if ($app->router->match($jobPost)->getStatusCode() !== 200) {
    throw new RuntimeException('The job definition route failed.');
}
$libraryPost = $jobPost->withUri(new \Laminas\Diactoros\Uri('/sis/hr/jobs/page/'))->withParsedBody(['search' => 'LIB']);
$libraryResponse = $app->router->match($libraryPost);
$libraryData = json_decode((string) $libraryResponse->getBody(), true, flags: JSON_THROW_ON_ERROR);
$jobRead = $app->router->match($employeeGet->withUri(new \Laminas\Diactoros\Uri('/sis/hr/jobs/' . $jobId . '/')));
if (
    $libraryResponse->getStatusCode() !== 200 || count($libraryData['items']) !== 1
    || $jobRead->getStatusCode() !== 200 || str_contains((string) $jobRead->getBody(), 'phpdebugbar')
    || !str_contains($jobRead->getHeaderLine('Cache-Control'), 'no-store')
    || $hrEvents !== $sis->sql->rows('sis_history_events')
) {
    throw new RuntimeException('HR setup lost its private read or separate CRUD boundary.');
}
$gradeBody['maximum_minor'] = 10;
if ($app->router->match($gradePost->withParsedBody($gradeBody))->getStatusCode() !== 422) {
    throw new RuntimeException('The pay grade form accepted a reversed range.');
}
// Dated employee agreements resolve through the same private, CSRF-protected routing stack.
$positionId = \Domain\Shared\ValueObject\EntityId::generate()->value;
$positionBody = ['id' => $positionId, 'job_id' => $jobId, 'job_version' => 0, 'grade_version' => 0,
    'valid_from' => '2026-09-01', 'valid_through' => null, 'rate_minor' => 2700,
    'weekly_minutes' => 1800, 'reason' => 'Fictional private position agreement'];
$positionBase = '/sis/employees/' . $employeeId . '/positions/';
$positionPost = $jobPost->withUri(new \Laminas\Diactoros\Uri($positionBase . 'assign/'))->withParsedBody($positionBody);
if ($app->router->match($positionPost->withoutHeader('X-CSRF-Token'))->getStatusCode() !== 403
    || $sis->sql->rows('sis_employee_positions') !== []) {
    throw new RuntimeException('Position assignments bypassed CSRF.');
}
$positionSaved = $app->router->match($positionPost);
if ($positionSaved->getStatusCode() !== 201
    || json_decode((string) $positionSaved->getBody(), true, flags: JSON_THROW_ON_ERROR)['id'] !== $positionId) {
    throw new RuntimeException('Position assignment failed through the real providers.');
}
$positionGet = $employeeGet->withUri(new \Laminas\Diactoros\Uri($positionBase . $positionId . '/'));
$positionRead = $app->router->match($positionGet);
$positionDetail = json_decode((string) $positionRead->getBody(), true, flags: JSON_THROW_ON_ERROR);
if ($positionRead->getStatusCode() !== 200 || $positionDetail['rate_minor'] !== 2700
    || !str_contains($positionRead->getHeaderLine('Cache-Control'), 'no-store')
    || str_contains((string) $positionRead->getBody(), 'phpdebugbar')
    || str_contains((string) $positionRead->getBody(), 'Fictional private position agreement')) {
    throw new RuntimeException('Position detail lost its protected private boundary.');
}
$positionEnd = $positionPost->withUri(new \Laminas\Diactoros\Uri($positionBase . $positionId . '/end/'))
    ->withParsedBody(['version' => 1, 'valid_through' => '2027-06-30', 'reason' => 'Fictional closure']);
if ($app->router->match($positionEnd)->getStatusCode() !== 200) {
    throw new RuntimeException('The assignment end-date route failed.');
}
$positionVoid = $positionPost->withUri(new \Laminas\Diactoros\Uri($positionBase . $positionId . '/void/'))
    ->withParsedBody(['version' => 2, 'voided' => true, 'reason' => 'Fictional correction']);
if ($app->router->match($positionVoid)->getStatusCode() !== 200) {
    throw new RuntimeException('The assignment correction route failed.');
}
$positionPage = $positionPost->withUri(new \Laminas\Diactoros\Uri($positionBase . 'page/'))->withParsedBody([]);
$positionPageResponse = $app->router->match($positionPage);
if ($positionPageResponse->getStatusCode() !== 200
    || count(json_decode((string) $positionPageResponse->getBody(), true, flags: JSON_THROW_ON_ERROR)['items']) !== 1) {
    throw new RuntimeException('The position paging route failed.');
}
$sis->access->grants = [];
foreach ([$positionGet, $positionPost, $positionPage, $positionEnd, $positionVoid] as $deniedPosition) {
    if ($app->router->match($deniedPosition)->getStatusCode() !== 403) {
        throw new RuntimeException('Employment ownership granted HR compensation authority.');
    }
}
// Employment dates are owner-readable, HR-managed and keep position effects in one confirmation.
$datesBase = '/sis/employees/' . $employeeId . '/employment-dates/';
$datesGet = $employeeGet->withUri(new \Laminas\Diactoros\Uri($datesBase));
$datesRead = $app->router->match($datesGet);
if ($datesRead->getStatusCode() !== 200
    || !str_contains($datesRead->getHeaderLine('Cache-Control'), 'no-store')
    || str_contains((string) $datesRead->getBody(), 'phpdebugbar')) {
    throw new RuntimeException('Employment dates lost their private owner-read boundary.');
}
$datesPeriod = \Domain\Shared\ValueObject\EntityId::generate()->value;
$datesBody = ['period_id' => $datesPeriod, 'version' => 0, 'from' => '2026-01-01',
    'through' => null, 'voided' => false, 'reason' => 'Fictional private hire decision'];
$datesReview = $positionPost->withUri(new \Laminas\Diactoros\Uri($datesBase . 'review/'))->withParsedBody($datesBody);
if ($app->router->match($datesReview)->getStatusCode() !== 403) {
    throw new RuntimeException('Ownership granted employment-date maintenance.');
}
$sis->access->grants = ['sis:hr:manage'];
if ($app->router->match($datesReview->withoutHeader('X-CSRF-Token'))->getStatusCode() !== 403) {
    throw new RuntimeException('Employment-date review bypassed CSRF.');
}
$datesReviewed = $app->router->match($datesReview);
$datesPreview = json_decode((string) $datesReviewed->getBody(), true, flags: JSON_THROW_ON_ERROR);
if ($datesReviewed->getStatusCode() !== 200 || !isset($datesPreview['position_revision'])
    || $sis->sql->rows('sis_employment_dates') !== []) {
    throw new RuntimeException('Employment-date review wrote facts or failed to resolve its dependencies.');
}
$datesConfirm = $datesReview->withUri(new \Laminas\Diactoros\Uri($datesBase . 'confirm/'))
    ->withParsedBody($datesBody + ['position_revision' => $datesPreview['position_revision']]);
if ($app->router->match($datesConfirm->withoutHeader('X-CSRF-Token'))->getStatusCode() !== 403
    || $app->router->match($datesConfirm)->getStatusCode() !== 200) {
    throw new RuntimeException('Employment-date confirmation failed its private CSRF-protected provider path.');
}
$datesSaved = $app->router->match($datesGet);
$datesSavedData = json_decode((string) $datesSaved->getBody(), true, flags: JSON_THROW_ON_ERROR);
if ($datesSavedData['version'] !== 1 || count($datesSavedData['periods']) !== 1
    || str_contains((string) $datesSaved->getBody(), 'Fictional private hire decision')) {
    throw new RuntimeException('Employment-date confirmation failed to retain curated private facts.');
}
// Time positions share owner/HR authorization without exposing compensation or bypassing CSRF.
$workPositions = $positionPost->withUri(new \Laminas\Diactoros\Uri('/sis/employees/' . $employeeId . '/work-positions/'))
    ->withParsedBody(['starts_at' => '2026-09-01T09:00:00+00:00', 'ends_at' => '2026-09-01T10:00:00+00:00']);
$workEvents = $sis->sql->rows('sis_history_events');
if ($app->router->match($workPositions->withoutHeader('X-CSRF-Token'))->getStatusCode() !== 403) {
    throw new RuntimeException('Work-position lookup bypassed CSRF.');
}
$sis->access->grants = [];
$workResponse = $app->router->match($workPositions);
$workData = json_decode((string) $workResponse->getBody(), true, flags: JSON_THROW_ON_ERROR);
if ($workResponse->getStatusCode() !== 200 || !$workData['position_required'] || $workData['items'] !== []
    || !str_contains($workResponse->getHeaderLine('Cache-Control'), 'no-store')
    || str_contains((string) $workResponse->getBody(), 'phpdebugbar') || $workEvents !== $sis->sql->rows('sis_history_events')) {
    throw new RuntimeException('The private work-position lookup lost its minimized, read-only boundary.');
}
$sis->access->grants = ['sis:hr:manage'];
$validPositionId = \Domain\Shared\ValueObject\EntityId::generate()->value;
$validPositionPost = $positionPost->withParsedBody(array_replace($positionBody,
    ['id' => $validPositionId, 'valid_from' => '2026-01-01', 'valid_through' => null]));
if ($app->router->match($validPositionPost)->getStatusCode() !== 201) {
    throw new RuntimeException('The work-position fixture assignment failed through real providers.');
}
$sis->access->grants = [];
$workResponse = $app->router->match($workPositions);
$workData = json_decode((string) $workResponse->getBody(), true, flags: JSON_THROW_ON_ERROR);
if (count($workData['items']) !== 1 || array_keys($workData['items'][0]) !== ['id', 'code', 'title', 'version']) {
    throw new RuntimeException('An owner could not read minimized work-position choices.');
}
// The existing employee fixture is paused; HR explicitly enables time before the owner submits it.
$sis->access->grants = ['sis:hr:manage'];
$enabledEmployee = $sis->timesheetService()->employeeDetails(new \Domain\Shared\ValueObject\EntityId($employeeId));
$sis->timesheetService()->editEmployee(new \Domain\Shared\ValueObject\EntityId($employeeId),
    $enabledEmployee['job_title'], true, $enabledEmployee['revision'], 'Fictional time-submission enablement');
$sis->access->grants = [];
$workSubmit = $workPositions->withUri(new \Laminas\Diactoros\Uri('/sis/timesheets/'))
    ->withParsedBody(['employee_id' => $employeeId, 'starts_at' => '2026-09-01T09:00:00+00:00',
        'ends_at' => '2026-09-01T10:00:00+00:00', 'position_id' => $validPositionId, 'position_version' => 1]);
$workSaved = $app->router->match($workSubmit);
if ($workSaved->getStatusCode() !== 201) {
    throw new RuntimeException('The owner could not submit a position-bound time entry through real providers.');
}
$workId = json_decode((string) $workSaved->getBody(), true, flags: JSON_THROW_ON_ERROR)['id'];
$boundTime = $app->router->match($employeeGet->withUri(new \Laminas\Diactoros\Uri('/sis/timesheets/' . $workId . '/')));
$boundData = json_decode((string) $boundTime->getBody(), true, flags: JSON_THROW_ON_ERROR);
if ($boundTime->getStatusCode() !== 200 || $boundData['position']['id'] !== $validPositionId
    || isset($boundData['position']['rate_minor']) || isset($boundData['encrypted_reference'])) {
    throw new RuntimeException('The time-entry response exposed a private reference or compensation.');
}
session_write_close();
\Codefy\Framework\Http\RequestContext::clear();
