<?php

declare(strict_types=1);

use Domain\Assistance\Exception\AssistanceUnavailableException;
use Domain\Assistance\ValueObject\AuthoringAdvice;
use Domain\Assistance\ValueObject\AuthoringTask;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Infrastructure\Assistance\ConfiguredAuthoringAssistant;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Qubus\Config\Collection;

final class ConfiguredAuthoringAssistantTest extends TestCase
{
    private function config(array $overrides = []): Collection
    {
        $config = Collection::factory([]);
        $config->setConfigKey(
            "assistance",
            array_replace(
                [
                    "enabled" => true,
                    "data_policy_approved" => true,
                    "label" => "Fictional service",
                    "endpoint" => "https://model.example.invalid/v1",
                    "protocol" => "responses",
                    "auth" => "bearer",
                    "key" => "fictional-secret",
                    "model" => "institution-deployment",
                ],
                $overrides,
            ),
        );
        return $config;
    }
    private static function response(array $advice = []): array
    {
        return [
            "status" => "completed",
            "output" => [
                [
                    "type" => "message",
                    "status" => "completed",
                    "content" => [
                        [
                            "type" => "output_text",
                            "text" => json_encode(
                                $advice ?: [
                                    "summary" => "Review the draft.",
                                    "suggestions" => ["Verify learning outcomes."],
                                    "description" => null,
                                ],
                                JSON_THROW_ON_ERROR,
                            ),
                        ],
                    ],
                ],
            ],
        ];
    }
    public function testDegreeSetupInstructionsDoNotClaimToReviewGroupsOrStudentEligibility(): void
    {
        $mock = new MockHandler([new Response(200, [], json_encode(self::response()))]);
        $service = new ConfiguredAuthoringAssistant($this->config(), new Client(["handler" => $mock]));
        self::assertNull(
            $service->suggest(AuthoringTask::DegreeReadiness, [
                "min_credits_milli" => 120000,
            ])->description,
        );
        $body = json_decode((string) $mock->getLastRequest()->getBody(), true);
        self::assertStringContainsString(
            "Group contents and student records are not supplied.",
            $body["input"][0]["content"],
        );
        self::assertStringContainsString("Return description as null.", $body["input"][0]["content"]);
    }
    public function testSectionReadinessHasItsOwnInstructionsAndRejectsDescriptionOutput(): void
    {
        $draft = ["section_code" => "MATH.101.A", "delivery_mode" => "online"];
        $mock = new MockHandler([new Response(200, [], json_encode(self::response()))]);
        $service = new ConfiguredAuthoringAssistant($this->config(), new Client(["handler" => $mock]));
        self::assertNull($service->suggest(AuthoringTask::SectionReadiness, $draft)->description);
        $body = json_decode((string) $mock->getLastRequest()->getBody(), true);
        self::assertStringContainsString(
            "Review saved section readiness only.",
            $body["input"][0]["content"],
        );
        self::assertStringContainsString(
            "Do not require rooms for online sections",
            $body["input"][0]["content"],
        );
        self::assertSame($draft, json_decode($body["input"][1]["content"], true));
        $bad = new MockHandler([
            new Response(
                200,
                [],
                json_encode(
                    self::response([
                        "summary" => "Review.",
                        "suggestions" => [],
                        "description" => "Unauthorized replacement text.",
                    ]),
                ),
            ),
        ]);
        $service = new ConfiguredAuthoringAssistant($this->config(), new Client(["handler" => $bad]));
        $this->expectException(AssistanceUnavailableException::class);
        $service->suggest(AuthoringTask::SectionReadiness, $draft);
    }
    public function testEditingTasksSendDistinctBoundedInstructionsAndOnlyReviewedFacts(): void
    {
        foreach ([AuthoringTask::Rewrite, AuthoringTask::Expand] as $task) {
            $payload = self::response([
                "summary" => "Review the wording.",
                "suggestions" => [],
                "description" => "A clearer description.",
            ]);
            $mock = new MockHandler([new Response(200, [], json_encode($payload))]);
            $service = new ConfiguredAuthoringAssistant($this->config(), new Client(["handler" => $mock]));
            $draft = [
                "title" => "Algebra",
                "description" => "An introduction to algebra.",
            ];
            self::assertSame("A clearer description.", $service->suggest($task, $draft)->description);
            $body = json_decode((string) $mock->getLastRequest()->getBody(), true);
            self::assertStringContainsString(
                ucfirst($task->value) . " the existing description",
                $body["input"][0]["content"],
            );
            self::assertSame($draft, json_decode($body["input"][1]["content"], true));
            self::assertFalse($body["store"]);
            self::assertSame(2000, $body["max_output_tokens"]);
        }
    }
    public function testEditingTasksCannotCallProviderWithoutSourceText(): void
    {
        foreach ([AuthoringTask::Rewrite, AuthoringTask::Expand] as $task) {
            $mock = new MockHandler([]);
            $service = new ConfiguredAuthoringAssistant($this->config(), new Client(["handler" => $mock]));
            try {
                $service->suggest($task, ["description" => "   "]);
                self::fail("An empty description was accepted.");
            } catch (AssistanceUnavailableException) {
                self::assertNull($mock->getLastRequest());
            }
        }
    }
    public function testResponsesRequestIsBoundedPrivateAndStrict(): void
    {
        $mock = new MockHandler([new Response(200, [], json_encode(self::response()))]);
        $service = new ConfiguredAuthoringAssistant(
            $this->config(),
            new Client(["handler" => HandlerStack::create($mock)]),
        );
        $draft = ["code" => "MATH.101", "title" => "Algebra"];
        self::assertSame("Review the draft.", $service->suggest(AuthoringTask::Readiness, $draft)->summary);
        $request = $mock->getLastRequest();
        self::assertSame("https://model.example.invalid/v1/responses", (string) $request->getUri());
        self::assertSame("Bearer fictional-secret", $request->getHeaderLine("Authorization"));
        $body = json_decode((string) $request->getBody(), true);
        self::assertFalse($body["store"]);
        self::assertSame(2000, $body["max_output_tokens"]);
        self::assertTrue($body["text"]["format"]["strict"]);
        self::assertSame($draft, json_decode($body["input"][1]["content"], true));
        $options = $mock->getLastOptions();
        foreach (["debug", "allow_redirects", "http_errors", "cookies"] as $name) {
            self::assertFalse($options[$name]);
        }
        self::assertTrue($options["verify"]);
        self::assertSame(15, $options["timeout"]);
        self::assertSame(5, $options["read_timeout"]);
        self::assertStringNotContainsString("fictional-secret", print_r($service, true));
    }
    public function testAzureChatAndLocalAdaptersUseTheSameContract(): void
    {
        foreach (["api-key", "none"] as $auth) {
            $mock = new MockHandler([
                new Response(
                    200,
                    [],
                    json_encode([
                        "choices" => [
                            [
                                "finish_reason" => "stop",
                                "message" => [
                                    "content" => json_encode([
                                        "summary" => "Review this suggestion.",
                                        "suggestions" => [],
                                        "description" => "An introduction to algebra.",
                                    ]),
                                ],
                            ],
                        ],
                    ]),
                ),
            ]);
            $service = new ConfiguredAuthoringAssistant(
                $this->config(["protocol" => "chat", "auth" => $auth]),
                new Client(["handler" => HandlerStack::create($mock)]),
            );
            self::assertSame(
                "An introduction to algebra.",
                $service->suggest(AuthoringTask::Description, [])->description,
            );
            $request = $mock->getLastRequest();
            self::assertStringEndsWith("/chat/completions", (string) $request->getUri());
            self::assertSame(
                $auth === "api-key" ? "fictional-secret" : "",
                $request->getHeaderLine("api-key"),
            );
            self::assertSame("", $request->getHeaderLine("Authorization"));
            $body = json_decode((string) $request->getBody(), true);
            self::assertTrue($body["response_format"]["json_schema"]["strict"]);
            self::assertFalse($body["store"]);
        }
    }
    public function testOfficialOpenAiRequiresBearerAuthenticationBeforeNetworkWork(): void
    {
        $mock = new MockHandler([]);
        $service = new ConfiguredAuthoringAssistant(
            $this->config([
                "endpoint" => "https://api.openai.com/v1",
                "auth" => "none",
            ]),
            new Client(["handler" => $mock]),
        );
        try {
            $service->suggest(AuthoringTask::Description, []);
            self::fail("An unauthenticated OpenAI request was accepted.");
        } catch (AssistanceUnavailableException $e) {
            self::assertSame(\Domain\Assistance\ValueObject\AssistanceFailure::Configuration, $e->failure);
            self::assertNull($mock->getLastRequest());
        }
    }
    public function testProviderFailuresExposeOnlyFixedActionableCategories(): void
    {
        foreach (
            [
                401 => "Authentication",
                403 => "Authentication",
                429 => "Capacity",
                400 => "RequestRejected",
                404 => "RequestRejected",
                500 => "Unavailable",
            ]
            as $status => $category
        ) {
            $mock = new MockHandler([
                new Response($status, [], "fictional-secret private prompt and upstream body"),
            ]);
            $service = new ConfiguredAuthoringAssistant($this->config(), new Client(["handler" => $mock]));
            try {
                $service->suggest(AuthoringTask::Description, []);
                self::fail("A rejected response was accepted.");
            } catch (AssistanceUnavailableException $e) {
                self::assertSame($category, $e->failure->name);
                self::assertStringNotContainsString("fictional-secret", (string) $e);
                self::assertStringNotContainsString("upstream body", (string) $e);
                self::assertNull($e->getPrevious());
            }
        }
    }
    public static function invalidSettings(): iterable
    {
        yield [["enabled" => false]];
        yield [["data_policy_approved" => false]];
        yield [["model" => ""]];
        yield [["endpoint" => "http://model.example.invalid/v1"]];
        yield [["endpoint" => "https://secret@example.invalid/v1"]];
        yield [["endpoint" => "https://example.invalid/v1?key=secret"]];
        yield [["auth" => "unexpected"]];
        yield [["key" => "secret\r\nInjected: true"]];
        yield [["protocol" => "unexpected"]];
    }
    #[DataProvider("invalidSettings")]
    public function testDisabledOrUnsafeConfigurationNeverCallsProvider(array $settings): void
    {
        $mock = new MockHandler([]);
        $service = new ConfiguredAuthoringAssistant(
            $this->config($settings),
            new Client(["handler" => $mock]),
        );
        try {
            $service->suggest(AuthoringTask::Readiness, []);
            self::fail("Invalid settings accepted.");
        } catch (AssistanceUnavailableException $e) {
            self::assertNull($e->getPrevious());
            self::assertNull($mock->getLastRequest());
        }
    }
    public static function invalidResponses(): iterable
    {
        yield [new Response(429, [], "fictional private upstream body")];
        yield [new Response(200, [], "not json")];
        yield [new Response(200, [], str_repeat("x", 131073))];
        yield [new Response(200, [], json_encode(["status" => "incomplete", "output" => []]))];
        yield [
            new Response(
                200,
                [],
                json_encode([
                    "status" => "completed",
                    "output" => [["type" => "function_call"]],
                ]),
            ),
        ];
        yield [
            new Response(
                200,
                [],
                json_encode(
                    self::response([
                        "summary" => "A",
                        "suggestions" => [],
                        "description" => null,
                        "extra" => true,
                    ]),
                ),
            ),
        ];
        yield [
            new Response(
                200,
                [],
                json_encode(
                    self::response([
                        "summary" => "A",
                        "suggestions" => [],
                        "description" => "Not a readiness response.",
                    ]),
                ),
            ),
        ];
        yield [
            new Response(
                200,
                [],
                json_encode(
                    self::response([
                        "summary" => "A",
                        "suggestions" => ["bad\x00text"],
                        "description" => null,
                    ]),
                ),
            ),
        ];
        yield [new RuntimeException("fictional-secret private prompt")];
    }
    #[DataProvider("invalidResponses")]
    public function testInvalidResponsesFailWithSafeMessageAndNoExceptionChain(
        Response|Throwable $response,
    ): void {
        $service = new ConfiguredAuthoringAssistant(
            $this->config(),
            new Client(["handler" => new MockHandler([$response])]),
        );
        try {
            $service->suggest(AuthoringTask::Readiness, []);
            self::fail("Invalid upstream response accepted.");
        } catch (AssistanceUnavailableException $e) {
            self::assertNull($e->getPrevious());
            self::assertStringNotContainsString("fictional-secret", $e->getMessage());
            self::assertStringNotContainsString("private", $e->getMessage());
        }
    }
    public function testPolicyChangeInvalidatesConsentAndAdviceIsRedactedByDefault(): void
    {
        $config = $this->config();
        $service = new ConfiguredAuthoringAssistant($config);
        $before = $service->availability()["fingerprint"];
        $config->setConfigKey(
            "assistance",
            array_replace($config->getConfigKey("assistance"), [
                "key" => "another-fictional-key",
            ]),
        );
        self::assertNotSame($before, $service->availability()["fingerprint"]);
        $advice = new AuthoringAdvice("Private draft words.", [], "Private suggested description.");
        self::assertStringNotContainsString("Private", json_encode($advice));
        self::assertStringNotContainsString("Private", print_r($advice, true));
        self::assertSame("Private draft words.", $advice->describe()["summary"]);
    }
}
