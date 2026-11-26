<?php

declare(strict_types=1);

// Real providers and private routes, only the isolated fictional worklist fixture.
$privateGet = static function (
    string $path,
    array $query = []
) use (
    $app,
    $get,
): \Psr\Http\Message\ResponseInterface {
    $response = $app->router->match(
        $get
            ->withUri(new \Laminas\Diactoros\Uri("https://campus.example.invalid" . $path))
            ->withQueryParams($query),
    );
    if (
        !str_contains($response->getHeaderLine("Cache-Control"), "no-store") ||
        $response->hasHeader("phpdebugbar-id")
    ) {
        throw new RuntimeException("Transcript worklists lost private transport protection.");
    }
    return $response;
};
[$colleague, $person] = $worklistFixture->colleague();
$list = $privateGet("/sis/transcript-worklist/");
$rows = json_decode((string) $list->getBody(), true, flags: JSON_THROW_ON_ERROR);
if ($list->getStatusCode() !== 200 || count($rows["items"] ?? []) !== 1) {
    throw new RuntimeException("The actual transcript worklist provider did not resolve.");
}
if ($privateGet('/sis/transcript-worklist/', ['scope' => '0'])->getStatusCode() !== 422) {
    throw new RuntimeException('An invalid worklist scope silently broadened the queue.');
}
$eligible = $privateGet("/sis/transcript-worklist/assignees/", ["search" => "Registrar"]);
$choices = json_decode((string) $eligible->getBody(), true, flags: JSON_THROW_ON_ERROR);
if ($eligible->getStatusCode() !== 200 || ($choices["items"][0]["person_id"] ?? null) !== $person->value) {
    throw new RuntimeException("The actual colleague chooser lost its canonical-person pin.");
}
$path = "/sis/transcript-requests/" . $worklistFixture->request->value . "/assignment/";
$before = $privateGet($path);
if ($before->getStatusCode() !== 200) {
    throw new RuntimeException("Assignment context failed.");
}
$command = [
    "version" => 0,
    "request_version" => 0,
    "assignee_id" => $colleague->value,
    "assignee_person_id" => $person->value,
];
if (
    $teachingPost($path, $command)->getStatusCode() !== 200 ||
    $teachingPost($path, $command)->getStatusCode() !== 409
) {
    throw new RuntimeException("Assignment mutations lost CSRF/current-version enforcement.");
}
$missing = $command;
unset($missing["assignee_person_id"]);
if ($teachingPost($path, $missing)->getStatusCode() !== 422) {
    throw new RuntimeException("An omitted assignment identity was interpreted as clearing.");
}
$sis->access->actor = $colleague;
$mine = $privateGet("/sis/transcript-worklist/", ["scope" => "mine"]);
if ($mine->getStatusCode() !== 200 || count(json_decode((string) $mine->getBody(), true)["items"]) !== 1) {
    throw new RuntimeException("The assigned colleague could not find their current work.");
}
if (
    $teachingPost($path, [
        "version" => 1,
        "request_version" => 0,
        "assignee_id" => null,
        "assignee_person_id" => null,
    ])->getStatusCode() !== 200
) {
    throw new RuntimeException("An authorized colleague could not explicitly clear current routing.");
}
$worklistFixture->permissions->assign(
    \Domain\Access\Enum\GrantKind::UserPermission,
    $colleague,
    new \Domain\Shared\ValueObject\EntityId(
        $sis->sql->one("sis_access_permissions", ["name" => "sis:student:read"])["id"],
    ),
    false,
);
if ($privateGet("/sis/transcript-worklist/")->getStatusCode() !== 403) {
    throw new RuntimeException("A cached session bypassed current transcript worklist authority.");
}
