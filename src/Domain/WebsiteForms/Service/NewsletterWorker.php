<?php

declare(strict_types=1);

namespace Domain\WebsiteForms\Service;

use Domain\Communications\Service\MessageCipher;
use Domain\Shared\Persistence\UnitOfWork;
use Domain\Shared\Service\Clock;
use Domain\Shared\ValueObject\EntityId;
use Domain\WebsiteForms\Repository\NewsletterRepository;
use Domain\WebsiteForms\Repository\WebsiteFormInboxRepository;
use Domain\WebsiteForms\ValueObject\WebsiteFormDefinition;
use Throwable;

/** Jobs contain references only. A bounded delivery and inquiry deletion serialize on the inquiry row. */
final readonly class NewsletterWorker
{
    public function __construct(
        private NewsletterRepository $jobs,
        private WebsiteFormInboxRepository $inquiries,
        private NewsletterService $connections,
        private NewsletterPolicy $policy,
        private NewsletterGateway $gateway,
        private MessageCipher $cipher,
        private UnitOfWork $transactions,
        private Clock $clock,
    ) {
    }

    public function run(int $limit = 1): array
    {
        if ($limit < 1 || $limit > 3) {
            throw new \InvalidArgumentException();
        }
        $counts = ['processed' => 0, 'completed' => 0, 'attention' => 0, 'paused' => !$this->policy->enabled()];
        $deadline = microtime(true) + 25;
        while (!$counts['paused'] && $counts['processed'] < $limit && microtime(true) + 10 < $deadline) {
            $now = $this->clock->now()->getTimestamp();
            $job = $this->jobs->next($now);
            if ($job === null) {
                break;
            }
            $id = new EntityId($job['id']);
            if ($job['state'] === 'sending') {
                $this->transactions->run(function () use ($id, $job, $now): void {
                    $this->inquiries->lock($id);
                    $actual = $this->jobs->job($id);
                    if (
                        $actual !== null && $actual['state'] === 'sending' && (int) $actual['lease_until'] <= $now
                        && (int) $actual['version'] === (int) $job['version']
                    ) {
                        $this->jobs->finish($id, (int) $job['version'], 'review', 'interrupted', 0);
                    }
                });
                $counts['processed']++;
                $counts['attention']++;
                continue;
            }
            $claimed = $this->transactions->run(function () use ($id, $job, $now): bool {
                $this->inquiries->lock($id);
                if ($this->inquiries->get($id) === null) {
                    $this->jobs->remove($id);
                    return false;
                }
                return $this->jobs->claim($id, (int) $job['version'], $now);
            });
            if (!$claimed) {
                break;
            }
            $outcome = $this->transactions->run(function () use ($id, $job): ?string {
                $connectionId = new EntityId($job['connection_id']);
                $this->jobs->lockConnection($connectionId);
                $this->inquiries->lock($id);
                $actual = $this->jobs->job($id);
                $row = $this->inquiries->get($id);
                if (
                    $actual === null || $row === null || $actual['state'] !== 'sending'
                    || (int) $actual['version'] !== (int) $job['version'] + 1
                ) {
                    return null;
                }
                $profile = $this->jobs->connection($connectionId);
                $result = 'disabled';
                if ($this->policy->enabled() && $profile !== null && (bool) $profile['enabled']) {
                    $result = $profile['target_hash'] === $job['target_hash'] ? 'unreadable' : 'changed';
                    if ($result === 'unreadable') {
                        try {
                            $connection = $this->connections->open($profile);
                            if (strlen($row['sealed_data']) > 524288) {
                                throw new \RuntimeException();
                            }
                            $payload = json_decode(
                                $this->cipher->open($row['sealed_data']),
                                true,
                                32,
                                JSON_THROW_ON_ERROR
                            );
                            if ($payload['purpose'] !== 'website.form.inquiry') {
                                throw new \RuntimeException();
                            }
                            foreach (
                                ['id', 'page_id', 'form_key', 'department_id', 'page_title',
                                'form_title', 'submitted_at'] as $key
                            ) {
                                if ($payload[$key] !== $row[$key]) {
                                    throw new \RuntimeException();
                                }
                            }
                            $definition = new WebsiteFormDefinition($payload['definition']);
                            $answers = $definition->answers($payload['answers']);
                            $binding = $definition->facts['newsletter'];
                            if (
                                $binding === null || $binding['connection_id'] !== $connectionId->value
                                || $binding['target_hash'] !== $job['target_hash']
                                || ($answers[$binding['consent_field']] ?? false) !== true
                                || !$this->policy->permitsHost($connection->host)
                            ) {
                                throw new \RuntimeException();
                            }
                            $contact = ['email' => $answers[$binding['email_field']],
                                'name' =>
                                    $binding['name_field'] === null ? '' : ($answers[$binding['name_field']] ?? ''),
                                'consented_at' => $row['submitted_at'],
                                'consent_label' => array_column(
                                    $definition->facts['fields'],
                                    null,
                                    'name'
                                )[$binding['consent_field']]['label']];
                            $contact['name'] = mb_substr($contact['name'], 0, 120);
                        } catch (Throwable) {
                            $contact = null;
                        }
                        if ($contact !== null) {
                            try {
                                $result = $this->gateway->subscribe($connection, $contact, $id);
                            } catch (Throwable) {
                                $result = 'uncertain';
                            }
                        }
                    }
                }
                if (
                    !in_array($result, ['accepted', 'pending', 'already_member', 'suppressed', 'retry', 'rejected',
                    'uncertain', 'disabled', 'changed', 'unreadable'], true)
                ) {
                    $result = 'uncertain';
                }
                $state = match ($result) {
                    'accepted', 'pending', 'already_member' => 'completed',
                    'retry' => (int) $actual['attempts'] < 3 ? 'retry' : 'review',
                    'uncertain' => 'review', default => 'blocked',
                };
                $this->jobs->finish(
                    $id,
                    (int) $actual['version'],
                    $state,
                    $result,
                    $this->clock->now()->getTimestamp() + ((int) $actual['attempts'] === 1 ? 60 : 300)
                );
                return $state;
            });
            $counts['processed']++;
            if ($outcome === 'completed') {
                $counts['completed']++;
            } elseif (in_array($outcome, ['review', 'blocked'], true)) {
                $counts['attention']++;
            }
        }
        return $counts;
    }
}
