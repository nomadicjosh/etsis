<?php
declare(strict_types=1);
namespace core\hook {
    class after_config {}
}
namespace core\session {
    class manager
    {
        public static function terminate_current(): void
        {
            $GLOBALS["terminated"]++;
        }
    }
}
namespace {
    define("MOODLE_INTERNAL", true);
    define("CLI_SCRIPT", false);
    class moodle_exception extends RuntimeException
    {
        public function __construct(public string $errorcode, string $component)
        {
            parent::__construct($errorcode);
        }
    }
    $settings = [
        "enabled" => 1,
        "launch" => 1,
        "sisurl" => "https://campus.example.test",
        "instanceid" => str_repeat("a", 32),
    ];
    $temporary = sys_get_temp_dir() . "/etsis-moodle-launch-" . bin2hex(random_bytes(8));
    mkdir($temporary);
    file_put_contents($temporary . "/authlib.php", "<?php");
    $CFG = (object) ["local_etsis_launch_secret" => str_repeat("c", 64), "libdir" => $temporary];
    $USER = (object) ["id" => 0];
    $SESSION = new stdClass();
    $terminated = 0;
    $logins = 0;
    $calls = 0;
    $scenario = $argv[1];
    $user = (object) ["id" => 123, "confirmed" => 1, "suspended" => 0, "deleted" => 0, "auth" => "manual"];
    if ($scenario === "suspended") {
        $user->suspended = 1;
    }
    if ($scenario === "switch") {
        $USER->id = 456;
    }
    function get_config(string $plugin, string $key): mixed
    {
        return $GLOBALS["settings"][$key] ?? false;
    }
    function get_complete_user_data(string $field, int $id): object
    {
        return $GLOBALS["user"];
    }
    function isloggedin(): bool
    {
        return $GLOBALS["USER"]->id > 0;
    }
    function isguestuser(?object $user = null): bool
    {
        return false;
    }
    function is_siteadmin(object $user): bool
    {
        return $GLOBALS["scenario"] === "admin";
    }
    function is_enabled_auth(string $auth): bool
    {
        return $GLOBALS["scenario"] !== "disabled_auth";
    }
    function complete_user_login(object $user): void
    {
        $GLOBALS["USER"] = $user;
        $GLOBALS["logins"]++;
        if ($GLOBALS["scenario"] === "login_hook_error") {
            throw new RuntimeException("fictional hook failure");
        }
    }
    $root = dirname(__DIR__, 2) . "/integrations/moodle/local/etsis";
    require $root . "/classes/local/instance_identity.php";
    require $root . "/classes/local/session_channel.php";
    require $root . "/classes/local/linked_session.php";

    if (in_array($scenario, ["admin_error_page", "generic_error_page"], true)) {
        require $root . "/classes/local/launch_error.php";
        require $root . "/lang/en/local_etsis.php";
        function get_string(string $code, string $component): string
        {
            return $GLOBALS["string"][$code];
        }
        function s(string $value): string
        {
            return htmlspecialchars($value, ENT_QUOTES, "UTF-8");
        }
        class moodle_url
        {
            public function __construct(private string $value) {}
            public function __toString(): string
            {
                return $this->value;
            }
        }
        class context_system
        {
            public static function instance(): object
            {
                return new stdClass();
            }
        }
        class html_writer
        {
            public static function tag(string $tag, string $body): string
            {
                return "<$tag>$body</$tag>";
            }
            public static function link(moodle_url $url, string $label): string
            {
                return '<a href="' . s((string) $url) . '">' . s($label) . "</a>";
            }
        }
        $PAGE = new class {
            public function __call(string $name, array $args): void {}
        };
        $OUTPUT = new class {
            public function header(): string
            {
                return "<main>";
            }
            public function footer(): string
            {
                return "</main>";
            }
            public function heading(string $title): string
            {
                return "<h1>" . s($title) . "</h1>";
            }
            public function single_button(moodle_url $url, string $label, string $method): string
            {
                return '<form action="' .
                    s((string) $url) .
                    '" method="' .
                    s($method) .
                    '"><button>' .
                    s($label) .
                    "</button></form>";
            }
        };
        ob_start();
        \local_etsis\local\launch_error::display(
            $scenario === "admin_error_page"
                ? "launchsiteadmin"
                : "<script>fictional-private-payload</script>",
        );
        $html = ob_get_clean();
        echo json_encode(
            [
                "status" => http_response_code(),
                "has_admin_reason" => str_contains($html, "site administrator"),
                "has_local_signin" => str_contains($html, "/login/index.php"),
                "has_campus_return" => str_contains($html, "https://campus.example.test/portal/moodle/"),
                "has_dead_docs_link" => str_contains($html, "docs.moodle.org"),
                "has_private_payload" => str_contains($html, "fictional-private-payload"),
            ],
            JSON_THROW_ON_ERROR,
        );
        unlink($temporary . "/authlib.php");
        rmdir($temporary);
        exit();
    }
    $grant = [
        "active" => true,
        "grant_id" => "01KAWQ0DWGP8PYVM92HQ7Y43GB",
        "moodle_user_id" => "123",
        "expires_at" => time() + 300,
    ];
    $transport = static function ($url, $body, $headers) use (&$calls, $grant, $scenario): array {
        $calls++;
        $values = [];
        foreach ($headers as $header) {
            [$key, $value] = explode(": ", $header, 2);
            $values[$key] = $value;
        }
        if (
            $url !== "https://campus.example.test/sis/moodle/session-channel/" ||
            !hash_equals(
                hash_hmac(
                    "sha256",
                    "etsis-moodle/v1\nrequest\n" .
                        $values["X-Etsis-Time"] .
                        "\n" .
                        $values["X-Etsis-Nonce"] .
                        "\n" .
                        $body,
                    hex2bin(str_repeat("c", 64)),
                ),
                $values["X-Etsis-Signature"],
            )
        ) {
            throw new RuntimeException("Invalid test channel request.");
        }
        if ($scenario === "outage") {
            throw new RuntimeException("fictional outage");
        }
        $response = $grant;
        if ($scenario === "revoked") {
            $response = ["active" => false];
        }
        if ($scenario === "wrong_user") {
            $response["moodle_user_id"] = "456";
        }
        if ($scenario === "wrong_grant") {
            $response["grant_id"] = "01KAWQ0DWGP8PYVM92HQ7Y43GC";
        }
        if ($scenario === "extended") {
            $response["expires_at"] += 60;
        }
        $reply = json_encode($response, JSON_THROW_ON_ERROR);
        $signature = hash_hmac(
            "sha256",
            "etsis-moodle/v1\nresponse\n" .
                $values["X-Etsis-Time"] .
                "\n" .
                $values["X-Etsis-Nonce"] .
                "\n" .
                $reply,
            hex2bin(str_repeat("c", 64)),
        );
        if ($scenario === "tampered") {
            $reply .= " ";
        }
        if ($scenario === "oversize") {
            $reply .= str_repeat(" ", 4097);
        }
        return [200, $reply, $signature];
    };
    $channel = new \local_etsis\local\session_channel($transport);
    $denied = false;
    $errorcode = null;
    try {
        if (
            in_array(
                $scenario,
                [
                    "valid",
                    "origin",
                    "missing_origin",
                    "admin",
                    "suspended",
                    "disabled_auth",
                    "switch",
                    "login_hook_error",
                ],
                true,
            )
        ) {
            $origin =
                $scenario === "origin"
                    ? "https://attacker.example.test"
                    : ($scenario === "missing_origin"
                        ? ""
                        : "https://campus.example.test");
            \local_etsis\local\linked_session::launch(str_repeat("e", 64), $origin, $channel);
        } elseif (in_array($scenario, ["hook_expired", "hook_unmarked"], true)) {
            $USER->id = 123;
            if ($scenario === "hook_expired") {
                $SESSION->local_etsis_launch = $grant;
                $SESSION->local_etsis_launch["expires_at"] = time() - 1;
            }
            \local_etsis\local\linked_session::after_config(new \core\hook\after_config());
        } else {
            $marker = $grant;
            if ($scenario === "expired") {
                $marker["expires_at"] = time() - 1;
            }
            if ($scenario === "malformed") {
                unset($marker["grant_id"]);
            }
            \local_etsis\local\linked_session::validate($marker, 123, $channel);
        }
    } catch (moodle_exception $error) {
        $denied = true;
        $errorcode = $error->errorcode;
    }
    echo json_encode(
        [
            "denied" => $denied,
            "errorcode" => $errorcode,
            "calls" => $calls,
            "logins" => $logins,
            "terminated" => $terminated,
            "marker" => isset($SESSION->local_etsis_launch),
        ],
        JSON_THROW_ON_ERROR,
    );
    unlink($temporary . "/authlib.php");
    rmdir($temporary);
}
