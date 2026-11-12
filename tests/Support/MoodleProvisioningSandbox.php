<?php
declare(strict_types=1);
namespace core_external {
    class external_api
    {
        public static function validate_context(mixed $context): void {}
    }
}
namespace core\lock {
    class lock_config
    {
        public static function get_lock_factory(string $name): object
        {
            return new class {
                public function get_lock(string $key, int $timeout): object
                {
                    return new class {
                        public function release(): void {}
                    };
                }
            };
        }
    }
}
namespace {
    // Isolated contract sandbox, not a replacement for native Moodle acceptance testing.
    // No Moodle bootstrap, real database, network, mail transport or institutional environment is loaded.
    define("MOODLE_INTERNAL", true);
    define("PARAM_NOTAGS", 1);
    define("AUTH_PASSWORD_NOT_CACHED", "not cached");
    class moodle_exception extends RuntimeException {}
    class context_system
    {
        public static function instance(): object
        {
            return (object) ["id" => 1];
        }
    }
    class core_text
    {
        public static function strlen(string $value): int
        {
            return mb_strlen($value);
        }
    }
    $settings = [
        "instanceid" => str_repeat("a", 32),
        "enabled" => 1,
        "provisioning" => 1,
        "passwordemail" => 1,
        "ssoauth" => "ldap",
        "policyrevision" => "1",
    ];
    $authorized = true;
    $sent = 0;
    $sendResult = true;
    $updates = 0;
    function get_config(string $component, string $key): mixed
    {
        global $settings;
        return $settings[$key] ?? false;
    }
    function require_capability(string $capability, mixed $context): void
    {
        global $authorized;
        if (!$authorized) {
            throw new moodle_exception("denied");
        }
    }
    function is_enabled_auth(string $auth): bool
    {
        return in_array($auth, ["manual", "ldap"], true);
    }
    function get_auth_plugin(string $auth): object
    {
        return new class ($auth) {
            public function __construct(private string $auth) {}
            public function is_internal(): bool
            {
                return $this->auth === "manual";
            }
            public function can_reset_password(): bool
            {
                return $this->auth === "manual";
            }
        };
    }
    function validate_email(string $email): bool
    {
        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }
    function clean_param(string $text, int $type): string
    {
        return strip_tags($text);
    }
    function hash_internal_user_password(string $text): string
    {
        return password_hash($text, PASSWORD_DEFAULT);
    }
    function user_create_user(object $user, bool $updatepassword, bool $trigger): int
    {
        global $DB, $updates;
        if ($updatepassword) {
            $updates++;
            throw new LogicException("External password updates forbidden.");
        }
        return $DB->insert_record("user", $user);
    }
    function is_siteadmin(object $user): bool
    {
        return $GLOBALS["fictionalSiteAdmin"] ?? false;
    }
    function has_capability(string $cap, mixed $context, int $user): bool
    {
        return true;
    }
    function core_login_generate_password_reset(object $user): object
    {
        global $DB;
        $DB->insert_record("user_password_resets", (object) ["userid" => $user->id]);
        return (object) ["userid" => $user->id];
    }
    function send_password_change_confirmation_email(object $user, object $reset): bool
    {
        global $sent, $sendResult;
        $sent++;
        return $sendResult;
    }
    final class SandboxDb
    {
        public PDO $pdo;
        public bool $failReceipt = false;
        public function __construct()
        {
            $this->pdo = new PDO("sqlite::memory:");
            $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $this->pdo->exec(
                "CREATE TABLE user (id INTEGER PRIMARY KEY, username TEXT UNIQUE, email TEXT, firstname TEXT, lastname TEXT, auth TEXT, password TEXT, confirmed INTEGER, mnethostid INTEGER, maildisplay INTEGER, deleted INTEGER DEFAULT 0, suspended INTEGER DEFAULT 0)",
            );
            $this->pdo->exec(
                "CREATE TABLE local_etsis_accounts (id INTEGER PRIMARY KEY, operation TEXT UNIQUE, requesthash TEXT, userid INTEGER, signinstatus TEXT, timecreated INTEGER)",
            );
            $this->pdo->exec("CREATE TABLE user_password_resets (id INTEGER PRIMARY KEY, userid INTEGER)");
        }
        public function get_record(string $table, array $criteria, string $fields = "*"): object|false
        {
            $q = $this->pdo->prepare(
                "SELECT " . $fields . " FROM " .
                    $table .
                    " WHERE " .
                    implode(" AND ", array_map(static fn($key) => $key . " = ?", array_keys($criteria))),
            );
            $q->execute(array_values($criteria));
            return $q->fetch(PDO::FETCH_OBJ);
        }
        public function record_exists(string $table, array $criteria): bool
        {
            return $this->get_record($table, $criteria) !== false;
        }
        public function record_exists_sql(string $sql, array $params): bool
        {
            $q = $this->pdo->prepare(str_replace("{user}", "user", $sql));
            $q->execute($params);
            return $q->fetchColumn() !== false;
        }
        public function sql_equal(string $field, string $param, bool $case): string
        {
            return "$field = $param COLLATE NOCASE";
        }
        public function insert_record(string $table, object $record): int
        {
            if ($table === "local_etsis_accounts" && $this->failReceipt) {
                throw new RuntimeException("Fictional receipt outage");
            }
            $data = (array) $record;
            $q = $this->pdo->prepare(
                "INSERT INTO " .
                    $table .
                    " (" .
                    implode(",", array_keys($data)) .
                    ") VALUES (" .
                    implode(",", array_fill(0, count($data), "?")) .
                    ")",
            );
            $q->execute(array_values($data));
            return (int) $this->pdo->lastInsertId();
        }
        public function set_field(string $table, string $field, mixed $value, array $criteria): void
        {
            $where =
                $criteria === []
                    ? ""
                    : " WHERE " .
                        implode(" AND ", array_map(static fn($key) => $key . " = ?", array_keys($criteria)));
            $q = $this->pdo->prepare("UPDATE $table SET $field = ?" . $where);
            $q->execute([$value, ...array_values($criteria)]);
        }
        public function start_delegated_transaction(): object
        {
            $this->pdo->beginTransaction();
            return new class ($this->pdo) {
                public function __construct(private PDO $pdo) {}
                public function allow_commit(): void
                {
                    $this->pdo->commit();
                }
                public function rollback(Throwable $error): never
                {
                    $this->pdo->rollBack();
                    throw $error;
                }
            };
        }
    }
    $temporary = sys_get_temp_dir() . "/etsis-moodle-contract-" . bin2hex(random_bytes(5));
    foreach (["user", "login", "lib"] as $folder) {
        mkdir($temporary . "/" . $folder, 0700, true);
        file_put_contents(
            $temporary . "/" . $folder . "/" . ($folder === "lib" ? "authlib.php" : "lib.php"),
            "<?php",
        );
    }
    $CFG = (object) ["dirroot" => $temporary, "libdir" => $temporary . "/lib", "mnet_localhost_id" => 1];
    $DB = new SandboxDb();
    $plugin = dirname(__DIR__, 2) . "/integrations/moodle/local/etsis";
    require $plugin . "/classes/local/instance_identity.php";
    require $plugin . "/classes/local/account_policy.php";
    require $plugin . "/classes/local/account_provisioner.php";
    require $plugin . "/classes/local/account_receipt.php";
    $instance = hash("sha256", $settings["instanceid"]);
    $policy = \local_etsis\local\account_policy::read($instance);
    $data = [
        "instance_hash" => $instance,
        "policy_hash" => $policy["policy_hash"],
        "operation" => "01KAT5HZXS0000000000000001",
        "username" => "alex_example",
        "email" => "fictional@example.invalid",
        "firstname" => "Alex",
        "lastname" => "Example",
        "sign_in" => "password_email",
    ];
    $scenario = $argv[1] ?? "retry";
    $refused = false;
    $same = false;
    $status = "none";
    $receiptBefore = null;
    $receiptAccount = null;
    $receiptSignIn = null;
    $snapshot = static function () use ($DB, &$sent): string {
        return json_encode(
            [
                $DB->pdo->query("SELECT * FROM user ORDER BY id")->fetchAll(PDO::FETCH_ASSOC),
                $DB->pdo->query("SELECT * FROM local_etsis_accounts ORDER BY id")->fetchAll(PDO::FETCH_ASSOC),
                $sent,
            ],
            JSON_THROW_ON_ERROR,
        );
    };
    try {
        if (str_starts_with($scenario, "receipt_")) {
            if ($scenario !== "receipt_missing") {
                \local_etsis\local\account_provisioner::execute($data);
            }
            $hash = hash("sha256", json_encode($data, JSON_THROW_ON_ERROR));
            $settings["provisioning"] = 0;
            $settings["policyrevision"] = "changed-after-creation";
            if ($scenario === "receipt_pending") {
                $DB->set_field("local_etsis_accounts", "signinstatus", "email_pending", []);
            }
            if ($scenario === "receipt_attempting") {
                $DB->set_field("local_etsis_accounts", "signinstatus", "email_attempting", []);
            }
            if ($scenario === "receipt_restricted") {
                $DB->set_field("user", "suspended", 1, []);
            }
            if ($scenario === "receipt_erased") {
                $DB->set_field("local_etsis_accounts", "userid", 0, []);
                $DB->set_field("local_etsis_accounts", "requesthash", str_repeat("0", 64), []);
            }
            if ($scenario === "receipt_siteadmin") {
                $GLOBALS["fictionalSiteAdmin"] = true;
            }
            if ($scenario === "receipt_denied") {
                $authorized = false;
            }
            if ($scenario === "receipt_disabled") {
                $settings["enabled"] = 0;
            }
            if ($scenario === "receipt_hash_changed") {
                $hash = str_repeat("f", 64);
            }
            if ($scenario === "receipt_instance_changed") {
                $instance = str_repeat("f", 64);
            }
            $receiptBefore = $snapshot();
            $observed = \local_etsis\local\account_receipt::read($instance, $data["operation"], $hash);
            $status = $observed["status"];
            $receiptAccount = $observed["user_id"];
            $receiptSignIn = $observed["sign_in_status"];
        } else {
            if ($scenario === "sso") {
                $data["sign_in"] = "sso";
            }
            if ($scenario === "rollback") {
                $DB->failReceipt = true;
            }
            if ($scenario === "denied") {
                $authorized = false;
            }
            if ($scenario === "mail_failure") {
                $sendResult = false;
            }
            $first = \local_etsis\local\account_provisioner::execute($data);
            if ($scenario === "changed") {
                $data["email"] = "changed@example.invalid";
            }
            if ($scenario === "policy") {
                $settings["policyrevision"] = "2";
            }
            if ($scenario === "uncertain_mail") {
                $DB->set_field("local_etsis_accounts", "signinstatus", "email_attempting", []);
            }
            if ($scenario === "erased") {
                $DB->set_field("local_etsis_accounts", "userid", 0, []);
            }
            $second = \local_etsis\local\account_provisioner::execute($data);
            $same = $first === $second;
            $status = $second["sign_in_status"];
        }
    } catch (Throwable) {
        $refused = true;
    }
    $count = static fn(string $table): int => (int) $DB->pdo
        ->query("SELECT COUNT(*) FROM " . $table)
        ->fetchColumn();
    $password = $DB->pdo->query("SELECT password FROM user")->fetchColumn();
    echo json_encode(
        [
            "users" => $count("user"),
            "receipts" => $count("local_etsis_accounts"),
            "sent" => $sent,
            "external_password_updates" => $updates,
            "same" => $same,
            "status" => $status,
            "refused" => $refused,
            "receipt_read_unchanged" => $receiptBefore === null || $receiptBefore === $snapshot(),
            "receipt_account" => $receiptAccount,
            "receipt_sign_in" => $receiptSignIn,
            "sso_password_uncached" => $password === AUTH_PASSWORD_NOT_CACHED,
        ],
        JSON_THROW_ON_ERROR,
    );
    foreach (["user", "login", "lib"] as $folder) {
        unlink($temporary . "/" . $folder . "/" . ($folder === "lib" ? "authlib.php" : "lib.php"));
        rmdir($temporary . "/" . $folder);
    }
    rmdir($temporary);
}
