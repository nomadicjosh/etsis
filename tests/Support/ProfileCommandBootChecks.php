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
        public function __construct(private SisFixture $sis, private \Domain\Shared\ValueObject\EntityId $owner) {}
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
if (count($history) !== 5 || count($sis->sql->rows("sis_history_events")) !== count($beforeEvents) + 2) {
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
session_write_close();
\Codefy\Framework\Http\RequestContext::clear();
