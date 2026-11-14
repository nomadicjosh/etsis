<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/Support/SisFixture.php';

use Domain\Catalog\Service\ProgramSpecializationService;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\RecordNotFoundException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\Repository\SqlCatalogRevisionRepository;
use Infrastructure\Persistence\Repository\SqlProgramSpecializationRepository;
use PHPUnit\Framework\TestCase;

final class ProgramSpecializationTest extends TestCase
{
    private function service(
        SisFixture $f,
        ?\Domain\Audit\Repository\AuditTrail $audit = null,
    ): ProgramSpecializationService {
        return new ProgramSpecializationService(
            new SqlProgramSpecializationRepository($f->sql),
            new SqlCatalogRevisionRepository($f->sql),
            $f->access,
            $f->transactions,
            $audit ?? $f->audit,
        );
    }
    public function testProgramOwnedReferencesEditRetireAndRemainSeparateFromAcademicDecisions(): void
    {
        $f = new SisFixture();
        $service = $this->service($f);
        $before = $f->sql->rows('sis_history_events');
        $row = $service->save(
            $f->program,
            null,
            null,
            ' data.science ',
            ' Data Science ',
            " Fictional description.\nSecond paragraph. ",
            true,
        );
        self::assertSame('DATA.SCIENCE', $row['code']);
        self::assertSame('Data Science', $row['title']);
        self::assertSame(1, $row['revision']);
        $saved = $service->save(
            $f->program,
            new EntityId($row['id']),
            1,
            $row['code'],
            'Data Science <b>fictional</b>',
            null,
            false,
        );
        self::assertSame(2, $saved['revision']);
        self::assertFalse($saved['active']);
        self::assertSame($saved, $service->get($f->program, new EntityId($row['id'])));
        self::assertFalse($service->page($f->program)['items'][0]['active']);
        self::assertSame($before, $f->sql->rows('sis_history_events'));
        self::assertSame([], $f->sql->rows('sis_student_programs'));
        self::assertSame([], $f->sql->rows('sis_student_requirements'));
        self::assertCount(2, $f->sql->rows('sis_audit', ['subject_id' => $row['id']]));
        $this->expectException(ConcurrentModificationException::class);
        $service->save(
            $f->program,
            new EntityId($row['id']),
            1,
            'DATA.SCIENCE',
            'Stale overwrite',
            null,
            true,
        );
    }
    public function testCodesAreUniqueInsideAProgramAndForeignProgramReferencesAreRejected(): void
    {
        $f = new SisFixture();
        $service = $this->service($f);
        $other = EntityId::generate();
        $f->sql->insert('sis_programs', [
            'id' => $other->value,
            'code' => 'OTHER',
            'title' => 'Other program',
        ]);
        $row = $service->save($f->program, null, null, 'ART', 'Art', null, true);
        self::assertSame(
            'ART',
            $service->save($other, null, null, 'ART', 'Art in another program', null, true)['code'],
        );
        try {
            $service->save($f->program, null, null, 'ART', 'Duplicate', null, true);
            self::fail('A duplicate code was accepted.');
        } catch (ValidationException) {
            self::assertTrue(true);
        }
        foreach (['read', 'write'] as $action) {
            try {
                if ($action === 'read') {
                    $service->get($other, new EntityId($row['id']));
                } else {
                    $service->save($other, new EntityId($row['id']), 1, 'ART', 'Moved', null, true);
                }
                self::fail('A foreign program reference was accepted.');
            } catch (RecordNotFoundException) {
                self::assertTrue(true);
            }
        }
        self::assertSame(1, $service->get($f->program, new EntityId($row['id']))['revision']);
    }
    public function testBoundedSearchEscapesWildcardsAndOmitsDescriptionsFromListPages(): void
    {
        $f = new SisFixture();
        $s = $this->service($f);
        for ($i = 0; $i < 23; $i++) {
            $s->save(
                $f->program,
                null,
                null,
                'FOCUS.' . $i,
                'Fictional Focus ' . $i . ' _ literal',
                'Long private working draft description.',
                true,
            );
        }
        $first = $s->page($f->program);
        self::assertCount(20, $first['items']);
        self::assertArrayNotHasKey('description', $first['items'][0]);
        $second = $s->page($f->program, '', new EntityId($first['next_after']));
        self::assertCount(3, $second['items']);
        self::assertNull($second['next_after']);
        self::assertSame([], $s->page($f->program, '%')['items']);
        self::assertCount(20, $s->page($f->program, '_')['items']);
        self::assertSame(
            [],
            array_intersect(array_column($first['items'], 'id'), array_column($second['items'], 'id')),
        );
    }
    public function testPermissionAndAuditFailurePreventAllWrites(): void
    {
        $f = new SisFixture();
        $service = $this->service($f);
        $f->access->staff = false;
        $f->access->grants = ['sis:student:read'];
        try {
            $service->page($f->program);
            self::fail('Student read allowed catalog setup.');
        } catch (AccessDeniedException) {
            self::assertTrue(true);
        }
        try {
            $service->save($f->program, null, null, 'NO', 'Denied', null, true);
            self::fail('Student read allowed catalog save.');
        } catch (AccessDeniedException) {
            self::assertTrue(true);
        }
        $f->access->staff = true;
        $audit = new class implements \Domain\Audit\Repository\AuditTrail {
            public function record(EntityId $actor, string $action, EntityId $subject): void
            {
                throw new RuntimeException('Fictional audit failure.');
            }
            public function forSubject(EntityId $subject): array
            {
                return [];
            }
        };
        try {
            $this->service($f, $audit)->save($f->program, null, null, 'ROLLBACK', 'Rollback', null, true);
            self::fail('A failed audit saved the reference.');
        } catch (RuntimeException) {
            self::assertSame([], $service->page($f->program)['items']);
        }
    }
    public function testInvalidOrIncompleteCommandsCannotCreateReferences(): void
    {
        $f = new SisFixture();
        $service = $this->service($f);
        foreach (
            [
                ['BAD/CODE', 'Title', null],
                ['OK', 'Title' . "\0", null],
                ['OK', 'Title', str_repeat('a', 10001)],
            ]
            as [$code, $title, $description]
        ) {
            try {
                $service->save($f->program, null, null, $code, $title, $description, true);
                self::fail('Invalid specialization accepted.');
            } catch (InvalidArgumentException) {
                self::assertSame([], $service->page($f->program)['items']);
            }
        }
        $this->expectException(InvalidArgumentException::class);
        $service->save($f->program, null, 1, 'OK', 'Title', null, true);
    }
}
