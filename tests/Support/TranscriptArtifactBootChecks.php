<?php

declare(strict_types=1);

// Actual lazy command/providers, fictional SQLite data and injected rendering; no real institution or mail.
$early = new \Symfony\Component\Console\Tester\CommandTester(
    new \Application\Console\Commands\VerifyHistoryCommand($app)
);
foreach (
    [
        ['--transcript-document' => 'Fictional private invalid option'],
        ['--transcript-document' => '00000000000000000000000000', '--events' => '500'],
        ['--transcript-document' => '00000000000000000000000000', '--streams' => '1'],
        ['--transcript-document' => '00000000000000000000000000', '--cursor' => 'private cursor'],
        ['--transcript-document' => '00000000000000000000000000', '--person' => '00000000000000000000000000'],
    ] as $options
) {
    if (
        $early->execute($options + ['--json' => true]) !== 2 ||
        json_decode($early->getDisplay(), true, flags: JSON_THROW_ON_ERROR)['error'] !== 'invalid_input' ||
        str_contains($early->getDisplay(), 'Fictional private invalid option')
    ) {
        throw new RuntimeException('Invalid artifact mode options reached storage or exposed input.');
    }
}
require_once __DIR__ . '/TranscriptFixture.php';
$t = new TranscriptFixture();
$renderer = new class implements \Domain\Transcript\Service\TranscriptDocumentRenderer {
    public function html(array $document): string
    {
        return '<html lang="en"><title>Fictional original</title></html>';
    }
    public function pdf(array $document): string
    {
        return '%PDF-1.4 Fictional original';
    }
};
$service = $t->service($renderer);
$request = $t->request();
$review = $service->review($request);
$document = new \Domain\Shared\ValueObject\EntityId($review['operation_id']);
$service->prepare($request, $document, $review['revision'], true, $review['review_token']);
$app->delegate(\Qubus\Expressive\Connection::class, static fn() => $t->sis->connection);
$app->delegate(\Qubus\Expressive\Database::class, static fn() => $t->sis->connection->queryBuilder());
$app->delegate(\Infrastructure\Persistence\SqlRecords::class, static fn() => $t->sis->sql);
$app->delegate(\Domain\Communications\Service\MessageCipher::class, static fn() => $t->campus->cipher);
$kernel->bootstrap();
foreach (
    [\Domain\Shared\EventSourcing\HistoricalIntegrityVerifier::class,
    \Domain\Transcript\Service\TranscriptDocumentRenderer::class,
    \Domain\Audit\Repository\AuditTrail::class, \Domain\Communications\Service\MailTransport::class] as $unused
) {
    $app->delegate($unused, static function (): never {
        throw new RuntimeException('Artifact inspection must not resolve business writers or renderers.');
    });
}
$command = new \Symfony\Component\Console\Tester\CommandTester($kernel->all()['sis:history:verify']);
$tables = ['sis_transcript_documents', 'sis_transcript_requests', 'sis_audit', 'sis_history_events',
    'sis_transcript_updates'];
$before = array_map($t->sis->sql->rows(...), $tables);
$t->sis->connection->pdo->exec('PRAGMA query_only = ON');
$options = ['--transcript-document' => $document->value];
if (
    $command->execute($options + ['--json' => true]) !== 0 ||
    json_decode($command->getDisplay(), true, flags: JSON_THROW_ON_ERROR)['status'] !== 'verified' ||
    array_map($t->sis->sql->rows(...), $tables) !== $before
) {
    throw new RuntimeException('Actual artifact verifier lost read-only provider wiring.');
}
foreach (
    ['Fictional', '<html', '%PDF-', $request->value, $before[0][0]['pdf_hash'],
    $before[0][0]['encrypted_content']] as $private
) {
    if (str_contains($command->getDisplay(), $private)) {
        throw new RuntimeException('Artifact verification output exposed private retained content.');
    }
}
if ($command->execute($options) !== 0 || !str_contains($command->getDisplay(), 'No records changed')) {
    throw new RuntimeException('Artifact inspection lost its fixed operator result.');
}
$t->sis->connection->pdo->exec('PRAGMA query_only = OFF');
$t->sis->sql->update(
    'sis_transcript_documents',
    ['encrypted_content' => 'Fictional damaged artifact'],
    ['id' => $document->value]
);
$t->sis->connection->pdo->exec('PRAGMA query_only = ON');
if (
    $command->execute($options + ['--json' => true]) !== 1 ||
    json_decode($command->getDisplay(), true, flags: JSON_THROW_ON_ERROR)['status'] !== 'unverified' ||
    str_contains($command->getDisplay(), 'damaged artifact')
) {
    throw new RuntimeException('Actual artifact diagnostics accepted corruption or disclosed its content.');
}
