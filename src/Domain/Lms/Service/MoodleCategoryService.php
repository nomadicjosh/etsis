<?php

declare(strict_types=1);

namespace Domain\Lms\Service;

use Domain\Access\Repository\AccessRepository;
use Domain\Audit\Repository\AuditTrail;
use Domain\Catalog\ValueObject\CourseDefinition;
use Domain\Communications\Service\MessageCipher;
use Domain\Lms\Repository\{MoodleCategoryRepository, MoodleBindingRepository};
use Domain\Lms\ValueObject\{MoodleBinding, MoodleExternalId};
use Domain\Shared\Exception\{AccessDeniedException, ConcurrentModificationException, ValidationException};
use Domain\Shared\Persistence\UnitOfWork;
use Domain\Shared\Security\StudentAccess;
use Domain\Shared\Service\Clock;
use Domain\Shared\ValueObject\EntityId;

final readonly class MoodleCategoryService
{
    public function __construct(
        private MoodleCategoryRepository $categories,
        private MoodleBindingRepository $bindings,
        private MoodleSectionGateway $remote,
        private MoodleSectionSyncPolicy $policy,
        private AccessRepository $roles,
        private StudentAccess $access,
        private UnitOfWork $transactions,
        private Clock $clock,
        private AuditTrail $audit,
        private MessageCipher $cipher,
    ) {
    }
    private function authorize(): EntityId
    {
        $a = $this->access->requirePermission("sis:moodle:manage");
        if ($this->access->requirePermission("sis:catalog:manage")->value !== $a->value) {
            throw new AccessDeniedException();
        }
        return $a;
    }
    private function binding(): MoodleBinding
    {
        return $this->bindings->current() ??
        throw new AccessDeniedException("Approve the Moodle installation first.");
    }
    /** @return array<string,mixed>|null */
    public function configuration(MoodleBinding $binding): ?array
    {
        return $this->categories->settings($binding->id);
    }
    /** @return array{source:?string,version:int} */
    public function settings(): array
    {
        $a = $this->authorize();
        $b = $this->bindings->current();
        $r = $b === null ? null : $this->configuration($b);
        if ($this->authorize()->value !== $a->value) {
            throw new AccessDeniedException();
        }
        return ["source" => $r["source"] ?? null, "version" => (int) ($r["version"] ?? 0)];
    }
    /** @return array{source:?string,version:int} */
    public function configure(string $source, int $version): array
    {
        if (!in_array($source, ["subjects", "departments"], true) || $version < 0 || $version >= 2147483646) {
            throw new ValidationException([
                ["field" => "source", "message" => "Choose Subjects or Departments."],
            ]);
        }
        $a = $this->authorize();
        $b = $this->binding();
        $this->transactions->run(function () use ($source, $version, $a, $b): void {
            $this->roles->lock();
            if ($this->authorize()->value !== $a->value || $this->binding()->describe() !== $b->describe()) {
                throw new ConcurrentModificationException();
            }
            $r = $this->configuration($b);
            if (($r["source"] ?? null) === $source && (int) $r["version"] === $version) {
                return;
            }
            $this->categories->configure(
                $b->id,
                $source,
                $version,
                $a,
                $this->clock->now()->setTimezone(new \DateTimeZone("UTC"))->format("Y-m-d\TH:i:s\Z"),
            );
            $this->audit->record($a, "moodle.category_policy_changed", $b->id);
        });
        return $this->settings();
    }
    /** @return array<string,mixed> */
    public function page(string $search): array
    {
        $a = $this->authorize();
        $b = $this->binding();
        $s = $this->configuration($b);
        $items =
        $s === null || mb_strlen(trim($search)) < 2
        ? []
        : $this->categories->page($b->id, $s["source"], mb_substr(trim($search), 0, 80));
        if ($this->authorize()->value !== $a->value) {
            throw new AccessDeniedException();
        }
        return ["items" => $items, "source" => $s["source"] ?? null];
    }
    /** @return array{source:string,reference_id:string,code:string,title:string,settings_version:int} */
    public function route(MoodleBinding $binding, CourseDefinition $definition): array
    {
        $s = $this->configuration($binding);
        if ($s === null) {
            throw new ValidationException([
                [
                    "field" => "create_in_moodle",
                    "message" => "Choose Subjects or Departments under Moodle integration first.",
                ],
            ]);
        }
        $ref = $s["source"] === "subjects" ? $definition->subject : $definition->classifications->department;
        if ($ref === null) {
            throw new ValidationException([
                [
                    "field" => "create_in_moodle",
                    "message" =>
                        "The retained course needs the chosen subject or department before Moodle creation.",
                ],
            ]);
        }
        $r = $this->categories->reference($s["source"], $ref->id);
        if (!$r["active"]) {
            throw new ValidationException([
                [
                    "field" => "create_in_moodle",
                    "message" => "The retained course category reference is inactive. Review its setup.",
                ],
            ]);
        }
        return [
            "source" => $s["source"],
            "reference_id" => $ref->id->value,
            "code" => $r["code"],
            "title" => $r["title"],
            "settings_version" => (int) $s["version"],
        ];
    }
    /** @param array<string,mixed> $route */
    public function validateRoute(MoodleBinding $binding, array $route): void
    {
        $s = $this->configuration($binding);
        if (
            $s === null ||
            ($route["source"] ?? null) !== $s["source"] ||
            ($route["settings_version"] ?? null) !== (int) $s["version"]
        ) {
            throw new ConcurrentModificationException(
                "Moodle category policy changed. Review the publication again.",
            );
        }
        $r = $this->categories->reference($route["source"], new EntityId($route["reference_id"]));
        if (!$r["active"] || $r["code"] !== $route["code"] || $r["title"] !== $route["title"]) {
            throw new ConcurrentModificationException(
                "The category reference changed. Review the publication again.",
            );
        }
    }
    private function workerAuthority(): EntityId
    {
        if (!$this->policy->enabled()) {
            throw new AccessDeniedException("Moodle synchronization is disabled.");
        }
        $a = $this->policy->actor();
        $this->roles->requireUser($a);
        if (
            !$this->roles->allows($a->value, "sis:moodle:manage") ||
            !$this->roles->allows($a->value, "sis:catalog:manage")
        ) {
            throw new AccessDeniedException("Current category synchronization authority is required.");
        }
        return $a;
    }
    public function runOne(): string
    {
        if (!$this->policy->enabled()) {
            return "idle";
        }
        $a = $this->workerAuthority();
        $b = $this->binding();
        $s = $this->configuration($b);
        if ($s === null) {
            return "idle";
        }
        $r = $this->categories->candidate($b->id, $s["source"], $this->clock->now()->getTimestamp());
        if ($r === null) {
            return "idle";
        }
        $m = $this->categories->mapping($b->id, $s["source"], new EntityId($r["id"]));
        if (
            $m !== null &&
            $m["external_id"] !== null &&
            $m["source_hash"] === hash("sha256", json_encode($r, JSON_THROW_ON_ERROR))
        ) {
            $this->transactions->run(function () use ($b, $s, $r): void {
                $this->workerAuthority();
                $now = $this->clock->now()->getTimestamp();
                $claim = $this->categories->claim($b->id, $s["source"], new EntityId($r["id"]), $now);
                $this->categories->finish(
                    new EntityId($claim["id"]),
                    (int) $claim["version"],
                    $claim["external_id"],
                    (bool) $claim["is_owned"],
                    $r,
                    "none",
                    0,
                    $now,
                );
            });
            return "category_unchanged";
        }
        try {
            $this->synchronize($b, $s["source"], $r, null, $a, null);
            return "category_synchronized";
        } catch (ConcurrentModificationException) {
            return "source_changed";
        } catch (\Throwable) {
            return "category_setup_required";
        }
    }
    /** Resolve the originally reviewed identity, even if the institution later changes its policy.
     * @param array<string,mixed> $route */
    public function resolve(MoodleBinding $binding, array $route): ?string
    {
        $a = $this->workerAuthority();
        $r = $this->categories->reference($route["source"], new EntityId($route["reference_id"]));
        if (!$r["active"]) {
            return null;
        }
        $m = $this->categories->mapping($binding->id, $route["source"], new EntityId($r["id"]));
        if (($m["issue_code"] ?? null) === "review_required") {
            return null;
        }
        if (
            $m !== null &&
            $m["external_id"] !== null &&
            $m["source_code"] === $r["code"] &&
            $m["source_title"] === $r["title"]
        ) {
            return $m["external_id"];
        }
        try {
            return $this->synchronize($binding, $route["source"], $r, null, $a, null);
        } catch (\Throwable) {
            return null;
        }
    }
    /** @return array{linked:bool} */
    public function link(
        EntityId $reference,
        MoodleExternalId $external,
        bool $acknowledged,
        #[\SensitiveParameter] string $reason,
    ): array {
        $a = $this->authorize();
        $b = $this->binding();
        $s = $this->configuration($b);
        if (
            !$acknowledged ||
            trim($reason) === "" ||
            mb_strlen($reason) > 500 ||
            preg_match('/[\x00-\x1F\x7F]/', $reason)
        ) {
            throw new ValidationException([
                [
                    "field" => "reason",
                    "message" =>
                        "Confirm the independently verified category and give a short administrative reason.",
                ],
            ]);
        }
        if ($s === null || !$this->policy->enabled()) {
            throw new AccessDeniedException("Choose the category source and enable synchronization first.");
        }
        $r = $this->categories->reference($s["source"], $reference);
        $m = $this->categories->mapping($b->id, $s["source"], $reference);
        if ($m !== null && $m["external_id"] !== null) {
            if ($m["external_id"] !== $external->value) {
                throw new ConcurrentModificationException(
                    "This reference already has a retained Moodle category.",
                );
            }
            return ["linked" => true];
        }
        $this->synchronize($b, $s["source"], $r, $external, $a, $reason);
        return ["linked" => true];
    }
    /** @param array{id:string,code:string,title:string,active:bool} $reference */
    private function synchronize(
        MoodleBinding $binding,
        string $source,
        array $reference,
        ?MoodleExternalId $requested,
        EntityId $actor,
        ?string $reason,
    ): string {
        if (!$reference["active"]) {
            throw new AccessDeniedException("The source is inactive.");
        }
        $id = new EntityId($reference["id"]);
        $now = $this->clock->now()->getTimestamp();
        $claim = $this->transactions->run(fn() => $this->categories->claim($binding->id, $source, $id, $now));
        $map = new EntityId($claim["id"]);
        $version = (int) $claim["version"];
        try {
            if (!$this->policy->enabled()) {
                throw new AccessDeniedException("Moodle synchronization is disabled.");
            }
            if ($requested === null) {
                $this->workerAuthority();
            } else {
                $this->authorize();
            }
            if ($this->binding()->describe() !== $binding->describe()) {
                throw new ConcurrentModificationException();
            }
            $result = $this->remote->syncCategory($binding, [
                "source" => $source,
                "reference_id" => $id->value,
                "code" => strip_tags($reference["code"]),
                "title" => strip_tags($reference["title"]),
                "version" => $version,
                "category_id" => $requested === null ? ($claim["external_id"] ?? "0") : $requested->value,
            ]);
        } catch (\Throwable $e) {
            $this->categories->finish(
                $map,
                $version,
                null,
                false,
                $reference,
                "retry_pending",
                $now + 60,
                $now,
            );
            throw $e;
        }
        try {
            return $this->transactions->run(function () use (
                $binding,
                $source,
                $id,
                $reference,
                $actor,
                $requested,
                $reason,
                $result,
                $map,
                $version,
            ): string {
                $this->roles->lock();
                if (!$this->policy->enabled()) {
                    throw new AccessDeniedException("Moodle synchronization is disabled.");
                }
                $current = $requested === null ? $this->workerAuthority() : $this->authorize();
                if (
                    $current->value !== $actor->value ||
                    $this->binding()->describe() !== $binding->describe() ||
                    $this->categories->reference($source, $id) !== $reference ||
                    $result["status"] === "superseded"
                ) {
                    throw new ConcurrentModificationException("Category source or authority changed.");
                }
                $decision =
                    $reason === null
                        ? null
                        : $this->cipher->seal(
                            json_encode(
                                [
                                    "actor" => $actor->value,
                                    "at" => $this->clock->now()->format(DATE_ATOM),
                                    "reason" => $reason,
                                    "external" => $result["id"],
                                    "source" => $source,
                                    "reference" => $id->value,
                                ],
                                JSON_THROW_ON_ERROR,
                            ),
                        );
                $this->categories->finish(
                    $map,
                    $version,
                    $result["id"],
                    $result["owned"],
                    $reference,
                    "none",
                    0,
                    $this->clock->now()->getTimestamp(),
                    $decision,
                );
                $this->audit->record(
                    $actor,
                    $requested === null ? "moodle.category_synchronized" : "moodle.category_linked",
                    $id,
                );
                return $result["id"];
            });
        } catch (\Throwable $e) {
            $this->categories->finish(
                $map,
                $version,
                null,
                false,
                $reference,
                "source_changed",
                0,
                $this->clock->now()->getTimestamp(),
            );
            throw $e;
        }
    }
}
