<?php

declare(strict_types=1);

// Minimal shared HTTP setup for focused SIS checks. Included by the isolated console fixture only.
require_once __DIR__ . "/SisFixture.php";
$sis = new SisFixture();
$originalSubmissionActor = $sis->access->actor;
$app->delegate(
    \Qubus\Expressive\Connection::class,
    static fn() => $sis->connection,
);
$app->delegate(
    \Qubus\Expressive\Database::class,
    static fn() => $sis->connection->queryBuilder(),
);
$app->delegate(
    \Infrastructure\Persistence\SqlRecords::class,
    static fn() => $sis->sql,
);
$app->delegate(
    \Domain\Admissions\Repository\AdmissionsRepository::class,
    static fn() => $sis->admissions,
);
$app->delegate(
    \Domain\Shared\Persistence\UnitOfWork::class,
    static fn() => $sis->transactions,
);
$app->delegate(
    \Domain\Shared\Security\StudentAccess::class,
    static fn() => $sis->access,
);
$app->delegate(\Domain\Shared\Service\Clock::class, static fn() => $sis->clock);
$app->delegate(
    \Domain\Communications\Service\MessageCipher::class,
    static fn() => $sis->dropCipher,
);
$app->delegate(
    \Infrastructure\EventSourcing\ProtectedEventCodec::class,
    static fn() => new \Infrastructure\EventSourcing\ProtectedEventCodec(
        EventStoreFixture::config($sis->connection->queryBuilder()),
    ),
);
$app->delegate(
    \Domain\Admissions\Service\AdmissionDocumentStorage::class,
    static fn() => new class implements
        \Domain\Admissions\Service\AdmissionDocumentStorage
    {
        public function put(
            \Domain\Shared\ValueObject\EntityId $application,
            \Domain\Shared\ValueObject\EntityId $id,
            #[\SensitiveParameter] string $bytes,
        ): void {
            throw new RuntimeException(
                "This focused fixture does not upload documents.",
            );
        }
        public function read(
            \Domain\Shared\ValueObject\EntityId $application,
            \Domain\Shared\ValueObject\EntityId $id,
        ): string {
            throw new RuntimeException(
                "This focused fixture has no document artifacts.",
            );
        }
        public function remove(
            \Domain\Shared\ValueObject\EntityId $application,
            \Domain\Shared\ValueObject\EntityId $id,
        ): void {
            throw new RuntimeException(
                "This focused fixture does not dispose of documents.",
            );
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
$get = new \Qubus\Http\ServerRequest(
    [],
    [],
    "https://campus.example.invalid/sis/admission-policy/",
    "GET",
)
    ->withHeader("Accept", "application/json")
    ->withAttribute(
        \Codefy\Framework\Http\Middleware\Csrf\CsrfTokenMiddleware::CSRF_SESSION_ATTRIBUTE,
        "fictionalcsrftoken",
    );
$post = $get
    ->withMethod("POST")
    ->withHeader("Content-Type", "application/x-www-form-urlencoded");
$teachingPost = static function (
    string $path,
    array $data
) use (
    $app,
    $post,
): \Psr\Http\Message\ResponseInterface {
    $request = $post
        ->withUri(
            new \Laminas\Diactoros\Uri(
                "https://campus.example.invalid" . $path,
            ),
        )
        ->withBody(new \Laminas\Diactoros\Stream("php://temp", "w+"));
    $request
        ->getBody()
        ->write(
            http_build_query([
                "_workspace_json" => json_encode(
                    (object) $data,
                    JSON_THROW_ON_ERROR,
                ),
            ]),
        );
    $request->getBody()->rewind();
    if ($app->router->match($request)->getStatusCode() !== 403) {
        throw new RuntimeException(
            "A workspace mutation bypassed framework CSRF.",
        );
    }
    $request->getBody()->rewind();
    $response = $app->router->match(
        $request->withHeader("X-CSRF-Token", "fictionalcsrftoken"),
    );
    if (
        !str_contains($response->getHeaderLine("Cache-Control"), "no-store") ||
        $response->hasHeader("phpdebugbar-id")
    ) {
        throw new RuntimeException(
            "A private workspace response lost cache or debug protections.",
        );
    }
    return $response;
};
