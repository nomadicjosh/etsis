<?php

declare(strict_types=1);

use Domain\Access\Enum\GrantKind;
use Domain\Person\Service\PersonPhotoService;
use Domain\Person\Service\PhotoPolicy;
use Domain\Shared\ValueObject\EntityId;

require_once __DIR__ . "/PhotoFixture.php";
$sis->registrationService();
$sis->sql->update(
    "users",
    [
        "token" => "fictional-photo-http",
        "password" => "fictional-disabled-hash",
        "sign_in_enabled" => 1,
    ],
    ["user_id" => $sis->access->actor->value],
);
$person = $sis->legacyPerson($sis->access->actor, "Fictional", "Photo HTTP");
$roles = new \Infrastructure\Persistence\Repository\SqlAccessRepository(
    $sis->sql,
);
$duties = ["sis:person:manage", PersonPhotoService::MANAGE, "sis:import:manage"];
foreach ($duties as $duty) {
    $roles->assign(
        GrantKind::UserPermission,
        $sis->access->actor,
        new EntityId(
            $sis->sql->one("sis_access_permissions", ["name" => $duty])["id"],
        ),
        true,
    );
}
$app->delegate(
    PhotoPolicy::class,
    static fn() => new PhotoPolicy(true, true, 30, true),
);
$app->delegate(
    \Domain\Admissions\Service\AdmissionDocumentScanner::class,
    static fn() => new class implements
        \Domain\Admissions\Service\AdmissionDocumentScanner
    {
        public function scan(#[\SensitiveParameter] string $bytes): string
        {
            return "clean";
        }
    },
);
$uploadPhoto = static function (
    string $path,
    int $version,
    string $source = "upload",
) use (
    $post,
    $app
): array {
    $bytes = \Tests\Support\PhotoFixture::image();
    $stream = new \Laminas\Diactoros\Stream("php://memory", "w+");
    $stream->write($bytes);
    $stream->rewind();
    $request = $post
        ->withUri(
            new \Laminas\Diactoros\Uri(
                "https://campus.example.invalid" . $path,
            ),
        )
        ->withUploadedFiles([
            "photo" => new \Laminas\Diactoros\UploadedFile(
                $stream,
                strlen($bytes),
                UPLOAD_ERR_OK,
                "fictional.png",
                "image/png",
            ),
        ])
        ->withBody(new \Laminas\Diactoros\Stream("php://memory", "w+"));
    $request->getBody()->write(
        http_build_query([
            "_workspace_json" => json_encode(
                [
                    "source" => $source,
                    "version" => $version,
                    "reason" => "Fictional private photo choice.",
                    "consent" => false,
                ],
                JSON_THROW_ON_ERROR,
            ),
        ]),
    );
    $request->getBody()->rewind();
    if ($app->router->match($request)->getStatusCode() !== 403) {
        throw new RuntimeException("Photo upload lost its CSRF boundary.");
    }
    $request->getBody()->rewind();
    $stream->rewind();
    $response = $app->router->match(
        $request->withHeader("X-CSRF-Token", "fictionalcsrftoken"),
    );
    if ($response->getStatusCode() !== 200) {
        throw new RuntimeException(
            "Photo upload HTTP status " . $response->getStatusCode(),
        );
    }
    return json_decode(
        (string) $response->getBody(),
        true,
        flags: JSON_THROW_ON_ERROR,
    );
};
$photoGet = static function (string $path) use (
    $app,
    $get,
): \Psr\Http\Message\ResponseInterface {
    return $app->router->match(
        $get->withUri(
            new \Laminas\Diactoros\Uri(
                "https://campus.example.invalid" . $path,
            ),
        ),
    );
};
$own = "/portal/api/person/photo/";
$review = $uploadPhoto($own . "review/", 0);
$preview = $photoGet($own . "preview/" . $review["draft_id"] . "/");
if (
    $preview->getStatusCode() !== 200 ||
    $preview->getHeaderLine("Content-Type") !== "image/jpeg"
) {
    throw new RuntimeException(
        "The owner photo preview lost its exact route or scope.",
    );
}
$accepted = $teachingPost($own . "confirm/", [
    "draft_id" => $review["draft_id"],
    "review_token" => $review["review_token"],
]);
if ($accepted->getStatusCode() !== 200) {
    throw new RuntimeException("The owner photo confirmation failed.");
}
$content = $photoGet($own . "content/");
if (
    $content->getStatusCode() !== 200 ||
    !str_contains($content->getHeaderLine("Cache-Control"), "no-store") ||
    $content->getHeaderLine("Cross-Origin-Resource-Policy") !== "same-origin" ||
    $content->hasHeader("phpdebugbar-id")
) {
    throw new RuntimeException("A photo lost its private content boundary.");
}
$staff = "/sis/people/" . $person->id->value . "/photo/";
$review = $uploadPhoto($staff . "review/", 1, "camera");
if (
    $teachingPost($staff . "confirm/", [
        "draft_id" => $review["draft_id"],
        "review_token" => $review["review_token"],
    ])->getStatusCode() !== 200
) {
    throw new RuntimeException("The institutional photo confirmation failed.");
}
$data = json_decode(
    (string) $photoGet($own)->getBody(),
    true,
    flags: JSON_THROW_ON_ERROR,
);
if (
    $data["can_edit"] ||
    !$data["institutional"] ||
    array_key_exists("image_hash", $data)
) {
    throw new RuntimeException(
        "The owner reader lost the institution-photo lock or minimized response.",
    );
}
if (
    $teachingPost($own . "review/", [
        "source" => "removed",
        "version" => 2,
        "reason" => "Attempt an owner replacement.",
    ])->getStatusCode() !== 422
) {
    throw new RuntimeException("An owner bypassed the institution-photo lock.");
}
if (
    $teachingPost($own . "confirm/", [
        "draft_id" => $review["draft_id"],
        "review_token" => $review["review_token"],
    ])->getStatusCode() !== 403
) {
    throw new RuntimeException(
        "A staff photo confirmation was replayed through the owner entry point.",
    );
}

$queuedReview = $app->make(\Domain\Person\Service\PhotoImportService::class)->review(
    \Tests\Support\PhotoFixture::image(),
    $person->id->value . '.png',
    'id',
    'Queue a fictional cancellable import.'
);
$queued = $teachingPost('/sis/imports/commit/', ['review_token' => $queuedReview['review_token']]);
if ($queued->getStatusCode() !== 200) {
    throw new RuntimeException('Photo import did not queue through the actual route.');
}
$job = json_decode((string) $queued->getBody(), true, flags: JSON_THROW_ON_ERROR);
$cancelled = $teachingPost('/sis/imports/jobs/' . $job['id'] . '/cancel/', []);
if (
    $cancelled->getStatusCode() !== 200 ||
    json_decode((string) $cancelled->getBody(), true, flags: JSON_THROW_ON_ERROR)['state'] !== 'cancelled'
) {
    throw new RuntimeException('The actual import cancellation route failed its CSRF/private boundary.');
}
