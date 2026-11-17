<?php

declare(strict_types=1);

namespace Infrastructure\Admissions;

use Domain\Admissions\Service\AdmissionDocumentStorage;
use Domain\Admissions\Service\AdmissionDocumentPolicy;
use Domain\Communications\Service\MessageCipher;
use Domain\Shared\ValueObject\EntityId;
use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use League\Flysystem\UnixVisibility\PortableVisibilityConverter;
use RuntimeException;

use function Codefy\Framework\Helpers\storage_path;

final readonly class PrivateAdmissionDocumentStorage implements AdmissionDocumentStorage
{
    private Filesystem $files;
    private string $root;
    public function __construct(private MessageCipher $cipher, ?string $root = null)
    {
        $this->root = $root ?? storage_path('app/admissions/documents');
    }
    private function files(): Filesystem
    {
        if (!isset($this->files)) {
            $visibility = PortableVisibilityConverter::fromArray([
                'file' => ['public' => 0600, 'private' => 0600],
                'dir' => ['public' => 0700, 'private' => 0700],
            ]);
            $this->files = new Filesystem(new LocalFilesystemAdapter(
                $this->root,
                $visibility,
                linkHandling: LocalFilesystemAdapter::DISALLOW_LINKS
            ));
        }
        return $this->files;
    }
    private function key(EntityId $application, EntityId $id): string
    {
        return $application->value . '/' . $id->value . '.protected';
    }
    public function put(EntityId $application, EntityId $id, #[\SensitiveParameter] string $bytes): void
    {
        $key = $this->key($application, $id);
        if ($this->files()->fileExists($key)) {
            if (!hash_equals($this->read($application, $id), $bytes)) {
                throw new RuntimeException('This document reference is already in use.');
            }
            return;
        }
        $this->files()->write($key, $this->cipher->seal($bytes), ['visibility' => 'private']);
    }
    public function read(EntityId $application, EntityId $id): string
    {
        $key = $this->key($application, $id);
        if ($this->files()->fileSize($key) > AdmissionDocumentPolicy::MAX_BYTES * 3 + 8192) {
            throw new RuntimeException('This document exceeds its protected storage limit.');
        }
        return $this->cipher->open($this->files()->read($key));
    }
    public function remove(EntityId $application, EntityId $id): void
    {
        $this->files()->delete($this->key($application, $id));
    }
}
