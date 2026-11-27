<?php

declare(strict_types=1);

namespace Domain\Person\Service;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use Domain\Communications\Service\MessageCipher;
use Domain\Person\Repository\AccountSetupRepository;
use Domain\Person\ValueObject\AccountSetupGrant;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\RecordNotFoundException;
use Domain\Shared\Persistence\UnitOfWork;
use Domain\Shared\Service\Clock;
use Domain\Shared\ValueObject\EntityId;
use DomainException;
use InvalidArgumentException;

/** Operator-only temporary notice retention; never invokes mail, account or historical writers. */
final readonly class AccountSetupRetention
{
    public function __construct(
        private AccountSetupRepository $setups,
        private MessageCipher $cipher,
        private UnitOfWork $transactions,
        private Clock $clock,
    ) {
    }

    /** Each applied row is atomic; a later stop preserves earlier completed redactions.
     * @return array<string,int|bool|string|null> */
    public function run(bool $apply = false, int $limit = 50, int $graceHours = 24, ?EntityId $after = null): array
    {
        if ($limit < 1 || $limit > 100 || $graceHours < 1 || $graceHours > 8760) {
            throw new InvalidArgumentException('Use 1–100 candidates and 1–8760 grace hours.');
        }
        $started = hrtime(true);
        $cutoff = $this->clock->now()->setTimezone(new DateTimeZone('UTC'))
            ->sub(new DateInterval('PT' . $graceHours . 'H'))->format('Y-m-d\TH:i:s.u\Z');
        $result = ['apply' => $apply, 'cutoff_utc' => $cutoff, 'examined' => 0, 'eligible' => 0,
            'cleared' => 0, 'invalid' => 0, 'changed' => 0, 'limited' => false, 'next_after' => null];
        $ids = $this->setups->retentionCandidates($cutoff, $after, $limit + 1);
        $more = count($ids) > $limit;
        $last = $after;
        foreach (array_slice($ids, 0, $limit) as $id) {
            if (hrtime(true) - $started >= 5_000_000_000) {
                $result['limited'] = true;
                break;
            }
            $row = $this->setups->retentionRecord($id);
            ++$result['examined'];
            try {
                if (!$this->eligible($row, $cutoff)) {
                    ++$result['changed'];
                } elseif (!$apply) {
                    ++$result['eligible'];
                } else {
                    $outcome = $this->transactions->run(function () use ($id, $row, $started): string {
                        $current = $this->setups->retentionRecord($id, lock: true);
                        if ($current !== $row) {
                            return 'changed';
                        }
                        if (hrtime(true) - $started >= 5_000_000_000) {
                            return 'limited';
                        }
                        if (!$this->setups->redactNotice($id, $row['token_hash'], $row['state'], $row['expires_at'])) {
                            throw new ConcurrentModificationException('The setup notice changed.');
                        }
                        $expected = array_replace($row, ['has_notice' => 0,
                            'state' => $row['state'] === 'sending' ? 'uncertain' : 'expired']);
                        if ($this->setups->retentionRecord($id) !== $expected) {
                            throw new ConcurrentModificationException('The setup notice changed.');
                        }
                        return 'cleared';
                    });
                    if ($outcome === 'limited') {
                        $result['limited'] = true;
                        break;
                    }
                    ++$result[$outcome];
                    if ($outcome === 'cleared') {
                        ++$result['eligible'];
                    }
                }
            } catch (ConcurrentModificationException | RecordNotFoundException) {
                ++$result['changed'];
            } catch (DomainException) {
                ++$result['invalid'];
            }
            $last = $id;
        }
        if ($more || $result['limited']) {
            $result['next_after'] = $last?->value;
        }
        return $result;
    }

    private function eligible(#[\SensitiveParameter] ?array $row, string $cutoff): bool
    {
        if (
            $row === null || (int) ($row['has_notice'] ?? 0) !== 1 ||
            !in_array($row['state'] ?? null, ['queued', 'sending'], true)
        ) {
            return false;
        }
        if ($row['consumed_at'] !== null || !AccountSetupGrant::matches($this->cipher, $row)) {
            throw new DomainException('The invitation binding was not verified.');
        }
        foreach (['requested_at', 'expires_at'] as $key) {
            $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s.u\Z', $row[$key], new DateTimeZone('UTC'));
            if ($date === false || $date->format('Y-m-d\TH:i:s.u\Z') !== $row[$key]) {
                throw new DomainException('The invitation date was not verified.');
            }
        }
        if ($row['requested_at'] >= $row['expires_at']) {
            throw new DomainException('The invitation dates conflict.');
        }
        return $row['expires_at'] <= $cutoff;
    }
}
