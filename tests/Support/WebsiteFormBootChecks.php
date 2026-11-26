<?php

declare(strict_types=1);

$formStaff = $sis->access->staff;
$formGrants = $sis->access->grants;

// Real providers, middleware and routes; fictional SQLite only, no installed page or inquiry writes.
$formPage = $websiteFixture->draft('website-form');
$formDefinition = \Domain\WebsiteForms\ValueObject\WebsiteFormDefinition::starter();
$sis->sql->update('pages', ['data' => json_encode(['html' => ['[block slug="regular-form" id="IDcontact"]'],
    'blocks' => ['en' => ['IDcontact' => ['slug' => 'regular-form', 'settings' => ['attributes' => [
        'website-definition' => json_encode($formDefinition, JSON_THROW_ON_ERROR),
    ]]]]]], JSON_THROW_ON_ERROR)], ['id' => $formPage]);
$sis->access->staff = true;
$websiteFixture->transition($formPage, 'submit');
$websiteFixture->transition($formPage, 'approve');
$proof = $read('/portal/api/website-forms/' . $formPage . '/contact/prepare/?locale=en');
$submission = ['ticket' => $proof['ticket'], 'answers' => [
    'name' => 'Fictional inquiry', 'email' => 'fictional@example.invalid', 'message' => 'A private fictional inquiry.',
], 'website_url' => ''];
$path = '/portal/api/website-forms/' . $formPage . '/contact/submit/';
$noCsrf = $app->router->match($get->withMethod('POST')->withUri(new \Laminas\Diactoros\Uri(
    'https://campus.example.invalid' . $path
))->withParsedBody($submission));
if ($noCsrf->getStatusCode() !== 403 || $sis->sql->rows('sis_website_form_inbox') !== []) {
    throw new RuntimeException('Website intake lost CSRF protection.');
}
$before = $sis->sql->rows('sis_history_events');
$first = $api($path, $submission);
if (
    !$first['received'] || $api($path, $submission) !== $first
    || count($sis->sql->rows('sis_website_form_inbox')) !== 1
) {
    throw new RuntimeException('Website intake lost its original confirmation.');
}
$sis->access->staff = false;
$sis->access->grants = ['vihzhuo:manage', 'sis:portal:publish'];
$read('/sis/website/form-inbox/', 403);
$sis->sql->update('users', ['sign_in_enabled' => 1], ['user_id' => $sis->access->actor->value]);
$formRoles = new \Infrastructure\Persistence\Repository\SqlAccessRepository($sis->sql);
foreach (['sis:website:forms:read', 'sis:website:forms:manage'] as $duty) {
    $permission = $sis->sql->one('sis_access_permissions', ['name' => $duty]);
    $formRoles->assign(
        \Domain\Access\Enum\GrantKind::UserPermission,
        $sis->access->actor,
        new \Domain\Shared\ValueObject\EntityId($permission['id']),
        true
    );
    $sis->access->grants[] = $duty;
}
$formAdmin = $sis->sql->one('sis_access_permissions', ['name' => 'admin:access:manage']);
$formRoles->assign(
    \Domain\Access\Enum\GrantKind::UserPermission,
    $sis->access->actor,
    new \Domain\Shared\ValueObject\EntityId($formAdmin['id']),
    true
);
$sis->access->grants[] = 'admin:access:manage';
$api('/sis/website/form-access/members/', ['kind' => 'form', 'scope' => $formPage, 'form' => 'contact',
    'user_id' => $sis->access->actor->value, 'grant' => true, 'expected' => null]);
$scopeMembers = $read('/sis/website/form-access/members/?kind=form&scope=' . $formPage . '&form=contact');
if (count($scopeMembers['items']) !== 1) {
    throw new RuntimeException('The private form access route lost its explicit assignment.');
}
$formWorkingGrants = $sis->access->grants;
$sis->access->grants = ['sis:website:forms:read', 'sis:website:forms:manage'];
$app->make(\Codefy\Framework\Http\Middleware\Csrf\CsrfTokenMiddleware::class);
$formScreen = $app->router->match($get->withUri(new \Laminas\Diactoros\Uri(
    'https://campus.example.invalid/sis/website/form-inbox/view/'
)));
if (
    $formScreen->getStatusCode() !== 200 || !str_contains((string) $formScreen->getBody(), 'Review website inquiries')
    || !str_contains($formScreen->getHeaderLine('Cache-Control'), 'no-store')
) {
    throw new RuntimeException('The independent staff inbox screen required website editing or lost privacy.');
}
$sis->access->grants = $formWorkingGrants;
$list = $read('/sis/website/form-inbox/');
if (count($list['items']) !== 1 || str_contains(json_encode($list), 'fictional@example.invalid')) {
    throw new RuntimeException('The website inbox exposed answers in its listing.');
}
$inquiry = $list['items'][0];
$private = $read('/sis/website/form-inbox/' . $inquiry['id'] . '/');
if ($private['answers'] !== $submission['answers']) {
    throw new RuntimeException('The website inbox lost its retained answers.');
}
$api('/sis/website/form-inbox/' . $inquiry['id'] . '/', ['version' => 0, 'action' => 'delete']);
if (
    $sis->sql->rows('sis_website_form_inbox') !== [] || $api($path, $submission) !== $first
    || $sis->sql->rows('sis_website_form_inbox') !== [] || $sis->sql->rows('sis_history_events') !== $before
) {
    throw new RuntimeException('Deleting a website inquiry recreated it or changed academic history.');
}
$preview = $api('/sis/website/form-preview/', ['definition' => $formDefinition]);
if (!str_contains($preview['html'], 'data-preview="1"') || !str_contains($preview['html'], 'disabled')) {
    throw new RuntimeException('The native form preview allowed submission.');
}
$receiptCommand = new \Symfony\Component\Console\Tester\CommandTester(
    new \Application\Console\Commands\PruneWebsiteFormReceiptsCommand($app)
);
if ($receiptCommand->execute(['--limit' => '0']) !== 2 || $receiptCommand->execute([]) !== 0) {
    throw new RuntimeException('Website receipt cleanup ignored its limits or dry-run.');
}
$formClock = $sis->clock->time;
$sis->clock->time = $sis->clock->time->modify('+2 hours');
if (
    $receiptCommand->execute(['--apply' => true, '--limit' => '1']) !== 0
    || $sis->sql->rows('sis_website_form_receipts') !== []
) {
    throw new RuntimeException('Website receipt cleanup did not remove expired answer-free metadata.');
}
$sis->access->staff = true;

$sis->clock->time = $formClock;

$sis->access->staff = $formStaff;
$sis->access->grants = $formGrants;

require __DIR__ . '/NewsletterBootChecks.php';
