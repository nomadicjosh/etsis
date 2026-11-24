<?php

declare(strict_types=1);

namespace Tests\Integration;

use Domain\Finance\ValueObject\AccountId;
use PHPUnit\Framework\TestCase;
use Tests\Support\WorkflowRecoveryFixture;

final class RegistrationConcurrencyTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__) . '/Support/WorkflowRecoveryFixture.php';
    }

    public function testTwoConnectionsCompeteForTheLastSeatWithoutOverbooking(): void
    {
        $this->race(false);
    }

    public function testTwoConnectionsRetryTheSameRegistrationWithoutAnotherCharge(): void
    {
        $this->race(true);
    }

    private function race(bool $sameStudent): void
    {
        $r = new WorkflowRecoveryFixture();
        try {
            $f = $r->sis;
            $one = $f->student();
            $two = $sameStudent ? $one : $f->student();
            $section = $f->section(1, 1250);
            $before = $r->facts();
            $command = ['student' => $one->id->value, 'section' => $section->id->value];
            $first = $r->start('register', $command, 'registration.created');
            $r->send($first, 'go');
            $r->await($first, 'staged');
            self::assertSame($before, $r->facts(), 'Uncommitted registration escaped its transaction.');
            $second = $r->start('register', ['student' => $two->id->value, 'section' => $section->id->value]);
            $r->send($second, 'go');
            $r->await($second, 'running');
            $r->send($first, 'continue');
            $results = [$r->result($first), $r->result($second)];
            $states = array_column($results, 'state');
            sort($states, SORT_STRING);
            self::assertSame($sameStudent ? ['accepted', 'accepted'] : ['accepted', 'rejected'], $states);
            if ($sameStudent) {
                self::assertSame($results[0]['result']['id'], $results[1]['result']['id']);
            }
            self::assertSame(1, $f->registration->sectionCount($section->id));
            foreach (
                ['sis_enrollments', 'sis_academic_credits', 'sis_registration_streams',
                'sis_registration_decisions'] as $table
            ) {
                self::assertCount(1, $f->sql->rows($table));
            }
            self::assertSame(1, $f->sql->rows('sis_registration_decisions')[0]['sequence']);
            $accounts = $f->sql->rows('sis_student_accounts');
            self::assertCount(1, $accounts);
            self::assertSame(1250, $f->accounts->get(new AccountId($accounts[0]['id']))->balance()->minor);
        } finally {
            $r->close();
        }
    }
}
