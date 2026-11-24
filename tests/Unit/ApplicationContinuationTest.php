<?php

declare(strict_types=1);

namespace Tests\Unit;

use Application\Http\Security\ApplicationContinuation;
use Domain\Shared\ValueObject\EntityId;
use PHPUnit\Framework\TestCase;

final class ApplicationContinuationTest extends TestCase
{
    public function testOnlyPublicTypedSelectionsSurviveAnExplicitApplyContinuation(): void
    {
        $program = EntityId::generate()->value;
        $term = EntityId::generate()->value;
        $input = ['continue' => 'apply', 'program' => $program, 'term' => $term,
            'return' => 'https://example.invalid/untrusted', 'person_id' => EntityId::generate()->value];
        self::assertSame(
            '?continue=apply&program=' . $program . '&term=' . $term,
            ApplicationContinuation::query($input)
        );
        self::assertSame(
            '/portal/apply/?program=' . $program . '&term=' . $term,
            ApplicationContinuation::target($input)
        );
        self::assertSame('', ApplicationContinuation::query(['program' => $program]));
        foreach (['https://example.invalid/', '../account', ['injected'], '', '<script>', 42] as $invalid) {
            self::assertSame('?continue=apply', ApplicationContinuation::query([
                'continue' => 'apply', 'program' => $invalid, 'term' => $invalid,
            ]));
        }
    }
}
