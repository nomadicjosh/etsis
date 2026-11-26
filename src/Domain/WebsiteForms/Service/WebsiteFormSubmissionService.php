<?php

declare(strict_types=1);

namespace Domain\WebsiteForms\Service;

use DateTimeImmutable;
use DateTimeZone;
use Domain\Communications\Service\MessageCipher;
use Domain\Portal\Repository\PagePublicationRepository;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\Persistence\UnitOfWork;
use Domain\Shared\Service\Clock;
use Domain\Shared\ValueObject\EntityId;
use Domain\WebsiteForms\Repository\WebsiteFormInboxRepository;
use Throwable;

use function Codefy\Framework\Helpers\trans_html;

/** Anonymous website inquiries are encrypted working intake, separate from person/application histories. */
final readonly class WebsiteFormSubmissionService
{
    public function __construct(
        private WebsiteFormCatalog $catalog,
        private PagePublicationRepository $pages,
        private WebsiteFormInboxRepository $inbox,
        private UnitOfWork $transactions,
        private MessageCipher $cipher,
        private Clock $clock,
        private WebsiteFormRoutingService $routing,
        private NewsletterService $newsletters,
    ) {
    }

    private function now(): DateTimeImmutable
    {
        return $this->clock->now()->setTimezone(new DateTimeZone('UTC'));
    }

    public function prepare(string $page, string $key, string $locale, #[\SensitiveParameter] string $csrf): array
    {
        if ($csrf === '') {
            throw new AccessDeniedException();
        }
        $source = $this->catalog->resolve($page, $key, $locale);
        $this->routing->validateDepartment($source['definition']->facts['department_id']);
        $now = $this->now();
        $ticket = ['purpose' => 'website.form.ticket', 'id' => EntityId::generate()->value,
            'page_id' => $page, 'form_key' => $key, 'locale' => $locale,
            'source_hash' => $source['source_hash'], 'session_hash' => hash('sha256', $csrf),
            'issued_at' => $now->format('Y-m-d\TH:i:s\Z'),
            'expires_at' => $now->modify('+20 minutes')->format('Y-m-d\TH:i:s\Z'),
            'nonce' => bin2hex(random_bytes(32))];
        return ['ticket' => $this->cipher->seal(json_encode($ticket, JSON_THROW_ON_ERROR)),
            'expires_at' => $ticket['expires_at']];
    }

    public function submit(
        string $page,
        string $key,
        #[\SensitiveParameter] string $csrf,
        #[\SensitiveParameter] string $proof,
        #[\SensitiveParameter] array $answers,
        string $honeypot = '',
    ): array {
        try {
            if (strlen($proof) > 8192 || strlen(json_encode($answers, JSON_THROW_ON_ERROR)) > 65536) {
                throw new \InvalidArgumentException();
            }
            $ticket = json_decode($this->cipher->open($proof), true, 8, JSON_THROW_ON_ERROR);
            $id = new EntityId($ticket['id']);
            if (
                $ticket['purpose'] !== 'website.form.ticket' || $ticket['page_id'] !== $page
                || $ticket['form_key'] !== $key || $csrf === ''
                || !hash_equals($ticket['session_hash'], hash('sha256', $csrf))
                || !is_string($ticket['nonce']) || strlen($ticket['nonce']) !== 64 || $honeypot !== ''
            ) {
                throw new \InvalidArgumentException();
            }
        } catch (Throwable) {
            throw new AccessDeniedException(trans_html('This form could not be submitted. Refresh the page and try again.'));
        }
        return $this->transactions->run(function () use ($page, $key, $ticket, $id, $answers): array {
            $this->pages->lock($page);
            $ordered = $answers;
            ksort($ordered);
            $intent = hash('sha256', $ticket['nonce'] . json_encode($ordered, JSON_THROW_ON_ERROR));
            $now = $this->now();
            $before = $now->modify('-1 hour')->format('Y-m-d\TH:i:s\Z');
            $this->inbox->prune($before);
            $accepted = $this->inbox->receipt($id);
            if ($accepted !== null && $accepted['accepted_at'] >= $before) {
                if (
                    $accepted['page_id'] !== $page || !hash_equals($accepted['intent_hash'], $intent)
                    || !hash_equals($accepted['session_hash'], $ticket['session_hash'])
                ) {
                    throw new ConcurrentModificationException(trans_html(
                        'This submission was already used with different answers.'
                    ));
                }
                return ['received' => true, 'message' => $accepted['success_message']];
            }
            if (
                $ticket['issued_at'] > $now->format('Y-m-d\TH:i:s\Z')
                || $ticket['expires_at'] <= $now->format('Y-m-d\TH:i:s\Z')
            ) {
                throw new ConcurrentModificationException(trans_html(
                    'This form expired. Refresh the page before submitting.'
                ));
            }
            if ($this->inbox->recent($page, $ticket['session_hash'], $before) >= 20) {
                throw new ValidationException([['field' => 'form',
                    'message' => trans_html('Too many messages were submitted. Please try again later.')]]);
            }
            $source = $this->catalog->resolve($page, $key, $ticket['locale']);
            if (!hash_equals($ticket['source_hash'], $source['source_hash'])) {
                throw new ConcurrentModificationException(trans_html(
                    'This form changed. Refresh the page before submitting.'
                ));
            }
            $definition = $source['definition']->facts;
            $this->routing->validateDepartment($definition['department_id']);
            $clean = $source['definition']->answers($answers);
            $at = $now->format('Y-m-d\TH:i:s\Z');
            $payload = ['purpose' => 'website.form.inquiry', 'id' => $id->value, 'page_id' => $page,
                'form_key' => $key, 'department_id' => $definition['department_id'],
                    'page_title' => $source['page_title'], 'form_title' => $definition['title'],
                'locale' => $ticket['locale'], 'source_hash' => $source['source_hash'], 'definition' => $definition,
                'answers' => $clean, 'submitted_at' => $at];
            $this->inbox->add(
                ['id' => $id->value, 'page_id' => $page, 'form_key' => $key,
                'department_id' => $definition['department_id'],
                'page_title' => $source['page_title'], 'form_title' => $definition['title'],
                'submitted_at' => $at, 'status' => 'open', 'version' => 0,
                'sealed_data' => $this->cipher->seal(json_encode($payload, JSON_THROW_ON_ERROR))],
                ['id' => $id->value, 'page_id' => $page, 'session_hash' => $ticket['session_hash'],
                'intent_hash' => $intent,
                'accepted_at' => $at,
                'success_message' => $definition['success_message']]
            );
            $this->newsletters->enqueue($id, $definition, $clean);
            if ($this->catalog->resolve($page, $key, $ticket['locale'])['source_hash'] !== $source['source_hash']) {
                throw new ConcurrentModificationException();
            }
            return ['received' => true, 'message' => $definition['success_message']];
        });
    }
}
