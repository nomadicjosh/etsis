<?php

declare(strict_types=1);

namespace Infrastructure\Lms;

use Domain\Lms\Exception\MoodleConnectionUnavailableException;
use Domain\Lms\Service\MoodleConnectionProbe;
use Domain\Lms\Service\MoodleRecordReader;
use Domain\Lms\ValueObject\MoodleExternalId;
use Domain\Lms\ValueObject\MoodleRecordCheck;
use Domain\Lms\ValueObject\MoodleResourceKind;
use Domain\Lms\ValueObject\MoodleCapabilities;
use Domain\Lms\ValueObject\MoodleSiteUrl;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use JsonException;
use Qubus\Config\ConfigContainer;
use Qubus\Exception\Data\TypeException;
use Throwable;

/** Isolated client deliberately bypasses framework HTTP debug and observer hooks. */
final readonly class RestMoodleGateway implements MoodleConnectionProbe, MoodleRecordReader
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
    public function inspect(MoodleResourceKind $kind, MoodleExternalId $id): MoodleRecordCheck
    {
        try {
            $settings = $this->settings();
            $capabilities = $this->handshake($settings)->describe();
            $function =
            $kind === MoodleResourceKind::User
            ? "core_user_get_users_by_field"
            : "core_course_get_courses_by_field";
            if (!$capabilities[$function]) {
                throw new MoodleConnectionUnavailableException();
            }
            $this->assertCurrent($settings);
            $parameters =
            $kind === MoodleResourceKind::User
            ? ["field" => "id", "values" => [$id->value]]
            : ["field" => "id", "value" => $id->value];
            $result = $this->request($settings, $function, $parameters);
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
        } catch (Throwable) {
            throw new MoodleConnectionUnavailableException();
        }
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
    ): array {
        $deadline = microtime(true) + 15;
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
            "connect_timeout" => 3,
            "timeout" => 15,
            "read_timeout" => 5,
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
    private function handshake(#[\SensitiveParameter] array $settings): MoodleCapabilities
    {
        $body = $this->request($settings, "core_webservice_get_site_info");
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
