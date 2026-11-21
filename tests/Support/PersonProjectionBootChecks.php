<?php

declare(strict_types=1);

// Isolated fictional bootstrap. No institutional configuration, profile values or external providers.
$early = new \Symfony\Component\Console\Tester\CommandTester(
    new \Application\Console\Commands\VerifyHistoryCommand($app)
);
foreach (
    [
    ['--person' => 'fictional-private-input'],
    ['--person' => '00000000000000000000000000', '--streams' => '10'],
    ['--person' => '00000000000000000000000000', '--events' => '0'],
    ['--person' => '00000000000000000000000000', '--events' => '2001'],
    ['--person' => '00000000000000000000000000', '--cursor' => 'fictional-private-input'],
    ['--person' => '00000000000000000000000000', '--account' => '00000000000000000000000000'],
    ['--person' => '00000000000000000000000000', '--student' => '00000000000000000000000000'],
    ] as $options
) {
    if (
        $early->execute(['--json' => true] + $options) !== 2 ||
        str_contains($early->getDisplay(), 'fictional-private-input')
    ) {
        throw new RuntimeException('Invalid person verification resolved storage or exposed input.');
    }
}
require_once __DIR__ . '/CampusFixture.php';
$c = new CampusFixture();
$s = $c->sis;
$person = $s->registrationService()->register(
    \Domain\Shared\ValueObject\EntityId::generate(),
    'Fictional',
    'Profile',
    'profile@example.invalid',
    'fictional_profile',
    \Codefy\Framework\Support\Password::hash('fictional-profile-password-long-enough')
);
$s->access->actor = $person->ownerUserId;
$c->contacts->save('Fictional private preferred', '00012345', 'Fictional private mailing', 0);
$c->emergency->save([['name' => 'Fictional private contact', 'phone' => '00098765',
    'relationship' => 'Parent', 'email' => 'contact@example.invalid']], 0);
$app->delegate(\Qubus\Expressive\Connection::class, static fn() => $s->connection);
$app->delegate(\Qubus\Expressive\Database::class, static fn() => $s->connection->queryBuilder());
$app->delegate(\Infrastructure\Persistence\SqlRecords::class, static fn() => $s->sql);
$app->delegate(\Infrastructure\EventSourcing\ProtectedEventCodec::class, static fn() =>
    new \Infrastructure\EventSourcing\ProtectedEventCodec(EventStoreFixture::config($s->connection->queryBuilder())));
$app->delegate(\Infrastructure\EventSourcing\SqlHistoricalEventStore::class, static fn() =>
    EventStoreFixture::history($s->connection->queryBuilder()));
$app->delegate(\Infrastructure\Communications\DefuseMessageCipher::class, static fn() => $c->cipher);
$kernel->bootstrap();
$test = new \Symfony\Component\Console\Tester\CommandTester($kernel->all()['sis:history:verify']);
$digest = static fn(): array => array_map($s->sql->rows(...), [
    'sis_history_events', 'sis_history_streams', 'sis_history_operations', 'sis_persons',
    'sis_person_identifiers', 'sis_portal_contacts', 'sis_person_addresses', 'sis_person_emergency_contacts',
    'sis_person_education', 'users', 'sis_audit',
]);
$options = ['--person' => $person->id->value, '--json' => true];
$before = $digest();
$s->connection->pdo->exec('PRAGMA query_only = ON');
if ($test->execute($options) !== 0) {
    throw new RuntimeException('The actual provider failed a healthy read-only person check.');
}
$result = json_decode($test->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
if (
    $result['status'] !== 'verified' || $result['events_checked'] !== 3 ||
    $result['scope'] !== 'person_profile' || $result['records_changed'] !== false
) {
    throw new RuntimeException('Person verification returned incorrect minimized evidence.');
}
foreach (['Fictional', 'profile@example', '00012345', '00098765', 'encrypted', 'sealed', 'password'] as $private) {
    if (str_contains($test->getDisplay(), $private)) {
        throw new RuntimeException('Person verification exposed protected values.');
    }
}
if (
    $test->execute(['--events' => '1'] + $options) !== 3 ||
    json_decode($test->getDisplay(), true)['status'] !== 'limited'
) {
    throw new RuntimeException('A bounded person check claimed completion.');
}
if (
    $test->execute(['--person' => '00000000000000000000000000', '--json' => true]) !== 1 ||
    json_decode($test->getDisplay(), true)['issues'] !== ['identity']
) {
    throw new RuntimeException('A missing person was certified as a known empty profile.');
}
if (
    $test->execute(['--person' => $person->id->value]) !== 0 ||
    !str_contains($test->getDisplay(), 'No records were changed') || $before !== $digest()
) {
    throw new RuntimeException('Text person verification changed records or failed its provider path.');
}
$s->connection->pdo->exec('PRAGMA query_only = OFF');
$legacy = $s->admissions->person($s->student($s->user())->personId);
$s->sql->update(
    'users',
    ['first_name' => $legacy->givenName, 'last_name' => $legacy->familyName],
    ['user_id' => $legacy->ownerUserId->value]
);
$before = $digest();
if (
    $test->execute(['--person' => $legacy->id->value, '--json' => true]) !== 3 ||
    json_decode($test->getDisplay(), true)['unrecorded_components'] !== ['identity'] || $before !== $digest()
) {
    throw new RuntimeException('Earlier unknown profiles were adopted or claimed complete.');
}
$s->sql->update('sis_persons', ['directory_restricted' => 0], ['id' => $person->id->value]);
$before = $digest();
if (
    $test->execute($options) !== 1 || json_decode($test->getDisplay(), true)['issues'] !== ['identity'] ||
    $before !== $digest()
) {
    throw new RuntimeException('Profile privacy drift was silently repaired or not identified.');
}
