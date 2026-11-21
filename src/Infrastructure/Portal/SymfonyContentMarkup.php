<?php

declare(strict_types=1);

namespace Infrastructure\Portal;

use Domain\Portal\Service\ContentMarkup;
use Domain\Shared\Exception\ValidationException;
use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;

/** Restricted editorial HTML. Media, scripts, styles, frames and arbitrary attributes are never allowed. */
final readonly class SymfonyContentMarkup implements ContentMarkup
{
    private HtmlSanitizer $sanitizer;

    public function __construct()
    {
        $config = new HtmlSanitizerConfig()
            ->withMaxInputLength(100000)
            ->allowLinkSchemes(["https", "http", "mailto"])
            ->allowRelativeLinks()
            ->forceAttribute("a", "rel", "noopener noreferrer");
        $allowedTags = ["p", "br", "h2", "h3", "strong", "b", "em", "i", "u", "s", "blockquote", "ol", "ul", "li"];
        foreach ($allowedTags as $tag) {
            $config = $config->allowElement($tag);
        }
        $config = $config->allowElement("a", ["href", "title", "rel"]);
        $blockedTags = [
            "script",
            "style",
            "iframe",
            "object",
            "embed",
            "svg",
            "math",
            "template",
            "form",
            "img",
            "video",
            "audio",
        ];
        foreach ($blockedTags as $tag) {
            $config = $config->dropElement($tag);
        }
        $this->sanitizer = new HtmlSanitizer($config);
    }

    public function sanitize(#[\SensitiveParameter] string $html): string
    {
        if (strlen($html) > 100000 || !mb_check_encoding($html, "UTF-8") || str_contains($html, "\0")) {
            throw new ValidationException([
                ["field" => "content", "message" => "Use news content up to 100,000 bytes."],
            ]);
        }
        return $this->sanitizer->sanitize($html);
    }

    public function plainText(string $sanitizedHtml): string
    {
        $spaced = preg_replace("~</(?:p|h2|h3|li|blockquote)>|<br\s*/?>~i", "\n", $sanitizedHtml);
        return trim(html_entity_decode(strip_tags($spaced ?? ""), ENT_QUOTES | ENT_HTML5, "UTF-8"));
    }
}
