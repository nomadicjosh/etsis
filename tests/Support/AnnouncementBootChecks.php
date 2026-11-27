<?php

declare(strict_types=1);

// Real providers/router/CSRF; fictional SQLite only, included by the isolated console fixture.
$content = $app->make(\Domain\Portal\Service\CampusContentService::class);
$id = $content->save(
    null,
    'news',
    'fictional-announcement',
    'Fictional announcement',
    '<p>A <strong>safe</strong> fictional notice.</p>',
    'campus',
    null,
    'html'
);
$window = new \Domain\Portal\ValueObject\PublicationWindow(null, null);
$review = $content->review($id, 'approve', $content->get($id)['revision'], $window, null);
$content->confirm(
    $id,
    new \Domain\Shared\ValueObject\EntityId($review['operation_id']),
    'approve',
    $review['revision'],
    $window,
    null,
    $review['review_token']
);
$sis->access->staff = false;
$sis->access->grants = [];
$sis->access->actor = $sis->user();
$before = $sis->sql->rows('sis_history_events');
$request = static function (string $path) use ($app, $get): \Psr\Http\Message\ResponseInterface {
    $uri = new \Laminas\Diactoros\Uri('https://campus.example.invalid' . $path);
    parse_str($uri->getQuery(), $parameters);
    $response = $app->router->match($get
        ->withUri($uri)->withQueryParams($parameters)
        ->withHeader('Accept', 'application/json'));
    if (
        !str_contains($response->getHeaderLine('Cache-Control'), 'private, no-store')
        || $response->hasHeader('phpdebugbar-id')
    ) {
        throw new RuntimeException('Announcement response lost privacy or debug suppression.');
    }
    return $response;
};
$path = '/portal/api/announcements/';
$page = $request($path);
$data = json_decode((string) $page->getBody(), true, flags: JSON_THROW_ON_ERROR);
if ($page->getStatusCode() !== 200 || array_column($data['items'], 'id') !== [$id->value]) {
    throw new RuntimeException('Announcement page did not resolve the signed-in reader.');
}
$detail = $request($path . $id->value . '/');
$data = json_decode((string) $detail->getBody(), true, flags: JSON_THROW_ON_ERROR);
if ($detail->getStatusCode() !== 200 || !str_contains($data['content_html'], '<strong>safe</strong>')) {
    throw new RuntimeException('Announcement detail lost its approved formatting.');
}
$missing = $app->router->match($get->withMethod('POST')
    ->withUri(new \Laminas\Diactoros\Uri('https://campus.example.invalid' . $path . $id->value . '/read/'))
    ->withHeader('Accept', 'application/json')->withParsedBody(['revision' => 1]));
if ($missing->getStatusCode() === 200 || $sis->sql->rows('sis_announcement_reads') !== []) {
    throw new RuntimeException('An announcement read bypassed CSRF.');
}
$read = $teachingPost($path . $id->value . '/read/', ['revision' => 1,
    'owner_user_id' => $originalSubmissionActor->value]);
$rows = $sis->sql->rows('sis_announcement_reads');
if (
    $read->getStatusCode() !== 200 || count($rows) !== 1
    || $rows[0]['owner_user_id'] !== $sis->access->actor->value || $read->hasHeader('phpdebugbar-id')
) {
    throw new RuntimeException('Announcement read accepted another account or lost private routing.');
}
if ($teachingPost($path . $id->value . '/read/', ['revision' => 2])->getStatusCode() !== 409) {
    throw new RuntimeException('An announcement read accepted another revision.');
}
if ($request($path . '?include_read=invalid')->getStatusCode() !== 422) {
    throw new RuntimeException('An announcement filter accepted invalid input.');
}
if ($before !== $sis->sql->rows('sis_history_events')) {
    throw new RuntimeException('Announcement reading changed authoritative history.');
}

$deleted = $teachingPost($path . $id->value . '/delete/', ['revision' => 1]);
if (
    $deleted->getStatusCode() !== 200 || $sis->sql->rows('sis_announcement_reads') !== []
    || count($sis->sql->rows('sis_announcement_dismissals')) !== 1
    || json_decode((string) $request($path . '?include_read=1')->getBody(), true)['items'] !== []
) {
    throw new RuntimeException('Announcement deletion did not erase reading state and suppress redelivery.');
}
if (
    $teachingPost($path . $id->value . '/read/', ['revision' => 1])->getStatusCode() !== 404
    || $teachingPost($path . $id->value . '/delete/', ['revision' => 1])->getStatusCode() !== 200
    || $before !== $sis->sql->rows('sis_history_events')
) {
    throw new RuntimeException('Deleted announcements were resurrected or changed business history.');
}

// Role-targeted authoring, all private readers and the role-label directory use the same real providers.
$sis->access->staff = true;
$role = \Domain\Shared\ValueObject\EntityId::generate();
$sis->sql->insert('sis_access_roles', ['id' => $role->value, 'name' => 'fictional_announcement_role',
    'description' => 'Fictional role.', 'is_system' => 0]);
$roles = $request('/sis/portal/audience-roles/?search=fictional_announcement_role');
$roleRows = json_decode((string) $roles->getBody(), true, flags: JSON_THROW_ON_ERROR);
if (
    $roles->getStatusCode() !== 200 ||
    $roleRows['items'] !== [['id' => $role->value, 'name' => 'fictional_announcement_role']]
) {
    throw new RuntimeException('The role directory lost its bounded label-only contract.');
}
$payload = ['kind' => 'news', 'code' => 'fictional-role-announcement', 'title' => 'Fictional role notice',
    'content' => 'Fictional role-scoped wording.', 'audience' => 'roles', 'audience_roles' => [$role->value],
    'content_format' => 'plain'];
foreach ([null, 'not-a-list', []] as $invalidRoles) {
    $invalidAudience = $teachingPost('/sis/portal/editor/', [...$payload, 'audience_roles' => $invalidRoles]);
    if ($invalidAudience->getStatusCode() !== 422) {
        throw new RuntimeException('An untyped or empty custom audience reached storage.');
    }
}
$missing = $app->router->match($get->withMethod('POST')
    ->withUri(new \Laminas\Diactoros\Uri('https://campus.example.invalid/sis/portal/editor/'))
    ->withHeader('Accept', 'application/json')->withParsedBody($payload));
if ($missing->getStatusCode() !== 403) {
    throw new RuntimeException('Role-targeted authoring bypassed CSRF.');
}
$saved = $teachingPost('/sis/portal/editor/', $payload);
if ($saved->getStatusCode() !== 201) {
    throw new RuntimeException('The role-targeted authoring route rejected its current contract.');
}
$rolePost = new \Domain\Shared\ValueObject\EntityId(json_decode((string) $saved->getBody(), true)['id']);
$review = $content->review($rolePost, 'approve', $content->get($rolePost)['revision'], $window, null);
$content->confirm(
    $rolePost,
    new \Domain\Shared\ValueObject\EntityId($review['operation_id']),
    'approve',
    $review['revision'],
    $window,
    null,
    $review['review_token']
);
$roleReader = $sis->user();
$sis->sql->insert('sis_access_user_roles', ['id' => \Domain\Shared\ValueObject\EntityId::generate()->value,
    'role_id' => $role->value, 'user_id' => $roleReader->value]);
$sis->access->actor = $roleReader;
$sis->access->staff = false;
$sis->access->grants = [];
if (
    $request('/sis/portal/audience-roles/')->getStatusCode() !== 403
    || $request('/sis/portal/content/fictional-role-announcement/')->getStatusCode() !== 200
    || $request($path . $rolePost->value . '/')->getStatusCode() !== 200
    || $teachingPost($path . $rolePost->value . '/read/', ['revision' => 1])->getStatusCode() !== 200
) {
    throw new RuntimeException('Role labels and current private reading lost their independent authority.');
}
$sis->sql->execute('DELETE FROM sis_access_user_roles WHERE user_id = ?', [$roleReader->value]);
$unmatched = json_decode((string) $request($path . '?include_read=1')->getBody(), true, flags: JSON_THROW_ON_ERROR);
if (
    in_array($rolePost->value, array_column($unmatched['items'], 'id'), true)
    || $request($path . $rolePost->value . '/')->getStatusCode() !== 404
    || $request('/sis/portal/content/fictional-role-announcement/')->getStatusCode() !== 404
    || $teachingPost($path . $rolePost->value . '/delete/', ['revision' => 1])->getStatusCode() !== 404
    || $before !== $sis->sql->rows('sis_history_events')
) {
    throw new RuntimeException('Role removal left a private content bypass or changed business history.');
}

// Program audiences use canonical active student pursuits through the same private routes.
$sis->access->staff = true;
$program = $sis->sql->one('sis_programs', ['id' => $sis->program->value]);
$directory = $request('/sis/portal/audience-programs/?search=' . rawurlencode($program['code']));
$labels = json_decode((string) $directory->getBody(), true, flags: JSON_THROW_ON_ERROR);
if (
    $directory->getStatusCode() !== 200 || $labels['items'] !== [[
    'id' => $program['id'], 'name' => $program['code'] . ' · ' . $program['title'],
    ]]
) {
    throw new RuntimeException('The program directory lost its bounded label-only contract.');
}
$programPayload = ['kind' => 'news', 'code' => 'fictional-program-announcement',
    'title' => 'Fictional program notice', 'content' => 'Fictional program-scoped wording.',
    'audience' => 'programs', 'audience_programs' => [$program['id']], 'content_format' => 'plain'];
foreach ([null, 'not-a-list', []] as $invalidPrograms) {
    if (
        $teachingPost('/sis/portal/editor/', [...$programPayload, 'audience_programs' => $invalidPrograms])
        ->getStatusCode() !== 422
    ) {
        throw new RuntimeException('An untyped or empty program audience reached storage.');
    }
}
$unsafe = $app->router->match($get->withMethod('POST')
    ->withUri(new \Laminas\Diactoros\Uri('https://campus.example.invalid/sis/portal/editor/'))
    ->withHeader('Accept', 'application/json')->withParsedBody($programPayload));
if ($unsafe->getStatusCode() !== 403) {
    throw new RuntimeException('Program-targeted authoring bypassed framework CSRF.');
}
$saved = $teachingPost('/sis/portal/editor/', $programPayload);
if ($saved->getStatusCode() !== 201) {
    throw new RuntimeException('The program audience save route rejected its current contract.');
}
$programPost = new \Domain\Shared\ValueObject\EntityId(json_decode((string) $saved->getBody(), true)['id']);
$review = $content->review($programPost, 'approve', $content->get($programPost)['revision'], $window, null);
$content->confirm(
    $programPost,
    new \Domain\Shared\ValueObject\EntityId($review['operation_id']),
    'approve',
    $review['revision'],
    $window,
    null,
    $review['review_token']
);
$programReader = $sis->user();
$programStudent = $sis->student($programReader);
$programFacts = $sis->sql->rows('sis_history_events');
$sis->access->actor = $programReader;
$sis->access->staff = false;
$sis->access->grants = [];
if (
    $request('/sis/portal/audience-programs/')->getStatusCode() !== 403
    || $request('/sis/portal/content/fictional-program-announcement/')->getStatusCode() !== 200
    || $request($path . $programPost->value . '/')->getStatusCode() !== 200
    || $teachingPost($path . $programPost->value . '/read/', ['revision' => 1])->getStatusCode() !== 200
) {
    throw new RuntimeException(
        'Program authoring labels and private student reading lost their independent authority.'
    );
}
// A fictional projection change exercises current membership; no historical closure is invented.
$sis->sql->update('sis_student_programs', ['status' => 'closed'], ['student_id' => $programStudent->id->value]);
$available = json_decode((string) $request($path . '?include_read=1')->getBody(), true, flags: JSON_THROW_ON_ERROR);
if (
    in_array($programPost->value, array_column($available['items'], 'id'), true)
    || $request($path . $programPost->value . '/')->getStatusCode() !== 404
    || $request('/sis/portal/content/fictional-program-announcement/')->getStatusCode() !== 404
    || $teachingPost($path . $programPost->value . '/delete/', ['revision' => 1])->getStatusCode() !== 404
    || $programFacts !== $sis->sql->rows('sis_history_events')
) {
    throw new RuntimeException('Pursuit closure left a private reader bypass or altered protected history.');
}
