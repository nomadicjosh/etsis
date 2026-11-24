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
