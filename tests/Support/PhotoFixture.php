<?php

declare(strict_types=1);

namespace Tests\Support;

use Domain\Admissions\Service\AdmissionDocumentScanner;
use Domain\Audit\Repository\AuditTrail;
use Domain\Person\Service\GravatarReader;
use Domain\Person\Service\PersonPhotoService;
use Domain\Person\Service\PersonProfileAccess;
use Domain\Person\Service\PhotoImportService;
use Domain\Person\Service\PhotoPolicy;
use Domain\Shared\Service\ReviewProof;
use Infrastructure\Identity\RandomInstitutionalIdGenerator;
use Infrastructure\Persistence\Repository\SqlAccessRepository;
use Infrastructure\Persistence\Repository\SqlPersonAccountRepository;
use Infrastructure\Persistence\Repository\SqlPersonProfileRepository;
use Infrastructure\Persistence\Repository\SqlPhotoRepository;
use Infrastructure\Person\ImagickPhotoProcessor;
use Infrastructure\Person\SqlPhotoStorage;
use Infrastructure\Person\ZipPhotoArchive;

final class PhotoFixture
{
    public static function service(
        \SisFixture $s,
        ?AdmissionDocumentScanner $scanner = null,
        ?GravatarReader $gravatar = null,
        ?PhotoPolicy $policy = null,
        ?AuditTrail $audit = null,
    ): PersonPhotoService {
        $roles = new SqlAccessRepository($s->sql);
        $history = \EventStoreFixture::history($s->connection->queryBuilder());
        $scanner ??= new class implements AdmissionDocumentScanner {
            public function scan(#[\SensitiveParameter] string $bytes): string
            {
                return "clean";
            }
        };
        $gravatar ??= new class implements GravatarReader {
            public function image(#[\SensitiveParameter] string $email): string
            {
                throw new \RuntimeException("No real Gravatar is a fixture.");
            }
        };
        return new PersonPhotoService(
            new SqlPhotoRepository($s->sql),
            new SqlPersonProfileRepository($s->sql),
            new PersonProfileAccess(
                new SqlPersonAccountRepository(
                    $s->sql,
                    $s->admissions,
                    new RandomInstitutionalIdGenerator(),
                ),
                $s->admissions,
                $roles,
                $s->access,
            ),
            $s->access,
            new \Infrastructure\Security\SqlLoginAccountStateReader($s->sql),
            $roles,
            $history,
            $history,
            new SqlPhotoStorage($s->sql, $s->dropCipher),
            new ImagickPhotoProcessor($scanner),
            $gravatar,
            $policy ?? new PhotoPolicy(true, true, 30, true),
            $s->dropCipher,
            new ReviewProof($s->dropCipher, $s->clock),
            $s->clock,
            $s->transactions,
            $audit ?? $s->audit,
        );
    }

    public static function imports(
        \SisFixture $s,
        ?PersonPhotoService $photos = null,
    ): PhotoImportService {
        return new PhotoImportService(
            $photos ?? self::service($s),
            new SqlPhotoRepository($s->sql),
            new SqlPhotoStorage($s->sql, $s->dropCipher),
            new ZipPhotoArchive(),
            new \Domain\Import\Service\ImportQueueService(
                new \Infrastructure\Persistence\Repository\SqlImportQueueRepository(
                    $s->sql,
                ),
                $s->access,
                $s->transactions,
                $s->audit,
                $s->dropCipher,
                $s->clock,
            ),
            $s->dropCipher,
            $s->transactions,
        );
    }

    public static function image(string $format = "png"): string
    {
        $image = new \Imagick();
        $image->newImage(64, 96, "skyblue");
        $image->setImageFormat($format);
        $image->setImageProperty(
            "comment",
            "Fictional metadata that must be stripped.",
        );
        $bytes = $image->getImageBlob();
        $image->clear();
        return $bytes;
    }

    public static function queue(
        \SisFixture $s,
    ): \Domain\Import\Service\ImportQueueService {
        return new \Domain\Import\Service\ImportQueueService(
            new \Infrastructure\Persistence\Repository\SqlImportQueueRepository(
                $s->sql,
            ),
            $s->access,
            $s->transactions,
            $s->audit,
            $s->dropCipher,
            $s->clock,
        );
    }

    public static function worker(
        \SisFixture $s,
    ): \Domain\Import\Service\ImportWorker {
        $roles = new SqlAccessRepository($s->sql);
        return new \Domain\Import\Service\ImportWorker(
            new \Infrastructure\Persistence\Repository\SqlImportQueueRepository(
                $s->sql,
            ),
            new \Infrastructure\Import\ReviewedImportBatchProcessor(
                $s->imports(),
                new \Domain\Import\Service\PersonImportService(
                    new \Infrastructure\Import\LeaguePersonCsvMapper(),
                    new \Infrastructure\Persistence\Repository\SqlPersonImportRepository(
                        $s->sql,
                        $s->admissions,
                    ),
                    $s->registrationService(),
                    $s->access,
                    $s->transactions,
                    $s->audit,
                    $s->dropCipher,
                    $s->clock,
                ),
                $roles,
                new \Infrastructure\Security\SqlLoginAccountStateReader(
                    $s->sql,
                ),
                self::imports($s),
            ),
            $roles,
            $s->transactions,
            $s->audit,
            $s->dropCipher,
            $s->clock,
        );
    }

    public static function zip(array $files): string
    {
        $path = tempnam(sys_get_temp_dir(), "etsis-photo-fixture-");
        $zip = new \ZipArchive();
        try {
            $zip->open($path, \ZipArchive::OVERWRITE);
            foreach ($files as $name => $bytes) {
                $zip->addFromString($name, $bytes);
            }
            $zip->close();
            return file_get_contents($path);
        } finally {
            unlink($path);
        }
    }
}
