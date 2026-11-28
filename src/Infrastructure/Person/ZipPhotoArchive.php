<?php

declare(strict_types=1);

namespace Infrastructure\Person;

use Domain\Person\Service\PhotoPolicy;
use Domain\Person\Service\PhotoArchive;
use Domain\Shared\Exception\ValidationException;
use ZipArchive;

use function Codefy\Framework\Helpers\storage_path;
use function Codefy\Framework\Helpers\trans;

/** No extraction or user-derived filesystem paths. Plain processing scratch is private and disposable. */
final readonly class ZipPhotoArchive implements PhotoArchive
{
    private function zip(
        #[\SensitiveParameter] string $bytes,
        callable $work,
    ): mixed {
        $folder = storage_path("framework/photos");
        if (
            is_link($folder) ||
            (!is_dir($folder) && !mkdir($folder, 0700, true))
        ) {
            throw new \RuntimeException(
                "Private image scratch is unavailable.",
            );
        }
        $this->cleanScratch();
        $path = tempnam($folder, ".photo-");
        $zip = new ZipArchive();
        try {
            chmod($path, 0600);
            file_put_contents($path, $bytes);
            if (
                $zip->open(
                    $path,
                    ZipArchive::RDONLY | ZipArchive::CHECKCONS,
                ) !== true
            ) {
                throw new ValidationException([
                    [
                        "field" => "photo",
                        "message" =>
                            trans("Choose a valid flat ZIP of PNG or JPG photos."),
                    ],
                ]);
            }
            return $work($zip);
        } finally {
            if ($zip->status === ZipArchive::ER_OK) {
                try {
                    $zip->close();
                } catch (\Throwable) {
                    // A failed open has no active archive handle.
                }
            }
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    public function cleanScratch(): int
    {
        $folder = storage_path("framework/photos");
        if (!is_dir($folder) || is_link($folder)) {
            return 0;
        }
        $count = 0;
        $checked = 0;
        foreach (new \DirectoryIterator($folder) as $file) {
            if ($count >= 20 || ++$checked > 100) {
                break;
            }
            if (
                $file->isFile() &&
                !$file->isLink() &&
                str_starts_with($file->getFilename(), ".photo-") &&
                $file->getMTime() < time() - 86400
            ) {
                unlink($file->getPathname());
                $count++;
            }
        }
        return $count;
    }

    public function entries(
        #[\SensitiveParameter] string $bytes,
        string $filename,
    ): array {
        if (strlen($bytes) < 1 || strlen($bytes) > PhotoPolicy::MAX_ARCHIVE) {
            throw new ValidationException([
                [
                    "field" => "photo",
                    "message" => trans("Use a photo or ZIP up to 10 MiB."),
                ],
            ]);
        }
        if (strtolower(pathinfo($filename, PATHINFO_EXTENSION)) !== "zip") {
            $this->name($filename);
            if (strlen($bytes) > PhotoPolicy::MAX_IMAGE) {
                throw new ValidationException([
                    [
                        "field" => "photo",
                        "message" => trans("Each photo must be at most 5 MiB."),
                    ],
                ]);
            }
            return [["index" => 0, "filename" => $filename]];
        }
        return $this->zip($bytes, function (ZipArchive $zip): array {
            if ($zip->numFiles < 1 || $zip->numFiles > PhotoPolicy::MAX_FILES) {
                throw new ValidationException([
                    [
                        "field" => "photo",
                        "message" => trans("Use between one and 200 photos per ZIP."),
                    ],
                ]);
            }
            $total = 0;
            $seen = [];
            $entries = [];
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                $this->name($stat["name"]);
                $zip->getExternalAttributesIndex($i, $system, $attributes);
                if (
                    isset($seen[mb_strtolower($stat["name"])]) ||
                    (($attributes >> 16) & 0170000) === 0120000 ||
                    ($stat["encryption_method"] ?? 0) !== 0 ||
                    $stat["size"] < 1 ||
                    $stat["size"] > PhotoPolicy::MAX_IMAGE ||
                    $stat["size"] > max(1024, $stat["comp_size"]) * 100 ||
                    ($total += $stat["size"]) > 104857600
                ) {
                    throw new ValidationException([
                        [
                            "field" => "photo",
                            "message" =>
                                trans("The archive has duplicate, unsafe, encrypted or oversized entries."),
                        ],
                    ]);
                }
                $seen[mb_strtolower($stat["name"])] = true;
                $entries[] = ["index" => $i, "filename" => $stat["name"]];
            }
            return $entries;
        });
    }

    private function name(string $name): void
    {
        if (
            mb_strlen($name) > 191 ||
            !mb_check_encoding($name, "UTF-8") ||
            preg_match('/[\/\\\\\x00-\x1F\x7F]/u', $name) ||
            str_starts_with($name, ".") ||
            !in_array(
                strtolower(pathinfo($name, PATHINFO_EXTENSION)),
                ["png", "jpg"],
                true,
            )
        ) {
            throw new ValidationException([
                [
                    "field" => "photo",
                    "message" =>
                        trans("Use flat PNG or JPG filenames without paths or hidden files."),
                ],
            ]);
        }
    }

    public function read(
        #[\SensitiveParameter] string $bytes,
        string $filename,
        array $entry,
    ): string {
        $entries = $this->entries($bytes, $filename);
        if (($entries[$entry["index"]] ?? null) !== $entry) {
            throw new \Domain\Shared\EventSourcing\CorruptHistoryException();
        }
        if (strtolower(pathinfo($filename, PATHINFO_EXTENSION)) !== "zip") {
            return $bytes;
        }
        return $this->zip($bytes, static function (ZipArchive $zip) use (
            $entry,
        ): string {
            $result = $zip->getFromIndex(
                $entry["index"],
                PhotoPolicy::MAX_IMAGE + 1,
            );
            if (
                !is_string($result) ||
                strlen($result) > PhotoPolicy::MAX_IMAGE
            ) {
                throw new \Domain\Shared\EventSourcing\CorruptHistoryException();
            }
            return $result;
        });
    }
}
