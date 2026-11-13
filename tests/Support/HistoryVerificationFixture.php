<?php

declare(strict_types=1);

require_once __DIR__ . "/SisFixture.php";

use Codefy\Domain\Metadata;
use Domain\Finance\Event\StudentAccountWasOpened;
use Domain\Finance\Event\AccountEntryWasPosted;
use Domain\Finance\ValueObject\AccountId;
use Domain\Shared\EventSourcing\EventContext;
use Domain\Shared\EventSourcing\StreamReference;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\EventSourcing\HistoricalSchemaRegistry;
use Infrastructure\EventSourcing\ProtectedEventCodec;
use Infrastructure\EventSourcing\SqlHistoricalEventStore;
use Infrastructure\EventSourcing\SqlHistoricalIntegrityVerifier;

final class HistoryVerificationFixture
{
    public SisFixture $sis;
    public ProtectedEventCodec $codec;
    public SqlHistoricalEventStore $store;
    public SqlHistoricalIntegrityVerifier $verifier;
    public function __construct()
    {
        $this->sis = $s = new SisFixture();
        $this->codec = new ProtectedEventCodec(EventStoreFixture::config($s->connection->queryBuilder()));
        $schemas = new HistoricalSchemaRegistry();
        $this->store = new SqlHistoricalEventStore(
            $s->sql,
            $s->transactions,
            $s->clock,
            $this->codec,
            $schemas,
        );
        $this->verifier = new SqlHistoricalIntegrityVerifier($s->sql, $this->store, $this->codec, $schemas);
    }
    public function stream(int $length = 3): StreamReference
    {
        $stream = new StreamReference(new AccountId(), "student_account");
        $context = new EventContext(
            $this->sis->access->actor,
            $this->sis->clock->now(),
            EntityId::generate(),
            reason: "Fictional confidential approval",
            approval: EntityId::generate(),
        );
        $events = [
            StudentAccountWasOpened::occur(
                $stream->aggregateId,
                [
                    "student_id" => EntityId::generate()->value,
                    "term_id" => $this->sis->term->value,
                    "currency" => "USD",
                ],
                [Metadata::AGGREGATE_TYPE => "student_account"],
            )->withPlayhead(1),
        ];
        for ($position = 2; $position <= $length; $position++) {
            $events[] = AccountEntryWasPosted::occur(
                $stream->aggregateId,
                [
                    "operation_id" => EntityId::generate()->value,
                    "type" => "charge",
                    "minor" => 100,
                    "currency" => "USD",
                    "actor_id" => $context->actor->value,
                ],
                [Metadata::AGGREGATE_TYPE => "student_account"],
            )->withPlayhead($position);
        }
        foreach (array_chunk($events, 50) as $index => $batch) {
            $this->store->append($stream, EntityId::generate(), $index * 50, $context, $batch);
        }
        return $stream;
    }
    public function digest(): string
    {
        $data = [];
        foreach (
            [
                "sis_history_events",
                "sis_history_streams",
                "sis_history_operations",
                "sis_student_accounts",
                "sis_audit",
            ]
            as $table
        ) {
            $data[$table] = $this->sis->sql->rows($table);
        }
        return hash("sha256", json_encode($data, JSON_THROW_ON_ERROR));
    }
}
