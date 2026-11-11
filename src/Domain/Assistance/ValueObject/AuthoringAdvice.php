<?php

declare(strict_types=1);

namespace Domain\Assistance\ValueObject;

use Domain\Assistance\Exception\AssistanceUnavailableException;

/** Untrusted plain text only. Suggestions never have authority to change or publish a definition. */
final readonly class AuthoringAdvice implements \JsonSerializable
{
    /** @var list<string> */
    public array $suggestions;

    /** @param array<mixed> $suggestions */
    public function __construct(
        #[\SensitiveParameter] public string $summary,
        #[\SensitiveParameter] array $suggestions,
        #[\SensitiveParameter] public ?string $description = null,
    ) {
        self::text($summary, 1200);
        if (!array_is_list($suggestions) || count($suggestions) > 8) {
            throw new AssistanceUnavailableException();
        }
        foreach ($suggestions as $suggestion) {
            if (!is_string($suggestion)) {
                throw new AssistanceUnavailableException();
            }
            self::text($suggestion, 800);
        }
        $this->suggestions = $suggestions;
        if ($description !== null) {
            self::text($description, 10000);
        }
    }
    /** @param array<string,mixed> $data */
    public static function fromArray(#[\SensitiveParameter] array $data): self
    {
        if (
            count($data) !== 3 ||
            array_diff(array_keys($data), ["summary", "suggestions", "description"]) !== [] ||
            !is_string($data["summary"]) ||
            !is_array($data["suggestions"]) ||
            ($data["description"] !== null && !is_string($data["description"]))
        ) {
            throw new AssistanceUnavailableException();
        }
        return new self($data["summary"], $data["suggestions"], $data["description"]);
    }
    private static function text(#[\SensitiveParameter] string $text, int $maximum): void
    {
        if (
            trim($text) === "" ||
            !mb_check_encoding($text, "UTF-8") ||
            mb_strlen($text) > $maximum ||
            preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $text)
        ) {
            throw new AssistanceUnavailableException();
        }
    }
    /** @return array{summary:string,suggestions:list<string>,description:?string} */
    public function describe(): array
    {
        return [
            "summary" => $this->summary,
            "suggestions" => $this->suggestions,
            "description" => $this->description,
        ];
    }
    /** @return array<string,string> */
    public function jsonSerialize(): array
    {
        return $this->__debugInfo();
    }
    /** @return array<string,string> */
    public function __debugInfo(): array
    {
        return ["advice" => "[private authoring suggestion]"];
    }
}
