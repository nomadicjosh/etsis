<?php

declare(strict_types=1);

// Native Moodle test doubles retain their framework class and method names in one isolated process.
// phpcs:disable PSR1.Classes.ClassDeclaration.MultipleClasses
// phpcs:disable Squiz.Classes.ValidClassName.NotCamelCaps
// phpcs:disable PSR1.Methods.CamelCapsMethodName.NotCamelCaps

namespace core_external {
    final class external_api
    {
        public static function validate_context(object $context): void
        {
        }
    }
}

namespace local_etsis\local {
    final class instance_identity
    {
        public static function read(): string
        {
            return str_repeat("a", 32);
        }
    }
}

namespace {
    define("MOODLE_INTERNAL", true);
    define("SITEID", 1);
    define("GRADE_TYPE_VALUE", 1);
    $mode = $argv[1] ?? "available";
    final class context_system
    {
        public static function instance(): self
        {
            return new self();
        }
    }
    final class context_course
    {
        public static function instance(int $course): self
        {
            if ($course !== 42) {
                throw new RuntimeException("Fictional foreign course.");
            }
            return new self();
        }
    }
    final class moodle_exception extends RuntimeException
    {
        public function __construct(string $key, string $component)
        {
            parent::__construct($key);
        }
    }
    function require_capability(string $capability, object $context): void
    {
        if (($GLOBALS["mode"] ?? "") === "denied") {
            throw new moodle_exception("denied", "local_etsis");
        }
    }
    function get_config(string $component, string $key): int
    {
        return ($GLOBALS["mode"] ?? "") === "disabled" ? 0 : 1;
    }
    function is_enrolled(
        object $context,
        int $user,
        string $capability,
        bool $active,
    ): bool {
        if ($active) {
            throw new RuntimeException('Read retained course membership after access suspension.');
        }
        return $user === 73 && ($GLOBALS["mode"] ?? "") !== "not_enrolled";
    }
    $DB = new class {
        public int $reads = 0;
        public function get_record(
            string $table,
            array $where,
            string $fields,
        ): object|false {
            $this->reads++;
            if (
                str_contains($fields, "feedback") ||
                str_contains($fields, "*")
            ) {
                throw new RuntimeException("Overbroad grade export.");
            }
            $mode = $GLOBALS["mode"];
            return match ($table) {
                "user" => (object) [
                    "id" => 73,
                    "deleted" => 0,
                    "suspended" => $mode === "suspended" ? 1 : 0,
                ],
                "grade_items" => $mode === "missing_item"
                ? false
                : (object) [
                        "id" => 97,
                        "gradetype" => $mode === "scale" ? 2 : 1,
                        "grademin" => 0,
                        "grademax" => 100,
                        "hidden" => 0,
                        "needsupdate" => $mode === "stale" ? 1 : 0,
                        "timemodified" => 1234,
                    ],
                "grade_grades" => (object) [
                    "id" => 98,
                    "itemid" => 97,
                    "finalgrade" => $mode === "ungraded" ? null : 91.25,
                    "hidden" => $mode === "hidden" ? 1 : 0,
                    "timemodified" => 1235,
                ],
                default => throw new RuntimeException(
                    "Unexpected database access.",
                ),
            };
        }
        public function __call(string $name, array $arguments): never
        {
            throw new RuntimeException("Grade export must not mutate Moodle.");
        }
    };
    $temporary =
    sys_get_temp_dir() .
    "/etsis-grade-connector-" .
    bin2hex(random_bytes(8));
    mkdir($temporary, 0700);
    file_put_contents($temporary . "/enrollib.php", "<?php");
    register_shutdown_function(static function () use ($temporary): void {
        unlink($temporary . "/enrollib.php");
        rmdir($temporary);
    });
    $CFG = (object) ["libdir" => $temporary];
    require dirname(__DIR__, 2) .
    "/integrations/moodle/local/etsis/classes/local/course_grade.php";
    $expected = match ($mode) {
        "hidden" => "hidden",
        "ungraded", "missing_item" => "ungraded",
        "scale", "stale" => "unsupported",
        default => "available",
    };
    try {
        $result = \local_etsis\local\course_grade::read(
            hash("sha256", str_repeat("a", 32)),
            42,
            73,
        );
        if (
            in_array(
                $mode,
                ["denied", "disabled", "not_enrolled", "suspended"],
                true,
            ) ||
            $result["grade"]["state"] !== $expected ||
            ($expected === "available" &&
                $result["grade"]["grade"] !== "91.25000")
        ) {
            throw new RuntimeException(
                "Course-grade sandbox assertion failed.",
            );
        }
        if ($expected !== "available" && $result["grade"]["grade"] !== null) {
            throw new RuntimeException("Hidden or unavailable value leaked.");
        }
    } catch (moodle_exception $failure) {
        if (
            !in_array(
                $mode,
                ["denied", "disabled", "not_enrolled", "suspended"],
                true,
            )
        ) {
            throw $failure;
        }
    }
}
