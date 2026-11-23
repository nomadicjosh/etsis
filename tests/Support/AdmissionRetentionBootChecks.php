<?php

declare(strict_types=1);

// Real providers, router and CSRF. The only stored file is a fictional in-memory fixture.
require __DIR__ . '/SisHttpFixture.php';
$storage = new class implements \Domain\Admissions\Service\AdmissionDocumentStorage {
    private array $files = [];
    public function put(
        \Domain\Shared\ValueObject\EntityId $application,
        \Domain\Shared\ValueObject\EntityId $id,
        string $bytes
    ): void {
        $this->files[$id->value] = $bytes;
    }
    public function read(
        \Domain\Shared\ValueObject\EntityId $application,
        \Domain\Shared\ValueObject\EntityId $id,
    ): string {
        return $this->files[$id->value];
    }
    public function remove(
        \Domain\Shared\ValueObject\EntityId $application,
        \Domain\Shared\ValueObject\EntityId $id,
    ): void {
        unset($this->files[$id->value]);
    }
};
$app->delegate(\Domain\Admissions\Service\AdmissionDocumentStorage::class, static fn() => $storage);
$app->delegate(
    \Domain\Admissions\Service\AdmissionDocumentPolicy::class,
    static fn() => new \Domain\Admissions\Service\AdmissionDocumentPolicy(true, 365, true)
);
$staff = $sis->access->actor;
$owner = $sis->user();
$person = $sis->legacyPerson($owner, 'Fictional', 'Applicant');
$application = $sis->legacyApplication($person->id, $sis->program, $sis->term)->id;
$base = '/sis/document-retention/';
$read = static fn(string $path) => $app->router->match($get->withUri(new \Laminas\Diactoros\Uri($path)));
$context = json_decode((string) $read($base)->getBody(), true, flags: JSON_THROW_ON_ERROR);
$category = $context['new_ids'][0];
$definition = ['categories' => [['id' => $category, 'code' => 'WORKING', 'title' => 'Fictional category',
    'days' => 730, 'active' => true]], 'default_id' => $category];
$reviewResponse = $teachingPost($base . 'review/', ['definition' => $definition, 'revision' => 0,
    'reason' => 'Fictional reviewed policy.']);
$review = json_decode((string) $reviewResponse->getBody(), true, flags: JSON_THROW_ON_ERROR);
if ($reviewResponse->getStatusCode() !== 200 || $teachingPost($base . 'confirm/', $review)->getStatusCode() !== 200) {
    throw new RuntimeException('Actual retention policy publication failed.');
}
$sis->access->actor = $owner;
$sis->access->staff = false;
$document = \Domain\Shared\ValueObject\EntityId::generate();
$app->make(\Domain\Admissions\Service\AdmissionDocumentService::class)->upload(
    $application,
    $document,
    'fictional.pdf',
    "%PDF-1.4\nFictional record.\n%%EOF",
    false,
);
$sis->access->actor = $staff;
$sis->access->staff = true;
$file = '/sis/applications/' . $application->value . '/documents/' . $document->value . '/category/';
$response = $teachingPost($file . 'review/', ['version' => 1, 'category_id' => $category,
    'reason' => 'Fictional private classification.']);
$proof = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
$confirmed = $teachingPost($file . 'confirm/', $proof);
if (
    $response->getStatusCode() !== 200 || $confirmed->getStatusCode() !== 200
    || (string) $teachingPost($file . 'confirm/', $proof)->getBody() !== (string) $confirmed->getBody()
) {
    throw new RuntimeException('Actual file classification lost its reviewed original result.');
}
foreach ([$base . 'history/', $file] as $path) {
    $history = $read($path);
    if (
        $history->getStatusCode() !== 200 || !str_contains($history->getHeaderLine('Cache-Control'), 'no-store')
        || $history->hasHeader('phpdebugbar-id')
    ) {
        throw new RuntimeException('Retention history lost private response protections.');
    }
}
$policy = json_decode((string) $read($base)->getBody(), true, flags: JSON_THROW_ON_ERROR);
if ($policy['revision'] !== 1 || $policy['definition'] !== $definition) {
    throw new RuntimeException('The accepted retention policy was not retained.');
}
foreach ([[], ['sis:admissions:manage'], ['sis:admissions:documents:retain']] as $grants) {
    $sis->access->actor = $owner;
    $sis->access->staff = false;
    $sis->access->grants = $grants;
    if (
        $read($base)->getStatusCode() !== 403 || $read($file)->getStatusCode() !== 403
        || $teachingPost($file . 'confirm/', $proof)->getStatusCode() !== 403
    ) {
        throw new RuntimeException('Incomplete or applicant authority reached custodial retention facts.');
    }
}
$mine = $read('/portal/api/applications/' . $application->value . '/documents/');
if (
    $mine->getStatusCode() !== 200 || str_contains((string) $mine->getBody(), 'retention_basis')
    || str_contains((string) $mine->getBody(), 'Fictional private classification.')
) {
    throw new RuntimeException('Applicant document responses exposed custodial retention metadata.');
}
echo "Retention policy, classification, exact retries, CSRF and private history verified.\n";
