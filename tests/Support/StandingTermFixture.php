<?php

declare(strict_types=1);

namespace Tests\Support;

// phpcs:disable PSR1.Files.SideEffects -- Isolated fictional workflow dependencies.
require_once __DIR__ . '/StandingProposalFixture.php';

use Domain\Shared\ValueObject\EntityId;

final class StandingTermFixture
{
    public StandingProposalFixture $base;
    public EntityId $other;
    public array $first;
    public array $second;

    public function __construct()
    {
        $this->base = $f = new StandingProposalFixture(false);
        $f->rules['bands'][1]['minimum_gpa_milli'] = 3000;
        $f->rules['gpa_basis'] = 'term';
        $this->first = $f->work('C');
        $this->second = $f->work('A');
        $s = $f->sis;
        $this->other = EntityId::generate();
        $term = $s->sql->one('sis_terms', ['id' => $s->term->value]);
        $s->sql->insert('sis_terms', array_replace($term, ['id' => $this->other->value, 'code' => '26/SP',
            'starts_on' => '2026-01-04', 'ends_on' => '2026-05-20', 'add_drop_ends_on' => '2026-01-20']));
        $this->move($this->second, $this->other);
        $f->publish();
    }

    public function move(array $work, EntityId $term): void
    {
        $s = $this->base->sis;
        $credit = $s->sql->one('sis_academic_credits', ['source_id' => $work['enrollment']->value]);
        $service = $s->academicDates();
        $review = $service->reviewDetails(
            $this->base->student,
            $work['section'],
            $work['enrollment'],
            null,
            null,
            $term,
            (int) $credit['version'],
            'Fictional verified posting correction.',
            '2026-08-10'
        );
        $service->commitDetails($this->base->student, $work['section'], $review['review_token']);
    }

    public function repeat(array $work, string $code, EntityId $term): array
    {
        $b = $this->base;
        $s = $b->sis;
        $source = $s->registration->section($work['section']);
        $section = new \Domain\Registration\Section(
            EntityId::generate(),
            $source->courseId,
            $s->term,
            $s->access->actor,
            'REPEAT-' . bin2hex(random_bytes(3)),
            20,
            true,
            true,
            $source->fee
        );
        $s->registration->addSection($section);
        $enrollment = $s->service->register($b->student, $section->id)->id;
        $choices = array_column(
            $s->academicCredits()->choices($b->student, $section->id, $enrollment)['items'],
            'id',
            'code'
        );
        $review = $s->academicCredits()->review(
            $b->student,
            $section->id,
            $enrollment,
            new EntityId($choices[$code]),
            0
        );
        $s->academicCredits()->commit($b->student, $section->id, $review['review_token']);
        $result = ['section' => $section->id, 'enrollment' => $enrollment];
        if ($term->value !== $s->term->value) {
            $this->move($result, $term);
        }
        return $result;
    }

    public function propose(?EntityId $term = null): array
    {
        $f = $this->base;
        return $f->sis->standingService()->propose($f->student, $f->pursuit, $term);
    }

    public function accept(array $proposal): array
    {
        $f = $this->base;
        $service = $f->sis->standingService();
        $review = $service->review(
            $f->student,
            $f->pursuit,
            [$f->row($proposal)],
            $service->view($f->student, $f->pursuit)['revision'],
            'Fictional reviewed term standing.',
            proposal: $proposal['proposal']
        );
        $service->commit(
            $f->student,
            $f->pursuit,
            new EntityId($review['operation_id']),
            $review['rows'],
            $review['revision'],
            $review['reason'],
            $review['review_token'],
            proposal: $review['proposal']
        );
        return $review;
    }

    public function facts(): array
    {
        return [$this->base->facts(), ...array_map($this->base->sis->sql->rows(...), [
            'sis_terms', 'sis_credit_posting_terms',
        ])];
    }
}
