<?php

declare(strict_types=1);

namespace Tests\Unit;

use Application\Http\Middleware\WorkspaceJsonBodyMiddleware;
use InvalidArgumentException;
use Laminas\Diactoros\Response\JsonResponse;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Qubus\Http\ServerRequest;
use Tests\Support\RecordedRequestBody;

final class WorkspaceJsonBodyMemoryTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__) . '/Support/RecordedRequestBody.php';
    }
    private function body(string $bytes): RecordedRequestBody
    {
        $body = new RecordedRequestBody("php://temp", "w+");
        $body->write($bytes);
        $body->rewind();
        return $body;
    }
    public function testSmallJsonAndFormBodiesUseSmallReadsAndPreserveTypedValuesAndFormPosition(): void
    {
        foreach (["application/json", "application/x-www-form-urlencoded"] as $type) {
            $json = json_encode(
                [
                    "required" => false,
                    "credits" => 3,
                    "nested" => ["label" => "Fictional"],
                ],
                JSON_THROW_ON_ERROR,
            );
            $stream = $this->body(
                $type === "application/json"
                    ? $json
                    : http_build_query(["_workspace_json" => $json]),
            );
            $request = new ServerRequest(
                [],
                [],
                "/sis/admission-policy/",
                "POST",
            )
                ->withBody($stream)
                ->withHeader("Content-Type", $type);
            $handler = $this->createMock(RequestHandlerInterface::class);
            $handler
                ->expects(self::once())
                ->method("handle")
                ->willReturnCallback(static function (
                    ServerRequestInterface $r,
                ): JsonResponse {
                    self::assertSame(
                        [
                            "required" => false,
                            "credits" => 3,
                            "nested" => ["label" => "Fictional"],
                        ],
                        $r->getParsedBody(),
                    );
                    return new JsonResponse(["ok" => true]);
                });
            new WorkspaceJsonBodyMiddleware()->process($request, $handler);
            self::assertLessThanOrEqual(8192, max($stream->requested));
            if ($type !== "application/json") {
                self::assertSame(0, $stream->tell());
            }
        }
    }
    public function testTheExactJsonBoundaryIsAcceptedAndLargerJsonOrFormEnvelopesAreRejected(): void
    {
        $maximum = 2097152;
        $json = json_encode(
            ["text" => str_repeat("a", $maximum - 11)],
            JSON_THROW_ON_ERROR,
        );
        self::assertSame($maximum, strlen($json));
        $request = new ServerRequest([], [], "/sis/courses/", "POST")
            ->withBody($this->body($json))
            ->withHeader("Content-Type", "application/json");
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler
            ->expects(self::once())
            ->method("handle")
            ->willReturn(new JsonResponse(["ok" => true]));
        self::assertSame(
            200,
            new WorkspaceJsonBodyMiddleware()
                ->process($request, $handler)
                ->getStatusCode(),
        );
        foreach (["json", "decoded_form", "encoded_form"] as $kind) {
            $stream = $this->body(
                match ($kind) {
                    "json" => $json . " ",
                    "decoded_form" => http_build_query([
                        "_workspace_json" => $json . " ",
                    ]),
                    default => "_workspace_json=" .
                        str_repeat("a", $maximum * 3 + 17),
                },
            );
            $request = $request
                ->withBody($stream)
                ->withHeader(
                    "Content-Type",
                    $kind === "json"
                        ? "application/json"
                        : "application/x-www-form-urlencoded",
                );
            $never = $this->createMock(RequestHandlerInterface::class);
            $never->expects(self::never())->method("handle");
            try {
                new WorkspaceJsonBodyMiddleware()->process($request, $never);
                self::fail("An oversized body was accepted.");
            } catch (InvalidArgumentException) {
                self::assertLessThanOrEqual(8192, max($stream->requested));
            }
        }
    }
    public function testImportPreviewAllowsCsvEnvelopeOverheadWithoutRaisingOtherRouteLimits(): void
    {
        $json = json_encode(['csv' => str_repeat('a', 2097152)], JSON_THROW_ON_ERROR);
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects(self::once())->method('handle')->willReturnCallback(
            static function ($request): JsonResponse {
                self::assertSame(2097152, strlen($request->getParsedBody()['csv']));
                return new JsonResponse(['ok' => true]);
            }
        );
        $request = new ServerRequest([], [], '/sis/imports/people/preview/', 'POST')
            ->withBody($this->body($json))->withHeader('Content-Type', 'application/json');
        self::assertSame(200, new WorkspaceJsonBodyMiddleware()->process($request, $handler)->getStatusCode());
    }
}
