<?php
declare(strict_types=1);
// Fictional native course APIs; never bootstrap Moodle or use a real database.
define("FORMAT_PLAIN", 2);
define("ENROL_INSTANCE_ENABLED", 0);
class context_coursecat
{
    public static function instance(int $category): object
    {
        return (object) ["id" => $category];
    }
}
function enrol_is_enabled(string $name): bool
{
    return true;
}
function enrol_get_plugin(string $name): object
{
    return new class {
        public function add_instance(object $course, array $data): int
        {
            global $DB;
            return $DB->insert_record(
                "enrol",
                (object) ($data + ["courseid" => $course->id, "enrol" => "manual"]),
            );
        }
    };
}
function create_course(object $data): object
{
    global $DB;
    $data->id = $DB->insert_record("course", $data);
    return $data;
}
$settings["coursecreation"] = 1;
$settings["coursecategory"] = 2;
foreach (
    [
        "CREATE TABLE course_categories (id INTEGER PRIMARY KEY)",
        "INSERT INTO course_categories VALUES(2),(3)",
        "CREATE TABLE course (id INTEGER PRIMARY KEY, shortname TEXT UNIQUE, fullname TEXT, startdate INTEGER, enddate INTEGER, category INTEGER, visible INTEGER, format TEXT, summary TEXT, summaryformat INTEGER)",
        "CREATE TABLE enrol (id INTEGER PRIMARY KEY, courseid INTEGER, enrol TEXT, status INTEGER)",
        "CREATE TABLE local_etsis_courses (id INTEGER PRIMARY KEY, operation TEXT UNIQUE, requesthash TEXT, courseid INTEGER)",
    ]
    as $statement
) {
    $DB->pdo->exec($statement);
}
require $plugin . "/classes/local/course_provisioner.php";
$instance = hash("sha256", $settings["instanceid"]);
$operation = "01KAT5HZXS0000000000000001";
$p = [
    "shortname" => "etsis-01KAT5HZXS0000000000000002",
    "fullname" => "26/FA · MATH.101.A · Fictional Algebra",
    "startdate" => 1790000000,
    "enddate" => 1795000000,
];
$scenario = $argv[1];
$refused = false;
$same = false;
try {
    if ($scenario === "course_disabled") {
        $settings["coursecreation"] = 0;
    }
    if ($scenario === "course_denied") {
        $authorized = false;
    }
    if ($scenario === "course_category") {
        $settings["coursecategory"] = 99;
    }
    if ($scenario === "course_rollback") {
        $DB->pdo->exec(
            "CREATE TRIGGER refuse_course_receipt BEFORE INSERT ON local_etsis_courses BEGIN SELECT RAISE(ABORT,'fictional receipt outage'); END",
        );
    }
    if ($scenario === "course_conflict") {
        $DB->insert_record("course", (object) $p);
    }
    $first = \local_etsis\local\course_provisioner::execute(
        $instance,
        $operation,
        json_encode($p, JSON_THROW_ON_ERROR),
    );
    if ($scenario === "course_changed") {
        $p["fullname"] = "Changed request";
    }
    if ($scenario === "course_moved") {
        $settings["coursecategory"] = 3;
    }
    if ($scenario === "course_deleted") {
        $DB->pdo->exec("DELETE FROM course");
    }
    $second = \local_etsis\local\course_provisioner::execute(
        $instance,
        $operation,
        json_encode($p, JSON_THROW_ON_ERROR),
    );
    $same = $first === $second;
} catch (Throwable) {
    $refused = true;
}
return [
    "refused" => $refused,
    "same" => $same,
    "courses" => (int) $DB->pdo->query("SELECT COUNT(*) FROM course")->fetchColumn(),
    "receipts" => (int) $DB->pdo->query("SELECT COUNT(*) FROM local_etsis_courses")->fetchColumn(),
    "manual_instances" => (int) $DB->pdo->query("SELECT COUNT(*) FROM enrol")->fetchColumn(),
];
