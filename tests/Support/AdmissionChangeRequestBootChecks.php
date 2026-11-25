<?php

declare(strict_types=1);

// Real middleware/providers with fictional in-memory records only.
$changeManager = $sis->access->actor;
$changeOwner = $sis->user();
$changePerson = $sis->legacyPerson($changeOwner, 'Fictional', 'Requested correction');
$changeApplication = $sis->submissionService()->apply(
    \Domain\Shared\ValueObject\EntityId::generate(),
    $changePerson->id,
    $sis->program,
    $sis->term
);
$changeOwnerBase = '/portal/api/applications/' . $changeApplication->id->value . '/change-requests/';
$changeStaffBase = '/sis/applications/' . $changeApplication->id->value . '/change-requests/';
$changeGet = static fn(string $path, array $query = []) => $app->router->match($get
    ->withUri(new \Laminas\Diactoros\Uri('https://campus.example.invalid' . $path))->withQueryParams($query));
$changeCheck = static function (\Psr\Http\Message\ResponseInterface $response, int $status): array {
    if (
        $response->getStatusCode() !== $status
        || !str_contains($response->getHeaderLine('Cache-Control'), 'no-store')
        || $response->hasHeader('phpdebugbar-id')
    ) {
        throw new RuntimeException('Application follow-up HTTP status/privacy failed: ' . $response->getStatusCode());
    }
    return json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
};
$changeHistory = \EventStoreFixture::historyDigest($sis->sql);
$sis->access->actor = $changeOwner;
$sis->access->staff = false;
$sis->access->grants = [];
$changePage = $changeCheck($changeGet($changeOwnerBase), 200);
$changeBody = ['request_id' => $changePage['new_id'], 'area' => 'program',
    'message' => 'Fictional private target request.', 'application_revision' => $changePage['application_revision']];
$changeCheck($changeGet($changeOwnerBase, ['finished' => 'unexpected']), 422);
$changeCheck($changeGet($changeStaffBase), 403);
$changeCheck($app->router->match($post->withUri(new \Laminas\Diactoros\Uri(
    'https://campus.example.invalid' . $changeOwnerBase . 'request/'
))->withParsedBody($changeBody)->withoutHeader('X-CSRF-TOKEN')->withoutHeader('X-XSRF-TOKEN')), 403);
$changeAccepted = $changeCheck($teachingPost($changeOwnerBase . 'request/', $changeBody), 200);
if ($changeAccepted !== $changeCheck($teachingPost($changeOwnerBase . 'request/', $changeBody), 200)) {
    throw new RuntimeException('Application follow-up original retry changed.');
}
$sis->access->actor = $sis->user();
$sis->access->staff = true;
$changeCheck($changeGet($changeOwnerBase), 403);
$changeCheck($teachingPost($changeOwnerBase . 'request/', $changeBody), 403);
$sis->access->actor = $changeManager;
$sis->access->staff = false;
$sis->access->grants = ['sis:admissions:manage'];
$changeStaffPage = $changeCheck($changeGet($changeStaffBase), 200);
$changeCheck($teachingPost(
    '/sis/applications/' . $changeApplication->id->value . '/program/review/',
    ['target_program' => $sis->program->value, 'reason' => 'Fictional unauthorized correction.']
), 403);
$changeReply = ['request_id' => $changeAccepted['id'], 'version' => 0,
    'application_revision' => $changeStaffPage['application_revision'], 'message' => 'Fictional owner-visible reply.'];
$changeClosed = $changeCheck($teachingPost($changeStaffBase . 'close/', $changeReply), 200);
if ($changeClosed !== $changeCheck($teachingPost($changeStaffBase . 'close/', $changeReply), 200)) {
    throw new RuntimeException('Application follow-up closure retry changed.');
}
$sis->access->actor = $changeOwner;
$sis->access->grants = [];
$changeFinished = $changeCheck($changeGet($changeOwnerBase, ['finished' => '1']), 200);
if (
    ($changeFinished['items'][0]['resolution'] ?? null) !== $changeReply['message']
    || $changeHistory !== \EventStoreFixture::historyDigest($sis->sql)
    || $sis->admissions->application($changeApplication->id)->version !== $changeApplication->version
    || $sis->sql->rows('sis_admission_cases', ['application_id' => $changeApplication->id->value]) !== []
) {
    throw new RuntimeException('Application follow-up changed formal facts or lost the owner reply.');
}
$sis->access->actor = $changeManager;
$sis->access->staff = true;
