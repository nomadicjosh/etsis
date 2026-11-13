<?php

declare(strict_types=1);

use Domain\DegreeAudit\ValueObject\CourseGroupDefinition;
use Domain\DegreeAudit\ValueObject\CourseGroupDefinitionV2;
use Domain\Rules\SavedCourseRule;
use Infrastructure\EventSourcing\ProtectedEventCodec;
use PHPUnit\Framework\TestCase;

final class SavedCourseRuleTest extends TestCase
{
    private function snapshot(): array
    {
        $definition = ['tree'=>['condition'=>'AND','rules'=>[
            ['id'=>'course_level_number','operator'=>'greater','value'=>200],
        ]], 'references'=>[]];
        return [
            'id'=>'01K00000000000000000000001', 'rule_id'=>'01K00000000000000000000001',
            'code'=>'DA.UPPER', 'title'=>'Fictional upper-level work', 'revision'=>1,
            'created_at'=>'2026-11-13T12:00:00.000000Z', 'definition'=>$definition,
            'definition_hash'=>hash('sha256',json_encode($definition,JSON_THROW_ON_ERROR)),
        ];
    }

    public function testSnapshotSurvivesJournalCanonicalizationWithoutAcceptingChangedContents(): void
    {
        $saved = $this->snapshot();
        $canonical = json_decode(ProtectedEventCodec::canonical($saved),true,32,JSON_THROW_ON_ERROR);
        self::assertEquals(new SavedCourseRule($saved),new SavedCourseRule($canonical));
        $canonical['definition']['tree']['rules'][0]['value']=100;
        $this->expectException(InvalidArgumentException::class);
        new SavedCourseRule($canonical);
    }

    public function testDynamicCountAndCreditRulesNeedNoHardcodedCoursesButCannotMeanTakeAll(): void
    {
        $d = CourseGroupDefinitionV2::blank('Fictional group')->definition;
        $d['requirement_type']='courses';
        $d['min_courses']=2;
        $d['rules']=[[
            'id'=>'01K00000000000000000000002','mode'=>'choose','minimum_courses'=>2,
            'course_ids'=>[],'eligibility'=>$this->snapshot(),
        ]];
        new CourseGroupDefinitionV2($d)->assertPublishable();
        $d['rules'][0]['mode']='credits';
        $d['rules'][0]['minimum_courses']=0;
        $d['rules'][0]['minimum_credits_milli']=6000;
        new CourseGroupDefinitionV2($d)->assertPublishable();
        self::assertTrue(true);
        $d['rules'][0]['mode']='all';
        unset($d['rules'][0]['minimum_credits_milli']);
        $this->expectException(InvalidArgumentException::class);
        new CourseGroupDefinitionV2($d);
    }

    public function testEarlierGroupGrammarCannotAcquireDynamicRulesByReinterpretation(): void
    {
        $d = CourseGroupDefinition::blank('Fictional earlier group')->definition;
        $d['min_credits_milli']=3000;
        $d['rules']=[[
            'id'=>'01K00000000000000000000002','mode'=>'pool','minimum_courses'=>0,
            'course_ids'=>[],'eligibility'=>$this->snapshot(),
        ]];
        $this->expectException(InvalidArgumentException::class);
        new CourseGroupDefinition($d);
    }
}
