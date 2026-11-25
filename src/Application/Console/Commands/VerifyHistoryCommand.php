<?php

declare(strict_types=1);

namespace Application\Console\Commands;

use Codefy\Framework\Application;
use Codefy\Framework\Console\ConsoleCommand;
use Domain\AcademicRecord\Repository\AcademicProjectionVerifier;
use Domain\Admissions\Repository\ApplicationProjectionVerifier;
use Domain\Student\Repository\StudentDecisionProjectionVerifier;
use Domain\Shared\ValueObject\EntityId;
use Domain\Finance\Repository\AccountProjectionVerifier;
use Domain\Finance\ValueObject\AccountId;
use Domain\Person\Repository\PersonProjectionVerifier;
use Domain\HumanResources\Repository\EmploymentProjectionVerifier;
use Domain\Shared\EventSourcing\HistoricalIntegrityVerifier;
use Domain\Shared\EventSourcing\HistoryVerificationFailedException;
use InvalidArgumentException;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/** Trusted deployment operators only; deliberately lazy to keep console registration independent of storage. */
final class VerifyHistoryCommand extends ConsoleCommand
{
    protected string $name = "sis:history:verify";
    protected string $description = "Verify protected chains, references and retry receipts in read-only batches.";
    protected array $options = [
        ["streams", null, "required", "Maximum stream chains visited (1–50).", "10"],
        ["events", null, "required", "Maximum global checks or selected-source replay events (1–2000).", "500"],
        ["cursor", null, "required", "Protected continuation from an earlier verification batch."],
        ["json", null, "none", "Emit machine-readable counts and a protected continuation cursor."],
        ['credit', null, 'required', 'Check one institutional or transfer academic credit and source; no cursor.'],
        ['waitlist-entry', null, 'required', 'Compare one retained waitlist request with queue history; no cursor.'],
        ['course', null, 'required', 'Compare one course publication history and retained references; no cursor.'],
        ['section', null, 'required', 'Compare one section publication, bindings and retained references; no cursor.'],
        ['student', null, 'required', 'Check one student’s available academic sources and projections; no cursor.'],
        ['account', null, 'required', 'Verify one financial account and its balance/posting projections; no cursor.'],
        ['person', null, 'required', 'Compare one person’s profile projections with protected history; no cursor.'],
        ['application', null, 'required', 'Compare one admissions application with protected history; no cursor.'],
        ['standing', null, 'required', 'Compare standing under one program pursuit with protected history; no cursor.'],
        ['load', null, 'required', 'Compare one retained load determination with protected history; no cursor.'],
        ['advisors', null, 'required', 'Compare one pursuit advisor assignment history; no cursor.'],
        ['requirements', null, 'required', 'Compare one pursuit degree selection and retained definitions; no cursor.'],
        ['progress-record', null, 'required',
            'Compare one recorded progress observation with protected history; no cursor.'],
        ['primary-position', null, 'required',
            'Compare one employee primary designation with protected history; no cursor.'],
        ['position', null, 'required', 'Compare one position agreement with protected history; no cursor.'],
        ['time-entry', null, 'required', 'Compare one retained time decision with its working row; no cursor.'],
    ];
    public function __construct(Application $codefy)
    {
        parent::__construct($codefy);
    }
    protected function configure(): void
    {
        parent::configure();
        $this->getDefinition()->getOption("streams")->setDefault("10");
        $this->getDefinition()->getOption("events")->setDefault("500");
    }
    public function handle(): int
    {
        $json = $this->getOptions("json") === true;
        try {
            $streams = $this->number($this->getOptions("streams"), 50);
            $events = $this->number($this->getOptions("events"), 2000);
            $cursor = $this->getOptions("cursor");
            if ($cursor !== null && (!is_string($cursor) || $cursor === "" || strlen($cursor) > 32768)) {
                throw new InvalidArgumentException();
            }
            $account = $this->getOptions('account');
            $student = $this->getOptions('student');
            $credit = $this->getOptions('credit');
            $person = $this->getOptions('person');
            $application = $this->getOptions('application');
            $standing = $this->getOptions('standing');
            $load = $this->getOptions('load');
            $advisors = $this->getOptions('advisors');
            $requirements = $this->getOptions('requirements');
            $progress = $this->getOptions('progress-record');
            $waitlist = $this->getOptions('waitlist-entry');
            $catalog = ['course' => $this->getOptions('course'), 'section' => $this->getOptions('section')];
            $employment = [
                'primary-position' => $this->getOptions('primary-position'),
                'position' => $this->getOptions('position'),
                'time-entry' => $this->getOptions('time-entry'),
            ];
            $subjects = [$account, $student, $credit, $person, $application, $standing, $load,
                $advisors, $requirements, $progress, $waitlist,
                ...array_values($employment), ...array_values($catalog)];
            if (count(array_filter($subjects, static fn(mixed $id): bool => $id !== null)) > 1) {
                throw new InvalidArgumentException();
            }
            foreach ($catalog as $kind => $reference) {
                if ($reference === null) {
                    continue;
                }
                if (!is_string($reference) || $cursor !== null || $this->input->hasParameterOption('--streams')) {
                    throw new InvalidArgumentException();
                }
                return $this->catalog($kind, new EntityId($reference), $events, $json);
            }
            if ($waitlist !== null) {
                if (!is_string($waitlist) || $cursor !== null || $this->input->hasParameterOption('--streams')) {
                    throw new InvalidArgumentException();
                }
                return $this->waitlist(new EntityId($waitlist), $events, $json);
            }
            foreach ($employment as $kind => $reference) {
                if ($reference === null) {
                    continue;
                }
                if (!is_string($reference) || $cursor !== null || $this->input->hasParameterOption('--streams')) {
                    throw new InvalidArgumentException();
                }
                return $this->employment($kind, new EntityId($reference), $events, $json);
            }
            if (
                $standing !== null || $load !== null || $advisors !== null ||
                $requirements !== null || $progress !== null
            ) {
                $reference = $standing ?? $load ?? $advisors ?? $requirements ?? $progress;
                if (!is_string($reference) || $cursor !== null || $this->input->hasParameterOption('--streams')) {
                    throw new InvalidArgumentException();
                }
                $kind = $standing !== null ? 'standing' : ($load !== null ? 'load' :
                    ($advisors !== null ? 'advisors' : ($requirements !== null ? 'requirements' : 'progress-record')));
                return $this->decision($kind, new EntityId($reference), $events, $json);
            }
            if ($application !== null) {
                if (!is_string($application) || $cursor !== null || $this->input->hasParameterOption('--streams')) {
                    throw new InvalidArgumentException();
                }
                return $this->application(new EntityId($application), $events, $json);
            }
            if ($person !== null) {
                if (!is_string($person) || $cursor !== null || $this->input->hasParameterOption('--streams')) {
                    throw new InvalidArgumentException();
                }
                return $this->person(new EntityId($person), $events, $json);
            }
            if ($credit !== null) {
                if (!is_string($credit) || $cursor !== null || $this->input->hasParameterOption('--streams')) {
                    throw new InvalidArgumentException();
                }
                return $this->credit(new EntityId($credit), $events, $json);
            }
            if ($student !== null) {
                if (
                    !is_string($student) || $account !== null || $cursor !== null ||
                    $this->input->hasParameterOption('--streams')
                ) {
                    throw new InvalidArgumentException();
                }
                return $this->student(new EntityId($student), $events, $json);
            }
            if ($account !== null) {
                if (!is_string($account) || $cursor !== null || $this->input->hasParameterOption('--streams')) {
                    throw new InvalidArgumentException();
                }
                return $this->account(new AccountId($account), $events, $json);
            }
            $result = $this->codefy
                ->make(HistoricalIntegrityVerifier::class)
                ->verify(cursor: $cursor, streamLimit: $streams, eventLimit: $events);
        } catch (InvalidArgumentException) {
            return $this->failure(
                $json,
                "invalid_input",
                "Use --streams=1..50, --events=1..2000 and a valid optional verification cursor. "
                    . 'Use one of --person=ULID, --account=ULID, --student=ULID, --credit=ULID, --application=ULID, '
                    . '--standing=PURSUIT_ULID, --load=DETERMINATION_ULID, --advisors=PURSUIT_ULID, '
                    . '--requirements=PURSUIT_ULID, --primary-position=EMPLOYEE_ULID, '
                    . '--position=POSITION_ULID, --time-entry=ENTRY_ULID, '
                    . '--course=COURSE_ULID or --section=SECTION_ULID '
                    . 'with --events and optional --json.',
                self::INVALID,
            );
        } catch (HistoryVerificationFailedException $exception) {
            return $this->failure(
                $json,
                "stream_unverified",
                "Recorded history could not be verified. No records were changed.",
                self::FAILURE,
                $exception->streamReference?->value,
            );
        } catch (Throwable) {
            return $this->failure(
                $json,
                "unavailable",
                "History verification is unavailable. Check protected storage and key configuration; " .
                    "no records were changed.",
                self::FAILURE,
            );
        }
        if ($json) {
            $this->output->writeln(
                json_encode($result->describe(), JSON_THROW_ON_ERROR),
                OutputInterface::OUTPUT_RAW,
            );
        } else {
            $this->terminalRaw(
                "Read-only check: " .
                    $result->streams .
                    " streams completed; " .
                    $result->events .
                    " events authenticated; " .
                    $result->inventoryEvents .
                    " event references checked; " .
                    $result->receipts .
                    " receipts and " .
                    $result->receiptEvents .
                    " associated events verified in this batch.",
            );
            $this->terminalRaw(
                $result->complete
                    ? "Chain, reference and receipt traversals finished for the rows visible to this pass."
                    : "More history remains. Use --json for the protected continuation cursor; rerun with --cursor.",
            );
            $this->terminalRaw(
                "This checks stored history; it does not certify projections, " .
                    "archive completeness or restore readiness.",
            );
        }
        return $result->complete ? self::SUCCESS : 3;
    }

    private function employment(string $kind, EntityId $id, int $events, bool $json): int
    {
        $verifier = $this->codefy->make(EmploymentProjectionVerifier::class);
        $result = match ($kind) {
            'primary-position' => $verifier->primaryPosition($id, $events),
            'position' => $verifier->position($id, $events),
            'time-entry' => $verifier->timeEntry($id, $events),
        };
        if ($json) {
            $this->output->writeln(json_encode($result->describe(), JSON_THROW_ON_ERROR), OutputInterface::OUTPUT_RAW);
        } else {
            $this->terminalRaw('Employment ' . $kind . ' ' . $id->value . ': ' . $result->status . '.');
            $this->terminalRaw($result->events . ' events checked; '
                . ($result->records ?? 'unknown') . ' retained records compared.');
            if ($result->issues !== []) {
                $this->terminalRaw('Components requiring review: ' . implode(', ', $result->issues) . '.');
            }
            $this->terminalRaw('No records were changed. This checks selected retained history and projections, '
                . 'not appointment eligibility, employment approval, wages, payroll, every retry receipt '
                . 'or archive completeness. '
                . 'An empty observation does not establish earlier history.');
        }
        return match ($result->status) {
            'verified' => self::SUCCESS,
            'history_gaps', 'observed_empty', 'changed', 'limited' => 3,
            default => self::FAILURE,
        };
    }

    private function decision(string $kind, EntityId $id, int $events, bool $json): int
    {
        $verifier = $this->codefy->make(StudentDecisionProjectionVerifier::class);
        $result = match ($kind) {
            'standing' => $verifier->standing($id, $events),
            'load' => $verifier->load($id, $events),
            'advisors' => $verifier->advisors($id, $events),
            'requirements' => $verifier->requirements($id, $events),
            'progress-record' => $verifier->progressRecord($id, $events),
        };
        $label = match ($kind) {
            'standing' => 'Program standing',
            'load' => 'Retained load determination',
            'advisors' => 'Advisor assignments',
            'requirements' => 'Selected degree requirements',
            'progress-record' => 'Recorded degree progress',
        };
        if ($json) {
            $this->output->writeln(json_encode($result->describe(), JSON_THROW_ON_ERROR), OutputInterface::OUTPUT_RAW);
        } else {
            $this->terminalRaw($label . ' ' . $id->value . ': ' . $result->status . '.');
            $this->terminalRaw($result->events . ' events checked; '
                . ($result->records ?? 'unknown') . ' retained records compared.');
            if ($result->issues !== []) {
                $this->terminalRaw('Components requiring review: ' . implode(', ', $result->issues) . '.');
            }
            $this->terminalRaw('No records were changed. This compares the selected retained decision and projection, '
                . 'not current policy, source registration freshness, census or graduation eligibility, '
                . 'fresh progress reproduction, every retry receipt or archive completeness. '
                . 'An empty observation is not verified prior history.');
        }
        return match ($result->status) {
            'verified' => self::SUCCESS,
            'observed_empty', 'changed', 'limited' => 3,
            default => self::FAILURE,
        };
    }

    private function catalog(string $kind, EntityId $id, int $events, bool $json): int
    {
        $verifier = $this->codefy->make(\Domain\Catalog\Repository\CatalogProjectionVerifier::class);
        $result = $kind === 'course' ? $verifier->course($id, $events) : $verifier->section($id, $events);
        if ($json) {
            $this->terminalRaw(json_encode($result->describe(), JSON_THROW_ON_ERROR));
        } else {
            $this->terminalRaw(ucfirst($kind) . ' publication ' . $id->value . ': ' . $result->status . '.');
            $this->terminalRaw($result->events . ' events checked. No records were changed.');
            if ($result->issues !== []) {
                $this->terminalRaw('Components requiring review: ' . implode(', ', $result->issues) . '.');
            }
            $this->terminalRaw('This compares recorded publications and bounded available references, '
                . 'not current draft correctness, registration eligibility, every dependent academic record, '
                . 'finances, external synchronization or archive completeness. '
                . 'No publication or offering was changed.');
        }
        return match ($result->status) {
            'verified' => self::SUCCESS,
            'changed', 'limited', 'observed_empty' => 3,
            default => self::FAILURE,
        };
    }

    private function waitlist(EntityId $entry, int $events, bool $json): int
    {
        $result = $this->codefy->make(\Domain\Registration\Waitlist\WaitlistProjectionVerifier::class)
            ->verify($entry, $events);
        if ($json) {
            $this->terminalRaw(json_encode($result->describe(), JSON_THROW_ON_ERROR));
        } else {
            $this->terminalRaw('Waitlist request ' . $entry->value . ': ' . $result->status . '.');
            $this->terminalRaw($result->events . ' events checked. No records were changed.');
            if ($result->issues !== []) {
                $this->terminalRaw('Components requiring review: ' . implode(', ', $result->issues) . '.');
            }
            $this->terminalRaw('This compares one retained request and its queue projection, '
                . 'not registration eligibility, academic or financial records, notifications, '
                . 'every retry receipt or archive completeness. No seat was allocated.');
        }
        return match ($result->status) {
            'verified' => self::SUCCESS,
            'changed', 'limited' => 3,
            default => self::FAILURE,
        };
    }

    private function credit(EntityId $credit, int $events, bool $json): int
    {
        $result = $this->codefy->make(AcademicProjectionVerifier::class)->credit($credit, $events);
        if ($json) {
            $this->output->writeln(json_encode($result->describe(), JSON_THROW_ON_ERROR), OutputInterface::OUTPUT_RAW);
        } else {
            $this->terminalRaw('Selected academic check ' . $credit->value . ': ' . $result->status . '.');
            $this->terminalRaw($result->events . ' events checked; '
                . ($result->currentRecords ?? 'unknown') . ' current academic records.');
            if ($result->issues !== []) {
                $this->terminalRaw('Unverified components: ' . implode(', ', $result->issues) . '.');
            }
            $this->terminalRaw('No records were changed. This checks one institutional/transfer academic credit, '
                . 'its surviving source/reference projections and available registration history. '
                . 'It does not certify a full archive, pursuit attribution, equivalence, finance or graduation. '
                . 'Earlier gaps, changed reads and limits require separate review.');
        }
        return match ($result->status) {
            'verified' => self::SUCCESS,
            'changed', 'limited', 'history_gaps' => 3,
            default => self::FAILURE,
        };
    }

    private function student(EntityId $student, int $events, bool $json): int
    {
        $result = $this->codefy->make(AcademicProjectionVerifier::class)->verify($student, $events);
        if ($json) {
            $this->output->writeln(json_encode($result->describe(), JSON_THROW_ON_ERROR), OutputInterface::OUTPUT_RAW);
        } else {
            $this->terminalRaw('Student academic check ' . $student->value . ': ' . $result->status . '.');
            $this->terminalRaw($result->events . ' events checked; '
                . ($result->records ?? 'unknown') . ' current records.');
            if ($result->issues !== []) {
                $this->terminalRaw('Unverified components: ' . implode(', ', $result->issues) . '.');
            }
            $this->terminalRaw('No records were changed. Earlier gaps and an empty observation do not establish '
                . 'archive completeness. Changed or limited checks must be repeated in a controlled review. '
                . 'This does not evaluate degree completion or verify pursuit attribution and finance.');
        }
        return match ($result->status) {
            'verified' => self::SUCCESS,
            'changed', 'limited', 'history_gaps', 'observed_empty' => 3,
            default => self::FAILURE,
        };
    }

    private function account(AccountId $account, int $events, bool $json): int
    {
        $result = $this->codefy->make(AccountProjectionVerifier::class)->verify($account, $events);
        if ($json) {
            $this->output->writeln(json_encode($result->describe(), JSON_THROW_ON_ERROR), OutputInterface::OUTPUT_RAW);
        } else {
            $this->terminalRaw('Financial account ' . $result->account->value . ': ' . $result->status . '.');
            $this->terminalRaw('Captured position: ' . ($result->position ?? 'unknown') . '; '
                . $result->events . ' events checked; ' . ($result->postings ?? 'unknown') . ' expected postings.');
            if ($result->issues !== []) {
                $this->terminalRaw('Unverified components: ' . implode(', ', $result->issues) . '.');
            }
            $this->terminalRaw('No records were changed. A changed or limited result is not a completed check. '
                . 'This checks one account and its projections, not external settlement or every retry receipt.');
        }
        return match ($result->status) {
            'verified' => self::SUCCESS,
            'changed', 'limited' => 3,
            default => self::FAILURE,
        };
    }
    private function person(EntityId $person, int $events, bool $json): int
    {
        $result = $this->codefy->make(PersonProjectionVerifier::class)->verify($person, $events);
        if ($json) {
            $this->output->writeln(json_encode($result->describe(), JSON_THROW_ON_ERROR), OutputInterface::OUTPUT_RAW);
        } else {
            $this->terminalRaw('Person profile ' . $result->person->value . ': ' . $result->status . '.');
            $this->terminalRaw('Captured position: ' . ($result->position ?? 'unknown') . '; '
                . $result->events . ' events checked.');
            if ($result->issues !== []) {
                $this->terminalRaw('Unverified components: ' . implode(', ', $result->issues) . '.');
            }
            if ($result->unrecordedComponents !== []) {
                $this->terminalRaw('Components without retained evidence: '
                    . implode(', ', $result->unrecordedComponents) . '.');
            }
            $this->terminalRaw('No records were changed. Earlier observations and unrecorded components do not '
                . 'establish complete history. Changed or limited checks must be repeated in a controlled review. '
                . 'This checks profile projections and linked account names, not credentials or every retry receipt.');
        }
        return match ($result->status) {
            'verified' => self::SUCCESS,
            'history_gaps', 'changed', 'limited' => 3,
            default => self::FAILURE,
        };
    }

    private function application(EntityId $application, int $events, bool $json): int
    {
        $result = $this->codefy->make(ApplicationProjectionVerifier::class)->verify($application, $events);
        if ($json) {
            $this->terminalRaw(json_encode($result->describe(), JSON_THROW_ON_ERROR));
        } else {
            $this->terminalRaw(sprintf(
                'Application check: %s; %d events checked.',
                $result->status,
                $result->events,
            ));
            if ($result->issues !== []) {
                $this->terminalRaw('Components requiring review: ' . implode(', ', $result->issues) . '.');
            }
            $this->terminalRaw('No records were changed. Earlier observations do not establish complete history. '
                . 'Changed or limited results are incomplete. This checks the selected application, retained '
                . 'answer artifacts and intake projection, not credentials, file contents, every retry receipt '
                . 'or downstream student/program projections.');
        }
        return match ($result->status) {
            'verified' => self::SUCCESS,
            'history_gaps', 'changed', 'limited' => 3,
            default => self::FAILURE,
        };
    }

    private function number(mixed $value, int $maximum): int
    {
        if (
            !is_string($value) ||
            preg_match('/^[1-9][0-9]{0,3}$/D', $value) !== 1 ||
            (int) $value > $maximum
        ) {
            throw new InvalidArgumentException();
        }
        return (int) $value;
    }
    private function failure(
        bool $json,
        string $error,
        string $message,
        int $code,
        ?string $reference = null,
    ): int {
        if ($json) {
            $this->output->writeln(
                json_encode(
                    [
                        "status" => "failed",
                        "error" => $error,
                        "message" => $message,
                        "stream_reference" => $reference,
                    ],
                    JSON_THROW_ON_ERROR,
                ),
                OutputInterface::OUTPUT_RAW,
            );
        } else {
            $this->terminalError(
                $message . ($reference === null ? "" : " Stream support reference: " . $reference),
            );
        }
        return $code;
    }
}
