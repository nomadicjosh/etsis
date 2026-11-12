<?php

declare(strict_types=1);

namespace Infrastructure\Lms;

use Application\Http\Security\MoodleSessionChannel;
use Domain\Lms\Service\{MoodleLaunchService, MoodleLaunchPolicy};
use Domain\Shared\Service\Clock;
use Domain\Shared\ValueObject\EntityId;
use Domain\Shared\Exception\AccessDeniedException;
use Laminas\Diactoros\Response\TextResponse;
use Psr\Http\Message\{ServerRequestInterface, ResponseInterface};
use Qubus\Config\ConfigContainer;

/** Dedicated machine authentication; never a browser CSRF exemption on other paths. */
final readonly class SignedMoodleSessionChannel implements MoodleSessionChannel
{
    public function __construct(
        private MoodleLaunchService $launches,
        private MoodleLaunchPolicy $policy,
        private ConfigContainer $config,
        private Clock $clock,
    ) {
    }
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $headers = ["Cache-Control" => "private, no-store, max-age=0", "X-Content-Type-Options" => "nosniff"];
        $time = $request->getHeaderLine("X-Etsis-Time");
        $nonce = $request->getHeaderLine("X-Etsis-Nonce");
        $signature = $request->getHeaderLine("X-Etsis-Signature");
        $secret = $this->config->string("moodle.launch_secret", "");
        if (
            !$this->policy->enabled() ||
            $request->getMethod() !== "POST" ||
            $request->getUri()->getQuery() !== "" ||
            strtolower(trim(explode(";", $request->getHeaderLine("Content-Type"))[0])) !==
                "application/json" ||
            preg_match('/^[0-9]{10}$/D', $time) !== 1 ||
            abs($this->clock->now()->getTimestamp() - (int) $time) > 30 ||
            preg_match('/^[a-f0-9]{64}$/D', $nonce) !== 1 ||
            preg_match('/^[a-f0-9]{64}$/D', $signature) !== 1 ||
            preg_match('/^[a-f0-9]{64}$/D', $secret) !== 1
        ) {
            return new TextResponse("Unavailable.", 403, $headers);
        }
        $raw = $request->getBody()->read(4097);
        $key = hex2bin($secret);
        if (
            $key === false ||
            strlen($raw) > 4096 ||
            !hash_equals(
                hash_hmac("sha256", "etsis-moodle/v1\nrequest\n$time\n$nonce\n$raw", $key),
                $signature,
            )
        ) {
            return new TextResponse("Unavailable.", 403, $headers);
        }
        $status = 200;
        try {
            $data = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
            if (
                !is_array($data) ||
                !is_string($data["instance_hash"] ?? null) ||
                preg_match('/^[a-f0-9]{64}$/D', $data["instance_hash"]) !== 1
            ) {
                throw new \InvalidArgumentException();
            }
            if (
                ($data["op"] ?? null) === "exchange" &&
                count($data) === 3 &&
                is_string($data["ticket"] ?? null)
            ) {
                $launch = $this->launches->exchange($data["ticket"], $data["instance_hash"]);
            } elseif (
                ($data["op"] ?? null) === "check" &&
                count($data) === 4 &&
                is_string($data["grant_id"] ?? null) &&
                is_string($data["moodle_user_id"] ?? null)
            ) {
                $launch = $this->launches->check(
                    new EntityId($data["grant_id"]),
                    $data["moodle_user_id"],
                    $data["instance_hash"],
                );
            } else {
                throw new \InvalidArgumentException();
            }
            $result =
            $launch === null
            ? ["active" => false]
            : [
                        "active" => true,
                        "grant_id" => $launch->id->value,
                        "moodle_user_id" => $launch->external->value,
                        "expires_at" => $launch->sessionExpiresAt,
                    ];
        } catch (AccessDeniedException | \InvalidArgumentException | \JsonException) {
            $result = ["active" => false];
        } catch (\Throwable) {
            // Never log request bodies, upstream identities, credentials or exception context here.
            $result = ["active" => false];
            $status = 503;
        }
        $body = json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        return new TextResponse(
            $body,
            $status,
            $headers + [
                "Content-Type" => "application/json",
                "X-Etsis-Signature" => hash_hmac(
                    "sha256",
                    "etsis-moodle/v1\nresponse\n$time\n$nonce\n$body",
                    $key,
                ),
            ],
        );
    }
}
