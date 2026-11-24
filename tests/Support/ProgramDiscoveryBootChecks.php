<?php

declare(strict_types=1);

require_once __DIR__ . '/ProgramDiscoveryFixture.php';
$discoveryFixture = new \Tests\Support\ProgramDiscoveryFixture($sis);
$catalog = $discoveryFixture->publish();
$path = '/sis/programs/' . $sis->program->value . '/website-catalog/';
$readProgram = static function (string $path) use ($app, $get): \Psr\Http\Message\ResponseInterface {
    $request = $get->withUri(new \Laminas\Diactoros\Uri('https://campus.example.invalid' . $path));
    $response = $app->router->match($request);
    if (
        !str_contains($response->getHeaderLine('Cache-Control'), 'no-store')
        || $response->hasHeader('phpdebugbar-id')
    ) {
        throw new RuntimeException('The public/private catalog response lost its transport boundary.');
    }
    return $response;
};
$public = '/portal/programs/' . $sis->program->value . '/';
if ($readProgram($public)->getStatusCode() !== 404) {
    throw new RuntimeException('An academic publication became public without explicit website approval.');
}
$current = json_decode((string) $readProgram($path)->getBody(), true, flags: JSON_THROW_ON_ERROR);
$command = ['catalog_id' => $catalog->value, 'visible' => true, 'version' => $current['version']];
$invalid = $teachingPost($path . 'review/', $command + ['visible' => false]);
if ($invalid->getStatusCode() !== 200) {
    throw new RuntimeException('The website catalog review did not resolve through actual providers.');
}
$review = json_decode((string) $invalid->getBody(), true, flags: JSON_THROW_ON_ERROR);
$events = $sis->sql->rows('sis_history_events');
$accepted = $teachingPost($path . 'commit/', $command + array_intersect_key(
    $review,
    array_flip(['operation_id', 'revision', 'review_token'])
));
$detail = $readProgram($public);
if (
    $accepted->getStatusCode() !== 200 || $detail->getStatusCode() !== 200
    || !str_contains((string) $detail->getBody(), 'Fictional Arts program')
    || str_contains((string) $detail->getBody(), '<script>literal</script>')
    || $events !== $sis->sql->rows('sis_history_events')
) {
    throw new RuntimeException('Publishing changed academic facts, exposed markup or failed public rendering.');
}
$before = $sis->sql->rows('sis_program_discovery');
$sis->access->staff = false;
$sis->access->grants = ['sis:catalog:manage', 'sis:portal:manage'];
if (
    $teachingPost($path . 'review/', $command)->getStatusCode() !== 403
    || $readProgram($public)->getStatusCode() !== 200
    || $before !== $sis->sql->rows('sis_program_discovery')
) {
    throw new RuntimeException('Website publication authority was confused with ordinary catalog/public access.');
}
$sis->access->staff = true;
$sis->access->grants = [];
$hide = json_decode((string) $teachingPost($path . 'review/', array_replace(
    $command,
    ['visible' => false, 'version' => 1]
))->getBody(), true, flags: JSON_THROW_ON_ERROR);
$hidden = $teachingPost($path . 'commit/', array_replace($command, ['visible' => false, 'version' => 1])
    + array_intersect_key($hide, array_flip(['operation_id', 'revision', 'review_token'])));
if (
    $hidden->getStatusCode() !== 200 || $readProgram($public)->getStatusCode() !== 404
    || $events !== $sis->sql->rows('sis_history_events')
) {
    throw new RuntimeException('Withdrawing public discovery damaged academic history or left the detail available.');
}
echo "Public program discovery checks passed.\n";
