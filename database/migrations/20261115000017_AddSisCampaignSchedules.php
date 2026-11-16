<?php

declare(strict_types=1);

use Qubus\Expressive\Migration\Migration;
use Qubus\Expressive\Schema\AlterTable;

final class AddSisCampaignSchedules extends Migration
{
    public function up(): void
    {
        $this->schema()->alter('sis_campaign_dispatches', static function (AlterTable $t): void {
            // Canonical UTC; null preserves the existing send-as-soon-as-possible meaning.
            $t->string('not_before_at', 27);
        });
        $this->schema()->alter('sis_campaign_dispatches', static function (AlterTable $t): void {
            $t->index(['status', 'not_before_at', 'updated_at', 'id'], 'scd_due');
        });
    }

    public function down(): void
    {
        throw new LogicException('Cancel scheduled authorizations before explicitly removing retained schedule data.');
    }
}
