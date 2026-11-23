<?php

declare(strict_types=1);

namespace Domain\Admissions\Service;

use DateTimeZone;
use Domain\Admissions\Repository\AdmissionDocumentRepository;
use Domain\Admissions\Repository\AdmissionsRepository;
use Domain\Communications\Service\MessageCipher;
use Domain\Shared\Persistence\UnitOfWork;
use Domain\Shared\Service\Clock;
use Domain\Shared\ValueObject\EntityId;
use InvalidArgumentException;
use Throwable;

/** Deployment-only bounded work. No mail, academic decisions, raw scanner output or private job payloads. */
final readonly class AdmissionDocumentWorker
{
    public function __construct(
        private AdmissionDocumentRepository $documents,
        private AdmissionsRepository $admissions,
        private AdmissionDocumentStorage $storage,
        private AdmissionDocumentScanner $scanner,
        private AdmissionDocumentPolicy $policy,
        private MessageCipher $cipher,
        private UnitOfWork $transactions,
        private Clock $clock,
        private AdmissionDocumentHoldService $holds,
    ) {
    }
    private function at(string $offset = ''): string
    {
        $date = $this->clock->now()->setTimezone(new DateTimeZone('UTC'));
        return ($offset === '' ? $date : $date->modify($offset))->format('Y-m-d\TH:i:s.u\Z');
    }
    /** @return array{examined:int,ready:int,rejected:int,pending:int,removed:int} */
    public function run(int $limit = 1): array
    {
        if ($limit < 1 || $limit > 3) {
            throw new InvalidArgumentException('Use a batch of one through three documents.');
        }
        $result = ['examined' => 0, 'ready' => 0, 'rejected' => 0, 'pending' => 0, 'removed' => 0];
        $content = new AdmissionDocumentContent($this->cipher, $this->storage);
        $candidates = $this->documents->scanCandidates(
            $this->at(),
            $this->at('-5 minutes'),
            $limit,
            $this->policy->acceptsUploads()
        );
        foreach ($candidates as $candidate) {
            $id = new EntityId($candidate['id']);
            $person = new EntityId($candidate['person_id']);
            $application = new EntityId($candidate['application_id']);
            $claim = $this->transactions->run(function () use ($id, $person, $application, $content): ?array {
                $this->admissions->lockPerson($person);
                $this->documents->lock($id);
                $row = $this->documents->find($id);
                $content->metadata($row);
                if (
                    $row['person_id'] !== $person->value || $row['application_id'] !== $application->value
                    || $this->admissions->application($application)->personId->value !== $person->value
                ) {
                    throw new \DomainException('Document preservation needs institutional review.');
                }
                if ($row['status'] === 'purging') {
                    if ($this->holds->blocks($person, $application)) {
                        return ['preserved' => true];
                    }
                    // Local unlink stays inside the canonical-person lock so a hold cannot race removal.
                    try {
                        $this->storage->remove($application, $id);
                    } catch (Throwable) {
                        return ['preserved' => true];
                    }
                    $this->documents->change($id, (int) $row['version'], $content->changes(
                        $row,
                        ['status' => 'purged', 'scan_token' => null, 'scan_after' => $this->at('+15 minutes')],
                    ));
                    return ['removed' => true];
                }
                $eligible = ($row['status'] === 'quarantined' && $row['scan_after'] <= $this->at())
                    || ($row['status'] === 'scanning' && $row['scan_after'] <= $this->at('-5 minutes'));
                if (!$eligible || !$this->policy->acceptsUploads()) {
                    return null;
                }
                $token = EntityId::generate()->value;
                $this->documents->change($id, (int) $row['version'], $content->changes(
                    $row,
                    ['status' => 'scanning', 'scan_token' => $token, 'scan_after' => $this->at()]
                ));
                return $this->documents->find($id);
            });
            if ($claim === null) {
                continue;
            }
            $result['examined']++;
            if (isset($claim['removed']) || isset($claim['preserved'])) {
                $result[isset($claim['removed']) ? 'removed' : 'pending']++;
                continue;
            }
            // External scanning happens after releasing all person and document database locks.
            try {
                $verdict = $this->scanner->scan($content->read($claim));
                $outcome = match ($verdict) {
                    'clean' => 'ready', 'infected' => 'rejected', default => 'quarantined'
                };
            } catch (Throwable) {
                $outcome = 'quarantined';
            }
            $accepted = $this->transactions->run(function () use ($id, $person, $claim, $outcome, $content): bool {
                $this->admissions->lockPerson($person);
                $this->documents->lock($id);
                $row = $this->documents->find($id);
                if ($row['status'] !== 'scanning' || $row['scan_token'] !== $claim['scan_token']) {
                    return false;
                }
                $meta = $content->metadata($row);
                if ($outcome === 'ready' || $outcome === 'rejected') {
                    $meta['scan_verdict'] = $outcome === 'ready' ? 'clean' : 'infected';
                    $meta['scanned_at'] = $this->at();
                }
                $this->documents->change($id, (int) $row['version'], $content->changes(
                    $row,
                    ['status' => $outcome, 'scan_token' => null, 'scan_after' => $this->at('+15 minutes')],
                    $meta
                ));
                return true;
            });
            $result[$accepted ? match ($outcome) {
                'ready' => 'ready', 'rejected' => 'rejected',
                'purged' => 'removed', default => 'pending'
            } : 'pending']++;
        }
        return $result;
    }
}
