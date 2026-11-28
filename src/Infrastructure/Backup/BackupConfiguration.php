<?php

declare(strict_types=1);

namespace Infrastructure\Backup;

use Domain\Shared\Backup\BackupBudget;
use Domain\Shared\Backup\BackupPlan;
use RuntimeException;

final readonly class BackupConfiguration implements \JsonSerializable
{
    public function __construct(
        public BackupPlan $plan,
        public string $restic,
        public string $repository,
        public string $passwordFile,
        public string $workDirectory,
        #[\SensitiveParameter] public array $credentials,
        public array $tools,
        public int $seconds,
        public int $bytes,
        public int $files,
    ) {
    }

    public static function load(
        string $path,
        string $project,
        ?string $keyring = null,
        bool $capture = false,
    ): self {
        $paths = new PrivateBackupPaths($project . "/public");
        $data = json_decode(
            $paths->secret($path),
            true,
            12,
            JSON_THROW_ON_ERROR,
        );
        $required = [
            "scope",
            "release",
            "repository",
            "restic",
            "password_file",
            "work_directory",
            "checkpoint_directory",
            "checkpoint_key",
            "includes",
            "tools",
        ];
        $allowed = [
            ...$required,
            "credentials_file",
            "seconds",
            "bytes",
            "files",
            "history_rows",
        ];
        if (
            !is_array($data) ||
            array_diff($required, array_keys($data)) !== [] ||
            array_diff(array_keys($data), $allowed) !== [] ||
            !is_array($data["includes"]) ||
            !array_is_list($data["includes"]) ||
            !is_array($data["tools"])
        ) {
            throw new RuntimeException(
                "Use the documented backup configuration.",
            );
        }
        $paths->directory($data["work_directory"]);
        $paths->directory($data["checkpoint_directory"]);
        if ($capture) {
            $paths->secret($data["checkpoint_key"], 4096);
        }
        $password = trim($paths->secret($data["password_file"], 4096));
        if (
            strlen($password) < 32 ||
            str_contains($password, "\n") ||
            str_contains($password, "\r")
        ) {
            throw new RuntimeException(
                "Use an independent recovery password of at least 32 characters.",
            );
        }
        $credentials = [];
        if (isset($data["credentials_file"])) {
            $credentials = json_decode(
                $paths->secret($data["credentials_file"]),
                true,
                4,
                JSON_THROW_ON_ERROR,
            );
            $keys = [
                "AWS_ACCESS_KEY_ID",
                "AWS_SECRET_ACCESS_KEY",
                "AWS_SESSION_TOKEN",
                "AWS_DEFAULT_REGION",
            ];
            if (
                !is_array($credentials) ||
                array_diff(array_keys($credentials), $keys) !== [] ||
                !isset(
                    $credentials["AWS_ACCESS_KEY_ID"],
                    $credentials["AWS_SECRET_ACCESS_KEY"],
                ) ||
                !array_all(
                    $credentials,
                    static fn($value) => is_string($value) &&
                        $value !== "" &&
                        strlen($value) < 4096,
                )
            ) {
                throw new RuntimeException(
                    "Use private bounded S3 credentials.",
                );
            }
        }
        $repository = $data["repository"];
        if (str_starts_with($repository, "/")) {
            $paths->directory($repository);
            if ($credentials !== []) {
                throw new RuntimeException(
                    "Local backup repositories do not accept cloud credentials.",
                );
            }
        } else {
            $url = str_starts_with($repository, "s3:https://")
            ? parse_url(substr($repository, 3))
            : false;
            if (
                !$url ||
                !isset($url["host"], $url["path"]) ||
                isset($url["user"]) ||
                isset($url["pass"]) ||
                isset($url["query"]) ||
                isset($url["fragment"]) ||
                strlen($repository) > 2048 ||
                preg_match(
                    '~(?:^|/)\.{1,2}(?:/|$)~',
                    rawurldecode($url["path"]),
                ) === 1 ||
                preg_match('/[\x00-\x20\x7f]/', $repository) === 1 ||
                trim($url["path"], "/") === "" ||
                $credentials === []
            ) {
                throw new RuntimeException(
                    "Use an explicit HTTPS S3 bucket/prefix and private credentials.",
                );
            }
        }
        $executables = [
            "restic" => $data["restic"],
            ...$capture ? $data["tools"] : [],
        ];
        foreach ($executables as $tool) {
            $paths->canonical($tool);
            if (!is_file($tool) || !is_executable($tool)) {
                throw new RuntimeException(
                    "A configured backup executable is unavailable.",
                );
            }
        }
        $includes = $data["includes"];
        foreach ($includes as $include) {
            if (!is_string($include)) {
                throw new RuntimeException("Use local backup paths.");
            }
            $paths->canonical($include);
            if ($capture && !is_file($include) && !is_dir($include)) {
                throw new RuntimeException(
                    "A selected backup path is missing.",
                );
            }
            $excluded = [
                $path,
                $data["password_file"],
                $data["checkpoint_key"],
                $data["checkpoint_directory"],
                $data["work_directory"],
                $data["credentials_file"] ?? "",
                str_starts_with($repository, "/") ? $repository : "",
                $project . "/.git",
                $project . "/storage/framework/cache",
                $project . "/storage/framework/sessions",
                $project . "/storage/framework/cookies",
                $project . "/storage/logs",
            ];
            foreach ($excluded as $external) {
                if (
                    $external !== "" &&
                    ($include === $external ||
                        str_starts_with($external, $include . "/") ||
                        str_starts_with($include, $external . "/"))
                ) {
                    throw new RuntimeException(
                        "Keep repositories and independent recovery credentials outside backed files.",
                    );
                }
            }
        }
        $mustInclude = [
            $project . "/.env",
            $project . "/.enc.key",
            $project . "/storage/app",
            $project . "/public/themes",
        ];
        if (is_dir($project . "/storage/framework/media")) {
            $mustInclude[] = $project . "/storage/framework/media";
        }
        if ($keyring !== null && $keyring !== "") {
            $mustInclude[] = $keyring;
        }
        foreach ($capture ? $mustInclude : [] as $must) {
            if (
                !array_any(
                    $includes,
                    static fn(string $include): bool => $must === $include ||
                        str_starts_with($must, $include . "/"),
                )
            ) {
                throw new RuntimeException(
                    "Include the required database artifact, configuration and key paths.",
                );
            }
        }
        $secrets = $capture
        ? [
                $project . "/.env",
                $project . "/.enc.key",
                $keyring,
                $data["checkpoint_key"],
            ]
        : [];
        foreach ($secrets as $secret) {
            if ($secret !== null && $secret !== "") {
                $stat = @lstat($secret);
                if ($stat === false || ($stat["mode"] & 0077) !== 0) {
                    throw new RuntimeException(
                        "Protect the source application and event keys before backup.",
                    );
                }
                if (
                    hash_equals(
                        $password,
                        trim(
                            (string) file_get_contents(
                                $secret,
                                false,
                                null,
                                0,
                                65536,
                            ),
                        ),
                    )
                ) {
                    throw new RuntimeException(
                        "Use a separate backup repository password.",
                    );
                }
            }
        }
        if ($capture && $keyring !== null && $keyring !== '') {
            $ring = json_decode(file_get_contents($keyring, false, null, 0, 65537), true, 8, JSON_THROW_ON_ERROR);
            if (!is_array($ring) || array_is_list($ring)) {
                throw new RuntimeException('Use the complete original event keyring.');
            }
            foreach ($ring as $key) {
                if (is_string($key) && hash_equals($password, trim($key))) {
                    throw new RuntimeException('Use a backup password separate from every event key.');
                }
            }
        }
        $plan = new BackupPlan(
            $data["scope"],
            $data["release"],
            $includes,
            $data["checkpoint_directory"],
            $data["checkpoint_key"],
            $data["history_rows"] ?? 2000,
        );
        $result = new self(
            $plan,
            $data["restic"],
            $repository,
            $data["password_file"],
            $data["work_directory"],
            $credentials,
            $data["tools"],
            $data["seconds"] ?? 120,
            $data["bytes"] ?? 1073741824,
            $data["files"] ?? 2000,
        );
        $result->budget();
        return $result;
    }

    public function budget(): BackupBudget
    {
        return new BackupBudget($this->seconds, $this->bytes, $this->files);
    }

    public function __debugInfo(): array
    {
        return ["backup_configuration" => "[private recovery settings]"];
    }

    public function jsonSerialize(): array
    {
        return $this->__debugInfo();
    }

    public function __serialize(): array
    {
        return $this->__debugInfo();
    }
}
