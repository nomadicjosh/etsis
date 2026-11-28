<?php

declare(strict_types=1);

namespace Infrastructure\Person;

use Domain\Person\Service\GravatarReader;
use Domain\Person\Service\PhotoPolicy;
use Domain\Shared\Exception\ValidationException;

use function Codefy\Framework\Helpers\trans;

final readonly class CurlGravatarReader implements GravatarReader
{
    public function image(#[\SensitiveParameter] string $email): string
    {
        $bytes = "";
        $handle = curl_init(
            "https://gravatar.com/avatar/" .
                hash("sha256", strtolower(trim($email))) .
                ".jpg?s=512&d=404&r=g",
        );
        try {
            curl_setopt_array($handle, [
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_CONNECTTIMEOUT => 3,
                CURLOPT_TIMEOUT => 8,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_MAXREDIRS => 0,
                CURLOPT_PROXY => "",
                CURLOPT_HTTPHEADER => ["Accept: image/jpeg"],
                CURLOPT_WRITEFUNCTION => static function (
                    $curl,
                    string $chunk,
                ) use (&$bytes): int {
                    if (
                        strlen($bytes) + strlen($chunk) >
                        PhotoPolicy::MAX_IMAGE
                    ) {
                        return 0;
                    }
                    $bytes .= $chunk;
                    return strlen($chunk);
                },
            ]);
            if (
                curl_exec($handle) === false ||
                curl_getinfo($handle, CURLINFO_RESPONSE_CODE) !== 200
            ) {
                throw new \RuntimeException();
            }
            return $bytes;
        } catch (\Throwable) {
            throw new ValidationException([
                [
                    "field" => "photo",
                    "message" =>
                        trans("A Gravatar photo could not be retrieved. You can upload a PNG or JPG instead."),
                ],
            ]);
        } finally {
            curl_close($handle);
        }
    }
}
