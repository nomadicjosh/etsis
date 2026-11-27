<?php

declare(strict_types=1);

namespace Domain\Transcript\Service;

use Domain\Communications\Service\MessageCipher;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Transcript\ValueObject\TranscriptLayout;
use Domain\Transcript\ValueObject\TranscriptReleaseConfirmation;
use Domain\Transcript\ValueObject\TranscriptReleasePolicy;
use Domain\Transcript\ValueObject\TranscriptStandingPresentation;

/** Reads retained originals only; no rendering, academic replay, access audit or delivery side effect. */
final readonly class TranscriptArtifactReader
{
    public const int MAX_CIPHERTEXT = 13 * 1024 * 1024;

    public function __construct(private MessageCipher $cipher)
    {
    }

    /** @param array<string,mixed> $row
     * @return array<string,mixed> */
    public function prepared(#[\SensitiveParameter] array $row): array
    {
        $p = $this->payload($row);
        // Preserve genuine earlier artifacts; absence means unrecorded, never an inferred confirmation.
        $hasReleaseReview = array_key_exists('release_review', $p);
        if (
            count($p) !== ($hasReleaseReview ? 7 : 6) || array_diff(
                array_keys($p),
                ['revision', 'academic_revision', 'document', 'pdf', 'html', 'html_hash', 'release_review']
            ) !== [] ||
            ($hasReleaseReview && !is_array($p['release_review'])) || !is_array($p['document'] ?? null) ||
            ($p['document']['document_id'] ?? null) !== $row['id'] ||
            ($p['document']['issued_at'] ?? null) !== $row['created_at'] ||
            ($p['document']['preview'] ?? null) !== false || !is_array($p['document']['presentation'] ?? null) ||
            !is_string($p['pdf'] ?? null) || strlen($p['pdf']) > 6 * 1024 * 1024 ||
            !is_string($p['html'] ?? null) || trim($p['html']) === '' || strlen($p['html']) > 1024 * 1024 ||
            !mb_check_encoding($p['html'], 'UTF-8')
        ) {
            throw new \RuntimeException('The prepared transcript could not be verified.');
        }
        foreach (['revision', 'academic_revision', 'html_hash'] as $key) {
            if (!is_string($p[$key] ?? null) || preg_match('/^[a-f0-9]{64}$/D', $p[$key]) !== 1) {
                throw new \RuntimeException('The prepared transcript has invalid integrity references.');
            }
        }
        if ($hasReleaseReview) {
            TranscriptReleasePolicy::validateEvidence(
                $p['release_review'],
                'preparation',
                $row['actor_id'],
                $row['created_at']
            );
        }
        if (isset($p['document']['standing'])) {
            $choice = $p['release_review']['policy']['standing_presentation'] ?? null;
            if ($choice !== ($p['document']['standing']['presentation'] ?? null)) {
                throw new CorruptHistoryException();
            }
            if ($choice !== null) {
                $presentation = TranscriptStandingPresentation::fromArray($choice);
                if ($presentation->apply($p['document']['standing']) !== $p['document']['standing']) {
                    throw new CorruptHistoryException();
                }
            }
        }
        TranscriptLayout::fromArray($p['document']['presentation']);
        if (!hash_equals($p['html_hash'], hash('sha256', $p['html']))) {
            throw new \RuntimeException('The stored accessible transcript failed verification.');
        }
        return $p + ['release_review' => null];
    }

    /** @param array<string,mixed> $row
     * @param array<string,mixed> $payload */
    public function pdf(#[\SensitiveParameter] array $row, #[\SensitiveParameter] array $payload): string
    {
        $bytes = base64_decode($payload['pdf'], true);
        if (
            $bytes === false || strlen($bytes) > 4 * 1024 * 1024 || !str_starts_with($bytes, '%PDF-') ||
            !is_string($row['pdf_hash'] ?? null) || !hash_equals($row['pdf_hash'], hash('sha256', $bytes))
        ) {
            throw new \RuntimeException('The stored transcript document failed verification.');
        }
        return $bytes;
    }

    /** @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function payload(#[\SensitiveParameter] array $row): array
    {
        if (
            !is_string($row['encrypted_content'] ?? null) ||
            strlen($row['encrypted_content']) > self::MAX_CIPHERTEXT
        ) {
            throw new \LengthException('The stored transcript exceeds its supported artifact size.');
        }
        $packet = $this->cipher->open($row['encrypted_content']);
        if (strlen($packet) > 6 * 1024 * 1024) {
            throw new \RuntimeException('The stored transcript exceeds its supported artifact size.');
        }
        return json_decode($packet, true, 32, JSON_THROW_ON_ERROR);
    }

    /** @param array<string,mixed> $context */
    private function policy(array $context): TranscriptReleasePolicy
    {
        return new TranscriptReleasePolicy(
            $context['release_policy']['revision'],
            $context['release_policy']['checks'],
            $context['release_policy']['include_standing'] ?? false,
            $context['release_policy']['standing_definitions'] ?? null,
            isset($context['release_policy']['standing_presentation'])
                ? TranscriptStandingPresentation::fromArray($context['release_policy']['standing_presentation'])
                : new TranscriptStandingPresentation(),
        );
    }

    /** @param array<string,mixed> $input */
    private function confirmation(array $input): ?TranscriptReleaseConfirmation
    {
        return ($input['release_confirmation'] ?? null) === null ? null :
        TranscriptReleaseConfirmation::fromArray($input['release_confirmation']);
    }

    /** @param array<string,mixed> $row
     * @return array<string,mixed> */
    public function delivery(#[\SensitiveParameter] array $row): array
    {
        $data = $this->payload($row);
        $hasReleaseReview = array_key_exists('release_review', $data);
        if (
            count($data) !== ($hasReleaseReview ? 3 : 2) || !is_array($data['request'] ?? null) ||
            !is_array($data['result'] ?? null) || ($data['request']['document_id'] ?? null) !== $row['document_id'] ||
            ($data['result']['document_id'] ?? null) !== $row['document_id'] ||
            ($data['result']['operation_id'] ?? null) !== $row['id'] ||
            ($hasReleaseReview && !is_array($data['release_review']))
        ) {
            throw new \RuntimeException('The retained delivery evidence is invalid.');
        }
        if ($hasReleaseReview) {
            TranscriptReleasePolicy::validateEvidence(
                $data['release_review'],
                'delivery',
                $row['actor_id'],
                $row['created_at']
            );
            $this->policy(['release_policy' => $data['release_review']['policy']])->confirm(
                'delivery',
                $this->confirmation($data['request'])
            );
        } elseif (($data['request']['release_confirmation'] ?? null) !== null) {
            throw new \RuntimeException('The delivery has no evidence for its institutional checks.');
        }
        return $data + ['release_review' => null];
    }
}
