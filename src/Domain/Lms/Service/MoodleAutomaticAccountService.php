<?php

declare(strict_types=1);

namespace Domain\Lms\Service;

use Domain\Access\Repository\AccessRepository;
use Domain\Admissions\Repository\AdmissionsRepository;
use Domain\Audit\Repository\AuditTrail;
use Domain\Communications\Service\MessageCipher;
use Domain\Lms\Repository\MoodleAccountRequestRepository;
use Domain\Lms\Repository\MoodleAccountDefaults;
use Domain\Lms\Repository\MoodleBindingRepository;
use Domain\Lms\Repository\MoodlePersonLinkRepository;
use Domain\Lms\Repository\MoodleSectionRepository;
use Domain\Lms\ValueObject\{MoodleAccountDraft, MoodleAccountRequest, MoodleBinding};
use Domain\Shared\Exception\{AccessDeniedException, ConcurrentModificationException, ValidationException};
use Domain\Shared\Persistence\UnitOfWork;
use Domain\Shared\Service\Clock;
use Domain\Shared\ValueObject\EntityId;

final readonly class MoodleAutomaticAccountService
{
    public function __construct(
        private MoodleAutomationPolicy $automation,
        private MoodleAccountRequestPolicy $policy,
        private MoodleAccountRequestRepository $requests,
        private MoodleAccountDefaults $defaults,
        private MoodleBindingRepository $bindings,
        private MoodlePersonLinkRepository $links,
        private MoodleSectionRepository $sections,
        private AdmissionsRepository $people,
        private AccessRepository $roles,
        private MoodleAccountProvisioner $remote,
        private MoodleAccountProfile $profiles,
        private MessageCipher $cipher,
        private UnitOfWork $transactions,
        private AuditTrail $audit,
        private Clock $clock,
    ) {
    }
    public function ensure(EntityId $section, EntityId $link, EntityId $person): string
    {
        if (!$this->automation->automaticAccountsEnabled() || !$this->policy->processingEnabled()) {
            return "account_setup_required";
        }
        $binding = $this->bindings->current() ?? throw new AccessDeniedException();
        if ($this->links->forPerson($binding->id, $person) !== null) {
            return "none";
        }
        $existing = $this->requests->forPerson($binding->id, $person);
        if ($existing !== null) {
            return in_array(
                $this->requests->processing($existing->id)["state"],
                ["queued", "processing"],
                true,
            )
            ? "account_pending"
            : "account_setup_required";
        }
        $actor = $this->automation->actor();
        $source = [
            "source" => "section",
            "source_section" => $section->value,
            "source_link" => $link->value,
            "person_id" => $person->value,
            "actor_id" => $actor->value,
        ];
        try {
            $snapshot = $this->transactions->run(function () use ($source, $binding, $person): array {
                $this->authorize($source, $binding);
                $p = $this->people->person($person);
                $d = $this->defaults->forOwner($p->ownerUserId);
                $draft = new MoodleAccountDraft(
                    strtolower($d["username"]),
                    $d["email"],
                    $this->automation->signIn(),
                );
                if (
                    $p->givenName === "" ||
                    $p->familyName === "" ||
                    mb_strlen($p->givenName) > 100 ||
                    mb_strlen($p->familyName) > 100 ||
                    strip_tags($p->givenName) !== $p->givenName ||
                    strip_tags($p->familyName) !== $p->familyName
                ) {
                    throw new AccessDeniedException();
                }
                return ["person" => $p, "draft" => $draft, "revision" => $this->profiles->revision($p)];
            });
        } catch (ValidationException | AccessDeniedException) {
            return "account_setup_required";
        }
        // Read policy metadata only; no identity data or locks cross this call.
        $remote = $this->remote->policy($binding);
        $draft = $snapshot["draft"];
        if (!$remote->permits($draft->signIn)) {
            return "account_setup_required";
        }
        return $this->transactions->run(function () use (
            $source,
            $binding,
            $person,
            $actor,
            $snapshot,
            $remote,
            $draft,
        ): string {
            $this->authorize($source, $binding);
            if ($this->links->forPerson($binding->id, $person) !== null) {
                return "none";
            }
            if ($this->requests->forPerson($binding->id, $person) !== null) {
                return "account_pending";
            }
            $p = $this->people->person($person);
            if ($this->profiles->revision($p) !== $snapshot["revision"]) {
                throw new ConcurrentModificationException("Account attributes changed.");
            }
            $id = EntityId::generate();
            $data = $source + [
                "kind" => "moodle-account-request",
                "id" => $id->value,
                "binding_id" => $binding->id->value,
                "binding_hash" => hash("sha256", json_encode($binding->describe(), JSON_THROW_ON_ERROR)),
                "person_revision" => $snapshot["revision"],
                "firstname" => $p->givenName,
                "lastname" => $p->familyName,
                "username" => $draft->username,
                "email" => $draft->email,
                "sign_in" => $draft->signIn,
                "policy_hash" => $remote->hash,
                "reason" => "Institutional first-use provisioning for a current section participant.",
            ];
            $encoded = json_encode($data, JSON_THROW_ON_ERROR);
            $at = $this->clock->now()->setTimezone(new \DateTimeZone("UTC"))->format("Y-m-d\TH:i:s\Z");
            $this->requests->add(
                new MoodleAccountRequest($id, $binding->id, $person, $actor, $at, hash("sha256", $encoded)),
                $this->cipher->seal($encoded),
            );
            $this->requests->enqueue($id, $at);
            $this->audit->record($actor, "moodle.automatic_account_requested", $person);
            return "account_pending";
        });
    }
    /** Reauthorize an automatically queued request before and after external creation.
     * @param array<string,string> $data */
    public function validate(array $data, MoodleBinding $binding): void
    {
        if (($data["source"] ?? null) !== "section") {
            return;
        }
        $this->authorize($data, $binding);
        if ($data["sign_in"] !== $this->automation->signIn()) {
            throw new AccessDeniedException("Institutional sign-in policy changed.");
        }
    }
    /** @param array<string,string> $data */
    private function authorize(array $data, MoodleBinding $binding): void
    {
        if (
            !$this->automation->automaticAccountsEnabled() ||
            !$this->policy->processingEnabled() ||
            $data["actor_id"] !== $this->automation->actor()->value ||
            $this->bindings->current()?->describe() !== $binding->describe()
        ) {
            throw new AccessDeniedException();
        }
        $actor = new EntityId($data["actor_id"]);
        $person = new EntityId($data["person_id"]);
        $this->roles->lock();
        $this->roles->requireUser($actor);
        $this->people->lockPerson($person);
        $p = $this->people->person($person);
        $owner = $p->ownerUserId;
        $permissions = [
            "sis:moodle:manage",
            "sis:catalog:manage",
            "sis:student:read",
            "sis:moodle:create-person",
            "sis:person:manage",
        ];
        foreach ($permissions as $permission) {
            if (!$this->roles->allows($actor->value, $permission)) {
                throw new AccessDeniedException();
            }
        }
        if (
            $owner === null ||
            !$this->roles->allows(
                $actor->value,
                $owner->value === $actor->value ? "admin:profile" : "admin:edit:user",
            ) ||
            ($this->roles->isSuper($owner->value) && !$this->roles->isSuper($actor->value))
        ) {
            throw new AccessDeniedException();
        }
        $section = new EntityId($data["source_section"]);
        $link = new EntityId($data["source_link"]);
        if (
            ($this->sections->link($binding->id, $section)["id"] ?? null) !== $link->value ||
            !$this->sections->desired($section, $link, $person)["account_eligible"]
        ) {
            throw new AccessDeniedException("Section eligibility changed.");
        }
    }
}
