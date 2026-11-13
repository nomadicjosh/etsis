<?php
declare(strict_types=1);
// Included inside the existing isolated connector sandbox; all accounts/roles and SQL are fictional.
define("SITEID", 1);
define("ENROL_INSTANCE_ENABLED", 0);
define("ENROL_USER_ACTIVE", 0);
define("ENROL_USER_SUSPENDED", 1);
class context_course
{
    public int $id;
    public function __construct(int $course)
    {
        $this->id = $course + 100;
    }
    public static function instance(int $course): self
    {
        return new self($course);
    }
}
function user_can_assign(context_course $context, int $role): bool
{
    return ($GLOBALS["argv"][1] ?? "") !== "roster_assign_denied";
}
function enrol_is_enabled(string $type): bool
{
    return true;
}
function role_unassign(int $role, int $user, int $context, string $component, int $item): void
{
    global $DB;
    $q = $DB->pdo->prepare(
        "DELETE FROM role_assignments WHERE roleid=? AND userid=? AND contextid=? AND component=? AND itemid=?",
    );
    $q->execute([$role, $user, $context, $component, $item]);
}
function enrol_get_plugin(string $type): object
{
    return new class {
        public function enrol_user(
            object $instance,
            int $user,
            int $role,
            int $start,
            int $end,
            int $status,
        ): void {
            global $DB;
            $row = $DB->get_record("user_enrolments", ["enrolid" => $instance->id, "userid" => $user]);
            if (!$row) {
                $DB->insert_record(
                    "user_enrolments",
                    (object) ["enrolid" => $instance->id, "userid" => $user, "status" => $status],
                );
            } else {
                $DB->set_field("user_enrolments", "status", $status, ["id" => $row->id]);
            }
            $DB->insert_record(
                "role_assignments",
                (object) [
                    "roleid" => $role,
                    "userid" => $user,
                    "contextid" => $instance->courseid + 100,
                    "component" => "enrol_manual",
                    "itemid" => $instance->id,
                ],
            );
        }
        public function update_user_enrol(object $instance, int $user, int $status): void
        {
            global $DB;
            $DB->set_field("user_enrolments", "status", $status, [
                "enrolid" => $instance->id,
                "userid" => $user,
            ]);
        }
    };
}
$settings["rostersync"] = 1;
$settings["studentrole"] = "3";
$settings["teacherrole"] = "4";
foreach (
    [
        "CREATE TABLE role (id INTEGER PRIMARY KEY, archetype TEXT)",
        "CREATE TABLE course (id INTEGER PRIMARY KEY)",
        "CREATE TABLE enrol (id INTEGER PRIMARY KEY, courseid INTEGER, enrol TEXT, status INTEGER)",
        "CREATE TABLE user_enrolments (id INTEGER PRIMARY KEY, enrolid INTEGER, userid INTEGER, status INTEGER, UNIQUE(enrolid,userid))",
        "CREATE TABLE role_assignments (id INTEGER PRIMARY KEY, roleid INTEGER, userid INTEGER, contextid INTEGER, component TEXT, itemid INTEGER)",
        "CREATE TABLE learning_history (id INTEGER PRIMARY KEY, userid INTEGER, grade TEXT)",
        "CREATE TABLE local_etsis_members (id INTEGER PRIMARY KEY, memberhash TEXT UNIQUE, courseid INTEGER, userid INTEGER, enrolid INTEGER, version INTEGER, requesthash TEXT, studentrole INTEGER, teacherrole INTEGER)",
        "INSERT INTO role VALUES(3,'student'),(4,'editingteacher')",
        "INSERT INTO course VALUES(81)",
        "INSERT INTO enrol VALUES(1,81,'manual',0)",
        "INSERT INTO user(id,username,email,deleted,suspended) VALUES(501,'fictional','fictional@example.invalid',0,0)",
        "INSERT INTO learning_history VALUES(1,501,'fictional-retained-grade')",
        "INSERT INTO role_assignments VALUES(1,7,501,181,'enrol_cohort',9)",
    ]
    as $statement
) {
    $DB->pdo->exec($statement);
}
require $plugin . "/classes/local/roster_sync.php";
$instance = hash("sha256", $settings["instanceid"]);
$policy = \local_etsis\local\roster_sync::policy($instance);
$p = [
    "section" => "01KAT5HZXS0000000000000001",
    "course" => "81",
    "person" => "01KAT5HZXS0000000000000002",
    "user" => "501",
    "version" => 1,
    "student" => true,
    "instructor" => false,
];
$scenario = $argv[1];
$refused = false;
$status = "none";
try {
    if ($scenario === "roster_external") {
        $DB->insert_record("user_enrolments", (object) ["enrolid" => 1, "userid" => 501, "status" => 0]);
    }
    if ($scenario === "roster_disabled") {
        $settings["rostersync"] = 0;
    }
    if ($scenario === "roster_denied") {
        $authorized = false;
    }
    if ($scenario === "roster_admin") {
        $GLOBALS["fictionalSiteAdmin"] = true;
    }
    if ($scenario === "roster_policy") {
        $policy["policy_hash"] = str_repeat("f", 64);
    }
    if ($scenario === "roster_both") {
        $p["instructor"] = true;
    }
    $r = \local_etsis\local\roster_sync::execute(
        $instance,
        $policy["policy_hash"],
        json_encode($p, JSON_THROW_ON_ERROR),
    );
    $status = $r["status"];
    if ($scenario === "roster_retry") {
        $r = \local_etsis\local\roster_sync::execute(
            $instance,
            $policy["policy_hash"],
            json_encode($p, JSON_THROW_ON_ERROR),
        );
        $status = $r["status"];
    }
    if ($scenario === "roster_stale") {
        $p["version"] = 2;
        $p["student"] = false;
        \local_etsis\local\roster_sync::execute(
            $instance,
            $policy["policy_hash"],
            json_encode($p, JSON_THROW_ON_ERROR),
        );
        $p["version"] = 1;
        $p["student"] = true;
        $r = \local_etsis\local\roster_sync::execute(
            $instance,
            $policy["policy_hash"],
            json_encode($p, JSON_THROW_ON_ERROR),
        );
        $status = $r["status"];
    }
    if ($scenario === "roster_suspend") {
        $p["version"] = 2;
        $p["student"] = false;
        \local_etsis\local\roster_sync::execute(
            $instance,
            $policy["policy_hash"],
            json_encode($p, JSON_THROW_ON_ERROR),
        );
    }
    if ($scenario === "roster_changed") {
        $p["student"] = false;
        \local_etsis\local\roster_sync::execute(
            $instance,
            $policy["policy_hash"],
            json_encode($p, JSON_THROW_ON_ERROR),
        );
    }
    if ($scenario === "roster_erased") {
        $DB->set_field("local_etsis_members", "userid", 0, []);
        $p["version"] = 2;
        \local_etsis\local\roster_sync::execute(
            $instance,
            $policy["policy_hash"],
            json_encode($p, JSON_THROW_ON_ERROR),
        );
    }
} catch (Throwable) {
    $refused = true;
}
return [
    "status" => $status,
    "refused" => $refused,
    "enrollments" => (int) $DB->pdo->query("SELECT COUNT(*) FROM user_enrolments")->fetchColumn(),
    "suspended" => (int) $DB->pdo
        ->query("SELECT COUNT(*) FROM user_enrolments WHERE status=1")
        ->fetchColumn(),
    "own_roles" => (int) $DB->pdo
        ->query("SELECT COUNT(*) FROM role_assignments WHERE component='enrol_manual'")
        ->fetchColumn(),
    "other_roles" => (int) $DB->pdo
        ->query("SELECT COUNT(*) FROM role_assignments WHERE component='enrol_cohort'")
        ->fetchColumn(),
    "learning" => (int) $DB->pdo->query("SELECT COUNT(*) FROM learning_history")->fetchColumn(),
];
