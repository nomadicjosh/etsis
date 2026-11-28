<?php

declare(strict_types=1);

namespace Infrastructure\Import;

use Domain\Access\Repository\AccessRepository;
use Domain\Import\Enum\CatalogImportTarget;
use Domain\Import\Service\ImportBatchProcessor;
use Domain\Import\Service\MappedImportService;
use Domain\Import\Service\PersonImportService;
use Domain\Import\ValueObject\ImportPlanFingerprint;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\ValueObject\EntityId;
use Domain\User\Repository\LoginAccountStateReader;
use League\Csv\Reader;
use League\Csv\Writer;

use function Codefy\Framework\Helpers\trans;

final readonly class ReviewedImportBatchProcessor implements ImportBatchProcessor
{
    public function __construct(
        private MappedImportService $catalog,
        private PersonImportService $people,
        private AccessRepository $roles,
        private LoginAccountStateReader $accounts,
        private \Domain\Person\Service\PhotoImportService $photos,
    ) {
    }

    public function authorize(EntityId $actor, string $target): void
    {
        $access = new ImportCommandAccess(
            $actor,
            $this->roles,
            $this->accounts,
        );
        $access->requirePermission("sis:import:manage");
        if (in_array($target, ['people', 'photos'], true)) {
            $access->requirePermission("sis:person:manage");
            if ($target === 'photos') {
                $access->requirePermission(\Domain\Person\Service\PersonPhotoService::MANAGE);
            }
        } else {
            CatalogImportTarget::from($target);
        }
    }

    public function execute(
        string $target,
        #[\SensitiveParameter] array $request,
        array $expected,
        int $offset,
        int $limit,
        EntityId $actor,
    ): array {
        $this->authorize($actor, $target);
        if ($target === 'photos') {
            return $this->photos->forAccess(new ImportCommandAccess($actor, $this->roles, $this->accounts))
                ->process($request, $expected, $offset, $limit);
        }
        $delimiter = $request["delimiter"] ?? ",";
        $reader = Reader::fromString($request["csv"])
            ->setDelimiter($delimiter)
            ->setEscape("");
        $writer = Writer::fromString()->setDelimiter($delimiter)->setEscape("");
        $writer->insertOne($reader->nth(0));
        $index = 0;
        $external = [];
        foreach ($reader->getRecords() as $values) {
            if ($index++ === 0 || $index <= $offset + 1) {
                continue;
            }
            if ($index > $offset + $limit + 1) {
                break;
            }
            $writer->insertOne($values);
            if ($target !== "people") {
                $external[] = trim($values[$request["mapping"]["external_id"]]);
            }
        }
        $csv = $writer->toString();
        $access = new ImportCommandAccess(
            $actor,
            $this->roles,
            $this->accounts,
        );
        if ($target === "people") {
            $service = $this->people->forAccess($access);
            $preview = $service->preview(
                $csv,
                $request["mapping"],
                $delimiter,
                $request["reason"],
            );
            $plan = $preview["rows"];
        } else {
            $service = $this->catalog->forAccess($access);
            $choices = array_values(
                array_filter(
                    $request["choices"] ?? [],
                    static fn(array $choice): bool => in_array(
                        $choice["external_id"],
                        $external,
                        true,
                    ),
                ),
            );
            $kind = CatalogImportTarget::from($target);
            $preview = $service->preview(
                $request["source"],
                $csv,
                $request["mapping"],
                $delimiter,
                $choices,
                $kind,
            );
            $plan = $preview["plan"];
        }
        if (
            !$preview["ready"] ||
            !is_string($preview["review_token"]) ||
            count($plan) !== $limit ||
            array_map(ImportPlanFingerprint::of(...), $plan) !== $expected
        ) {
            throw new ConcurrentModificationException(
                trans(
                    "The import source changed after review. Resolve the remaining records.",
                ),
            );
        }
        if ($target === "people") {
            $result = $service->commit($preview["review_token"]);
            return [
                "imported" => $result["created"],
                "linked" => 0,
                "skipped" => 0,
                "omitted" => 0,
            ];
        }
        $result = $service->commit(
            $request["source"],
            $csv,
            $request["mapping"],
            $preview["review_token"],
            $delimiter,
            $choices,
            $kind,
        );
        return array_intersect_key(
            $result,
            array_flip(["imported", "linked", "skipped", "omitted"]),
        );
    }
}
