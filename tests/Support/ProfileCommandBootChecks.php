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
    if ($invalidDecision->getStatusCode() !== 422
        || $signInEvents !== $sis->sql->rows('sis_history_events')) {
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
session_write_close();
\Codefy\Framework\Http\RequestContext::clear();
