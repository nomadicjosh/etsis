<?php
declare(strict_types=1);
// Fictional native category APIs; no Moodle bootstrap, accounts or network.
define("MUST_EXIST", 2);
class context_coursecat
{
    public static function instance(int $id): object
    {
        return (object) ["id" => $id];
    }
}
class core_course_category
{
    private function __construct(public int $id) {}
    public static function create(array $data): self
    {
        global $DB;
        return new self($DB->insert_record("course_categories", (object) $data));
    }
    public static function get(int $id, int $strict, bool $hidden): self
    {
        global $DB;
        if (!$DB->record_exists("course_categories", ["id" => $id])) {
            throw new RuntimeException("Missing fictional category.");
        }
        return new self($id);
    }
    public function update(array $data): void
    {
        global $DB;
        $DB->update_record("course_categories", (object) (["id" => $this->id] + $data));
    }
}
$DB->pdo->exec(
    "CREATE TABLE course_categories (id INTEGER PRIMARY KEY, name TEXT, idnumber TEXT UNIQUE, parent INTEGER, visible INTEGER)",
);
$DB->pdo->exec(
    "CREATE TABLE local_etsis_categories (id INTEGER PRIMARY KEY, sourcehash TEXT UNIQUE, categoryid INTEGER, owned INTEGER, version INTEGER, requesthash TEXT)",
);
require $plugin . "/classes/local/category_sync.php";
$instance = hash("sha256", $settings["instanceid"]);
$p = [
    "source" => "subjects",
    "reference_id" => "01KAT5HZXS0000000000000001",
    "code" => "MATH",
    "title" => "Mathematics",
    "version" => 1,
    "category_id" => "0",
];
$scenario = $argv[1];
$refused = false;
$same = false;
$status = "none";
$owned = false;
try {
    if ($scenario === "category_disabled") {
        $settings["enabled"] = 0;
    }
    if ($scenario === "category_denied") {
        $authorized = false;
    }
    if ($scenario === "category_rollback") {
        $DB->pdo->exec(
            "CREATE TRIGGER reject_category_receipt BEFORE INSERT ON local_etsis_categories BEGIN SELECT RAISE(ABORT,'fictional receipt outage'); END",
        );
    }
    if ($scenario === "category_linked") {
        $DB->insert_record(
            "course_categories",
            (object) [
                "id" => 42,
                "name" => "Existing institutional category",
                "idnumber" => "external",
                "parent" => 0,
                "visible" => 1,
            ],
        );
        $p["category_id"] = "42";
    }
    if ($scenario === "category_wrong_instance") {
        $instance = str_repeat("f", 64);
    }
    $first = \local_etsis\local\category_sync::execute($instance, json_encode($p, JSON_THROW_ON_ERROR));
    if (in_array($scenario, ["category_rename", "category_linked", "category_stale"], true)) {
        $p["version"] = 2;
        $p["title"] = "Mathematical Sciences";
    }
    if ($scenario === "category_changed") {
        $p["title"] = "Changed same version";
    }
    if ($scenario === "category_relink") {
        $p["version"] = 2;
        $p["category_id"] = "99";
    }
    if ($scenario === "category_deleted") {
        $DB->pdo->exec("DELETE FROM course_categories");
    }
    $second = \local_etsis\local\category_sync::execute($instance, json_encode($p, JSON_THROW_ON_ERROR));
    $same = $first === $second;
    $owned = $second["owned"];
    $status = $second["status"];
    if ($scenario === "category_stale") {
        $p["version"] = 1;
        $p["title"] = "Mathematics";
        $status = \local_etsis\local\category_sync::execute($instance, json_encode($p, JSON_THROW_ON_ERROR))[
            "status"
        ];
    }
} catch (Throwable) {
    $refused = true;
}
return [
    "refused" => $refused,
    "same" => $same,
    "owned" => $owned,
    "status" => $status,
    "categories" => (int) $DB->pdo->query("SELECT COUNT(*) FROM course_categories")->fetchColumn(),
    "receipts" => (int) $DB->pdo->query("SELECT COUNT(*) FROM local_etsis_categories")->fetchColumn(),
    "name" =>
        $DB->pdo->query("SELECT name FROM course_categories ORDER BY id LIMIT 1")->fetchColumn() ?: null,
];
