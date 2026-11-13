<?php

declare(strict_types=1);

namespace Infrastructure\Lms;

use Domain\Lms\Exception\MoodleConnectionUnavailableException;
use Domain\Lms\Service\MoodleConnectionProbe as Probe;
use Domain\Lms\Service\MoodleRecordReader as Reader;
use Domain\Lms\Service\MoodleInstanceReader as Identity;
use Domain\Lms\Service\MoodleBoundRecordReader as Bound;
use Domain\Lms\Service\MoodleAccountProvisioner as Provisioner;
use Domain\Lms\Service\MoodleAccountReceiptReader as Receipts;
use Domain\Lms\Service\MoodleSectionGateway as Sections;
use Domain\Lms\ValueObject\MoodleAccountReceipt;
use Domain\Lms\ValueObject\MoodleAccountPolicy;
use Domain\Lms\ValueObject\MoodleAccountDraft;
use Domain\Lms\ValueObject\MoodleProvisionedAccount;
use Domain\Shared\ValueObject\EntityId;
use Domain\Lms\ValueObject\MoodleBinding;
use Domain\Lms\ValueObject\MoodleInstanceIdentity;
use Domain\Lms\ValueObject\MoodleInstallation;
use Domain\Lms\ValueObject\MoodleExternalId;
use Domain\Lms\ValueObject\MoodleRecordCheck;
use Domain\Lms\ValueObject\MoodleResourceKind;
use Domain\Lms\ValueObject\MoodleCapabilities;
use Domain\Lms\ValueObject\MoodleSiteUrl;
use Domain\Lms\ValueObject\MoodleSetupIssue;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use JsonException;
use Qubus\Config\ConfigContainer;
use Qubus\Exception\Data\TypeException;
use Throwable;

/** Isolated client deliberately bypasses framework HTTP debug and observer hooks. */
final readonly class RestMoodleGateway implements Probe, Reader, Identity, Bound, Provisioner, Receipts, Sections
{
    public function __construct(private ConfigContainer $config, private Client $http = new Client())
    {
    }
    /**
     * @throws TypeException
     */
    public function enabled(): bool
    {
        return $this->config->getConfigKey("moodle.enabled", false) === true;
    }
    public function check(): MoodleCapabilities
    {
        try {
            $settings = $this->settings();
            $result = $this->handshake($settings);
            $this->assertCurrent($settings);
            return $result;
        } catch (Throwable) {
            throw new MoodleConnectionUnavailableException();
        }
    }
    public function identify(): MoodleInstallation
    {
        try {
            $settings = $this->settings();
            $capabilities = $this->handshake($settings)->describe();
            if (!$capabilities["local_etsis_get_instance_info"]) {
                throw new MoodleConnectionUnavailableException(MoodleSetupIssue::FunctionUnavailable);
            }
            $this->assertCurrent($settings);
            $installation = $this->installation($settings);
            $this->assertCurrent($settings);
            return $installation;
        } catch (MoodleConnectionUnavailableException $error) {
            throw new MoodleConnectionUnavailableException($error->setupIssue);
        } catch (Throwable) {
            throw new MoodleConnectionUnavailableException();
        }
    }
    public function inspect(MoodleResourceKind $kind, MoodleExternalId $id): MoodleRecordCheck
    {
        try {
            $settings = $this->settings();
            $capabilities = $this->handshake($settings)->describe();
            return $this->lookup($settings, $capabilities, $kind, $id);
        } catch (Throwable) {
            throw new MoodleConnectionUnavailableException();
        }
    }

    public function inspectBound(
        MoodleBinding $binding,
        MoodleResourceKind $kind,
        MoodleExternalId $id,
    ): MoodleRecordCheck {
        try {
            $deadline = microtime(true) + 30;
            $settings = $this->settings();
            $capabilities = $this->handshake($settings, $deadline)->describe();
            if (!$capabilities["local_etsis_get_instance_info"]) {
                throw new MoodleConnectionUnavailableException(MoodleSetupIssue::FunctionUnavailable);
            }
            $this->assertCurrent($settings);
            $before = $this->installation($settings, $deadline);
            if (!$binding->matches($before)) {
                throw new MoodleConnectionUnavailableException(MoodleSetupIssue::InstallationMismatch);
            }
            $record = $this->lookup($settings, $capabilities, $kind, $id, $deadline);
            $after = $this->installation($settings, $deadline);
            if (!$binding->matches($after)) {
                throw new MoodleConnectionUnavailableException(MoodleSetupIssue::InstallationMismatch);
            }
            $this->assertCurrent($settings);
            return $record;
        } catch (MoodleConnectionUnavailableException $error) {
            throw new MoodleConnectionUnavailableException($error->setupIssue);
        } catch (Throwable) {
            throw new MoodleConnectionUnavailableException();
        }
    }

    public function policy(MoodleBinding $binding): MoodleAccountPolicy
    {
        try {
            $settings = $this->settings();
            $deadline = microtime(true) + 30;
            $this->handshake($settings, $deadline);
            if (!hash_equals($binding->siteFingerprint, hash("sha256", $settings["site"]->value))) {
                throw new MoodleConnectionUnavailableException();
            }
            $result = $this->request(
                $settings,
                "local_etsis_get_account_policy",
                ["instance_hash" => $binding->instanceFingerprint],
                $deadline,
            );
            $this->assertCurrent($settings);
            if (
                count($result) !== 5 ||
                ($result["protocol"] ?? null) !== 1 ||
                ($result["instance_hash"] ?? null) !== $binding->instanceFingerprint ||
                !is_string($result["policy_hash"] ?? null) ||
                !is_bool($result["sso"] ?? null) ||
                !is_bool($result["password_email"] ?? null)
            ) {
                throw new MoodleConnectionUnavailableException();
            }
            return new MoodleAccountPolicy($result["policy_hash"], $result["sso"], $result["password_email"]);
        } catch (Throwable) {
            throw new MoodleConnectionUnavailableException();
        }
    }
    public function syncCategory(MoodleBinding $binding, array $category): array
    {
        try {
            if ($this->config->getConfigKey("moodle.section_sync_enabled", false) !== true) {
                throw new MoodleConnectionUnavailableException();
            }
            $settings = $this->settings();
            $deadline = microtime(true) + 15;
            $this->handshake($settings, $deadline);
            $this->assertCurrent($settings);
            if (
                !hash_equals($binding->siteFingerprint, hash("sha256", $settings["site"]->value)) ||
                $this->config->getConfigKey("moodle.section_sync_enabled", false) !== true
            ) {
                throw new MoodleConnectionUnavailableException();
            }
            $r = $this->request(
                $settings,
                "local_etsis_sync_category",
                [
                    "instance_hash" => $binding->instanceFingerprint,
                    "payload" => json_encode($category, JSON_THROW_ON_ERROR),
                ],
                $deadline,
            );
            $this->assertCurrent($settings);
            if (
                $this->config->getConfigKey("moodle.section_sync_enabled", false) !== true ||
                count($r) !== 7 ||
                ($r["protocol"] ?? null) !== 1 ||
                ($r["instance_hash"] ?? null) !== $binding->instanceFingerprint ||
                ($r["reference_id"] ?? null) !== $category["reference_id"] ||
                ($r["version"] ?? null) !== $category["version"] ||
                !is_string($r["category_id"] ?? null) ||
                !is_bool($r["owned"] ?? null) ||
                !in_array($r["status"] ?? null, ["synchronized", "unchanged", "superseded"], true)
            ) {
                throw new MoodleConnectionUnavailableException();
            }
            return [
                "id" => new \Domain\Lms\ValueObject\MoodleExternalId($r["category_id"])->value,
                "owned" => $r["owned"],
                "status" => $r["status"],
            ];
        } catch (Throwable) {
            throw new MoodleConnectionUnavailableException();
        }
    }
    public function provisionCourse(
        MoodleBinding $binding,
        EntityId $operation,
        array $course,
    ): \Domain\Lms\ValueObject\MoodleExternalId {
        try {
            if ($this->config->getConfigKey("moodle.course_creation_enabled", false) !== true) {
                throw new MoodleConnectionUnavailableException();
            }
            $settings = $this->settings();
            $deadline = microtime(true) + 15;
            $this->handshake($settings, $deadline);
            if (!hash_equals($binding->siteFingerprint, hash("sha256", $settings["site"]->value))) {
                throw new MoodleConnectionUnavailableException();
            }
            $this->assertCurrent($settings);
            if ($this->config->getConfigKey("moodle.course_creation_enabled", false) !== true) {
                throw new MoodleConnectionUnavailableException();
            }
            $result = $this->request(
                $settings,
                "local_etsis_provision_course",
                [
                    "instance_hash" => $binding->instanceFingerprint,
                    "operation" => $operation->value,
                    "payload" => json_encode($course, JSON_THROW_ON_ERROR),
                ],
                $deadline,
            );
            $this->assertCurrent($settings);
            if (
                $this->config->getConfigKey("moodle.course_creation_enabled", false) !== true ||
                count($result) !== 4 ||
                ($result["protocol"] ?? null) !== 1 ||
                ($result["instance_hash"] ?? null) !== $binding->instanceFingerprint ||
                ($result["operation"] ?? null) !== $operation->value ||
                !is_string($result["course_id"] ?? null)
            ) {
                throw new MoodleConnectionUnavailableException();
            }
            return new \Domain\Lms\ValueObject\MoodleExternalId($result["course_id"]);
        } catch (Throwable) {
            throw new MoodleConnectionUnavailableException();
        }
    }
    public function rosterPolicy(MoodleBinding $binding): array
    {
        try {
            $settings = $this->settings();
            $deadline = microtime(true) + 10;
            $this->handshake($settings, $deadline);
            if (!hash_equals($binding->siteFingerprint, hash("sha256", $settings["site"]->value))) {
                throw new MoodleConnectionUnavailableException();
            }
            $result = $this->request(
                $settings,
                "local_etsis_get_roster_policy",
                ["instance_hash" => $binding->instanceFingerprint],
                $deadline,
            );
            $this->assertCurrent($settings);
            if (
                count($result) !== 3 ||
                ($result["protocol"] ?? null) !== 1 ||
                ($result["instance_hash"] ?? null) !== $binding->instanceFingerprint ||
                !is_string($result["policy_hash"] ?? null) ||
                preg_match('/^[a-f0-9]{64}$/D', $result["policy_hash"]) !== 1
            ) {
                throw new MoodleConnectionUnavailableException();
            }
            return ["policy_hash" => $result["policy_hash"]];
        } catch (Throwable) {
            throw new MoodleConnectionUnavailableException();
        }
    }
    public function syncMember(
        MoodleBinding $binding,
        string $policyHash,
        #[\SensitiveParameter] array $member,
    ): string {
        try {
            if ($this->config->getConfigKey("moodle.section_sync_enabled", false) !== true) {
                throw new MoodleConnectionUnavailableException();
            }
            $settings = $this->settings();
            if (!hash_equals($binding->siteFingerprint, hash("sha256", $settings["site"]->value))) {
                throw new MoodleConnectionUnavailableException();
            }
            $result = $this->request(
                $settings,
                "local_etsis_sync_roster_member",
                [
                    "instance_hash" => $binding->instanceFingerprint,
                    "policy_hash" => $policyHash,
                    "payload" => json_encode($member, JSON_THROW_ON_ERROR),
                ],
                microtime(true) + 10,
            );
            $this->assertCurrent($settings);
            if (
                $this->config->getConfigKey("moodle.section_sync_enabled", false) !== true ||
                count($result) !== 5 ||
                ($result["protocol"] ?? null) !== 1 ||
                ($result["instance_hash"] ?? null) !== $binding->instanceFingerprint ||
                ($result["section"] ?? null) !== $member["section"] ||
                ($result["version"] ?? null) !== $member["version"] ||
                !in_array(
                    $result["status"] ?? null,
                    ["synchronized", "unchanged", "superseded", "externally_managed"],
                    true,
                )
            ) {
                throw new MoodleConnectionUnavailableException();
            }
            return $result["status"];
        } catch (Throwable) {
            throw new MoodleConnectionUnavailableException();
        }
    }

    public function receipt(
        MoodleBinding $binding,
        EntityId $operation,
        string $requestHash,
    ): MoodleAccountReceipt {
        try {
            if (preg_match('/^[a-f0-9]{64}$/D', $requestHash) !== 1) {
                throw new MoodleConnectionUnavailableException();
            }
            $settings = $this->settings();
            $deadline = microtime(true) + 30;
            $this->handshake($settings, $deadline);
            if (!hash_equals($binding->siteFingerprint, hash("sha256", $settings["site"]->value))) {
                throw new MoodleConnectionUnavailableException();
            }
            $this->assertCurrent($settings);
            $result = $this->request(
                $settings,
                "local_etsis_get_account_receipt",
                [
                    "instance_hash" => $binding->instanceFingerprint,
                    "operation" => $operation->value,
                    "request_hash" => $requestHash,
                ],
                $deadline,
            );
            $this->assertCurrent($settings);
            if (
                count($result) !== 7 ||
                ($result["protocol"] ?? null) !== 1 ||
                ($result["instance_hash"] ?? null) !== $binding->instanceFingerprint ||
                ($result["operation"] ?? null) !== $operation->value ||
                ($result["request_hash"] ?? null) !== $requestHash ||
                !is_string($result["status"] ?? null) ||
                !is_string($result["user_id"] ?? null) ||
                !is_string($result["sign_in_status"] ?? null)
            ) {
                throw new MoodleConnectionUnavailableException();
            }
            if ($result["status"] !== "recorded") {
                if ($result["user_id"] !== "0" || $result["sign_in_status"] !== "unknown") {
                    throw new MoodleConnectionUnavailableException();
                }
                return new MoodleAccountReceipt($result["status"], null);
            }
            return new MoodleAccountReceipt(
                "recorded",
                new MoodleProvisionedAccount(
                    new MoodleExternalId($result["user_id"]),
                    $result["sign_in_status"],
                ),
            );
        } catch (Throwable) {
            throw new MoodleConnectionUnavailableException();
        }
    }
    public function provision(
        MoodleBinding $binding,
        EntityId $operation,
        string $policyHash,
        #[\SensitiveParameter] MoodleAccountDraft $draft,
        #[\SensitiveParameter] string $firstName,
        #[\SensitiveParameter] string $lastName,
    ): MoodleProvisionedAccount {
        try {
            if ($this->config->getConfigKey("moodle.provisioning_enabled", false) !== true) {
                throw new MoodleConnectionUnavailableException();
            }
            $settings = $this->settings();
            $deadline = microtime(true) + 30;
            $this->handshake($settings, $deadline);
            if (!hash_equals($binding->siteFingerprint, hash("sha256", $settings["site"]->value))) {
                throw new MoodleConnectionUnavailableException();
            }
            $this->assertCurrent($settings);
            if ($this->config->getConfigKey("moodle.provisioning_enabled", false) !== true) {
                throw new MoodleConnectionUnavailableException();
            }
            $result = $this->request(
                $settings,
                "local_etsis_provision_account",
                [
                    "instance_hash" => $binding->instanceFingerprint,
                    "policy_hash" => $policyHash,
                    "operation" => $operation->value,
                    "username" => $draft->username,
                    "email" => $draft->email,
                    "firstname" => $firstName,
                    "lastname" => $lastName,
                    "sign_in" => $draft->signIn,
                ],
                $deadline,
            );
            $this->assertCurrent($settings);
            if (
                count($result) !== 5 ||
                ($result["protocol"] ?? null) !== 1 ||
                ($result["instance_hash"] ?? null) !== $binding->instanceFingerprint ||
                ($result["operation"] ?? null) !== $operation->value ||
                !is_string($result["user_id"] ?? null) ||
                !is_string($result["sign_in_status"] ?? null)
            ) {
                throw new MoodleConnectionUnavailableException();
            }
            if (($draft->signIn === "sso") !== ($result["sign_in_status"] === "sso_ready")) {
                throw new MoodleConnectionUnavailableException();
            }
            return new MoodleProvisionedAccount(
                new MoodleExternalId($result["user_id"]),
                $result["sign_in_status"],
            );
        } catch (Throwable) {
            throw new MoodleConnectionUnavailableException();
        }
    }

    /** @param array{site:MoodleSiteUrl,token:string,verify:true|string} $settings
     * @phpstan-impure Reads the remote installation again; never memoize across a record lookup.
     */
    private function installation(
        #[\SensitiveParameter] array $settings,
        ?float $deadline = null,
    ): MoodleInstallation {
        $result = $this->request($settings, "local_etsis_get_instance_info", operationDeadline: $deadline);
        if (
            count($result) !== 2 ||
            ($result["protocol"] ?? null) !== 1 ||
            !is_string($result["instance_id"] ?? null)
        ) {
            throw new MoodleConnectionUnavailableException();
        }
        return new MoodleInstallation($settings["site"], new MoodleInstanceIdentity($result["instance_id"]));
    }

    /** @param array{site:MoodleSiteUrl,token:string,verify:true|string} $settings
     * @param array<string,bool> $capabilities
     */
    private function lookup(
        #[\SensitiveParameter] array $settings,
        array $capabilities,
        MoodleResourceKind $kind,
        MoodleExternalId $id,
        ?float $deadline = null,
    ): MoodleRecordCheck {
        if ($kind === MoodleResourceKind::User) {
            $function = "core_user_get_users_by_field";
            $parameters = ["field" => "id", "values" => [$id->value]];
        } else {
            $function = "core_course_get_courses_by_field";
            $parameters = ["field" => "id", "value" => $id->value];
        }
        if (!$capabilities[$function]) {
            throw new MoodleConnectionUnavailableException(MoodleSetupIssue::LookupUnavailable);
        }
        $this->assertCurrent($settings);
        $result = $this->request($settings, $function, $parameters, $deadline);
        $records = $kind === MoodleResourceKind::User ? $result : $result["courses"] ?? null;
        if (
            $kind === MoodleResourceKind::Course &&
            (!is_array($result["warnings"] ?? null) || $result["warnings"] !== [])
        ) {
            throw new MoodleConnectionUnavailableException();
        }
        if (!is_array($records) || !array_is_list($records) || count($records) > 1) {
            throw new MoodleConnectionUnavailableException();
        }
        $this->assertCurrent($settings);
        if ($records === []) {
            return new MoodleRecordCheck($kind, false);
        }
        $record = $records[0];
        if (!is_array($record)) {
            throw new MoodleConnectionUnavailableException();
        }
        $returnedId = $record["id"] ?? null;
        if (
            (!is_int($returnedId) && !is_string($returnedId)) ||
            new MoodleExternalId((string) $returnedId)->value !== $id->value
        ) {
            throw new MoodleConnectionUnavailableException();
        }
        $flags = $kind === MoodleResourceKind::User ? ["suspended", "deleted"] : ["visible"];
        $status = [];
        foreach ($flags as $flag) {
            $value = $record[$flag] ?? null;
            if ($value !== null && !in_array($value, [true, false, 0, 1], true)) {
                throw new MoodleConnectionUnavailableException();
            }
            $status[$flag] = $value === null ? null : (bool) $value;
        }
        return new MoodleRecordCheck($kind, true, $status);
    }
    /**
     * @return array{site:MoodleSiteUrl,token:string,verify:true|string}
     * @throws TypeException
     */
    private function settings(): array
    {
        if (!$this->enabled()) {
            throw new MoodleConnectionUnavailableException();
        }
        $base = $this->config->getConfigKey("moodle.url", "");
        $token = $this->config->getConfigKey("moodle.token", "");
        $ca = $this->config->getConfigKey("moodle.ca_file", "");
        if (
            !is_string($base) ||
            preg_match('/[\x00-\x20\x7F]/', $base) ||
            !is_string($token) ||
            $token === "" ||
            strlen($token) > 4096 ||
            preg_match('/[\x00-\x20\x7F]/', $token) ||
            !is_string($ca)
        ) {
            throw new MoodleConnectionUnavailableException();
        }
        $site = new MoodleSiteUrl($base);
        $verify = true;
        if ($ca !== "") {
            $verify = realpath($ca);
            if ($verify === false || !is_file($verify) || !is_readable($verify)) {
                throw new MoodleConnectionUnavailableException();
            }
        }
        return ["site" => $site, "token" => $token, "verify" => $verify];
    }
    /**
     * @param array{site:MoodleSiteUrl,token:string,verify:true|string} $settings
     * @throws TypeException
     */
    private function assertCurrent(#[\SensitiveParameter] array $settings): void
    {
        $current = $this->settings();
        if (
            !$current["site"]->matches($settings["site"]) ||
            !hash_equals($settings["token"], $current["token"]) ||
            $settings["verify"] !== $current["verify"]
        ) {
            throw new MoodleConnectionUnavailableException();
        }
    }

    /** @param array{site:MoodleSiteUrl,token:string,verify:true|string} $settings
     * @param array<string,mixed> $parameters
     * @return array<mixed>
     * @throws JsonException
     * @throws GuzzleException
     */
    private function request(
        #[\SensitiveParameter] array $settings,
        string $function,
        array $parameters = [],
        ?float $operationDeadline = null,
    ): array {
        $timeout = $operationDeadline === null ? 15 : min(15, $operationDeadline - microtime(true));
        if ($timeout <= 0) {
            throw new MoodleConnectionUnavailableException();
        }
        $deadline = microtime(true) + $timeout;
        $response = $this->http->request("POST", $settings["site"]->value . "/webservice/rest/server.php", [
            "headers" => ["Accept" => "application/json"],
            "form_params" =>
                [
                    "wstoken" => $settings["token"],
                    "wsfunction" => $function,
                    "moodlewsrestformat" => "json",
                ] + $parameters,
            "verify" => $settings["verify"],
            "allow_redirects" => false,
            "http_errors" => false,
            "debug" => false,
            "cookies" => false,
            "stream" => true,
            "connect_timeout" => min(3, $timeout),
            "timeout" => $timeout,
            "read_timeout" => min(5, $timeout),
        ]);
        $stream = $response->getBody();
        try {
            if ($response->getStatusCode() !== 200) {
                throw new MoodleConnectionUnavailableException();
            }
            $raw = "";
            while (!$stream->eof()) {
                if (microtime(true) >= $deadline) {
                    throw new MoodleConnectionUnavailableException();
                }
                $chunk = $stream->read(8192);
                if ($chunk === "" || strlen($raw) + strlen($chunk) > 524288) {
                    throw new MoodleConnectionUnavailableException();
                }
                $raw .= $chunk;
            }
        } finally {
            $stream->close();
        }
        $body = json_decode($raw, true, 16, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        if (is_array($body) && $function === "local_etsis_get_instance_info") {
            $issue = match ($body["errorcode"] ?? null) {
                "integrationdisabled" => MoodleSetupIssue::VerificationDisabled,
                "identityunavailable" => MoodleSetupIssue::IdentityUnavailable,
                "nopermissions", "accesscontrol" => MoodleSetupIssue::PermissionDenied,
                default => null,
            };
            if ($issue !== null) {
                throw new MoodleConnectionUnavailableException($issue);
            }
        }
        if (!is_array($body) || isset($body["exception"]) || isset($body["errorcode"])) {
            throw new MoodleConnectionUnavailableException();
        }
        return $body;
    }
    /**
     * @param array{site:MoodleSiteUrl,token:string,verify:true|string} $settings
     * @throws JsonException
     * @throws GuzzleException
     */
    private function handshake(
        #[\SensitiveParameter] array $settings,
        ?float $deadline = null,
    ): MoodleCapabilities {
        $body = $this->request($settings, "core_webservice_get_site_info", operationDeadline: $deadline);
        if (
            !is_array($body["functions"] ?? null) ||
            !array_is_list($body["functions"]) ||
            count($body["functions"]) > 2000
        ) {
            throw new MoodleConnectionUnavailableException();
        }
        if (
            !is_string($body["siteurl"] ?? null) ||
            !$settings["site"]->matches(new MoodleSiteUrl($body["siteurl"]))
        ) {
            throw new MoodleConnectionUnavailableException();
        }
        $names = [];
        foreach ($body["functions"] as $function) {
            $name = $function["name"] ?? null;
            if (!is_string($name) || strlen($name) > 191 || preg_match('/^[a-z][a-z0-9_]*$/D', $name) !== 1) {
                throw new MoodleConnectionUnavailableException();
            }
            // Keep only fixed diagnostic names; discard profile fields and all other function names.
            if (in_array($name, MoodleCapabilities::FUNCTIONS, true)) {
                $names[] = $name;
            }
        }
        if (!in_array("core_webservice_get_site_info", $names, true)) {
            throw new MoodleConnectionUnavailableException();
        }
        return new MoodleCapabilities($names);
    }
    /** @return array<string,string> */
    public function __debugInfo(): array
    {
        return ["connection" => "[private Moodle configuration]"];
    }
}
