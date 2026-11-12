<?php

declare(strict_types=1);

namespace Infrastructure\Lms;

use Domain\Lms\Exception\MoodleConnectionUnavailableException;
use Domain\Lms\Service\MoodleConnectionProbe;
use Domain\Lms\ValueObject\MoodleCapabilities;
use GuzzleHttp\Client;
use Qubus\Config\ConfigContainer;
use Throwable;

/** Isolated client deliberately bypasses framework HTTP debug and observer hooks. */
final readonly class RestMoodleConnectionProbe implements MoodleConnectionProbe
{
    public function __construct(private ConfigContainer $config, private Client $http = new Client())
    {
    }
    public function enabled(): bool
    {
        return $this->config->getConfigKey("moodle.enabled", false) === true;
    }
    public function check(): MoodleCapabilities
    {
        try {
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
            $url = parse_url($base);
            if (
                $url === false ||
                ($url["scheme"] ?? "") !== "https" ||
                empty($url["host"]) ||
                isset($url["user"]) ||
                isset($url["pass"]) ||
                isset($url["query"]) ||
                isset($url["fragment"])
            ) {
                throw new MoodleConnectionUnavailableException();
            }
            $verify = true;
            if ($ca !== "") {
                $verify = realpath($ca);
                if ($verify === false || !is_file($verify) || !is_readable($verify)) {
                    throw new MoodleConnectionUnavailableException();
                }
            }
            $deadline = microtime(true) + 15;
            $response = $this->http->request("POST", rtrim($base, "/") . "/webservice/rest/server.php", [
                "headers" => ["Accept" => "application/json"],
                "form_params" => [
                    "wstoken" => $token,
                    "wsfunction" => "core_webservice_get_site_info",
                    "moodlewsrestformat" => "json",
                ],
                "verify" => $verify,
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
            $body = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
            if (
                !is_array($body) ||
                isset($body["exception"]) ||
                isset($body["errorcode"]) ||
                !is_array($body["functions"] ?? null) ||
                !array_is_list($body["functions"]) ||
                count($body["functions"]) > 2000
            ) {
                throw new MoodleConnectionUnavailableException();
            }
            $names = [];
            foreach ($body["functions"] as $function) {
                $name = $function["name"] ?? null;
                if (
                    !is_string($name) ||
                    strlen($name) > 191 ||
                    preg_match('/^[a-z][a-z0-9_]*$/D', $name) !== 1
                ) {
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
        } catch (Throwable) {
            // No upstream message, body, token or previous exception is retained.
            throw new MoodleConnectionUnavailableException();
        }
    }
    /** @return array<string,string> */
    public function __debugInfo(): array
    {
        return ["connection" => "[private Moodle configuration]"];
    }
}
