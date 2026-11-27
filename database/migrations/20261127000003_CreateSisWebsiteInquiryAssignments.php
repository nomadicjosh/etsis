<?php

declare(strict_types=1);

use Qubus\Expressive\Migration\Migration;
use Qubus\Expressive\Schema\CreateTable;

final class CreateSisWebsiteInquiryAssignments extends Migration
{
    public function up(): void
    {
        if (!$this->schema()->hasTable('sis_website_inquiry_assignments')) {
            $this->schema()->create('sis_website_inquiry_assignments', self::define(...));
        }
    }

    public static function define(CreateTable $table): void
    {
        $table->string('id', 26)->primary()->notNull();
        $table->integer('version')->notNull();
        $table->string('assignee_id', 36);
        $table->string('assignee_person_id', 26);
        $table->string('updated_at', 20)->notNull();
        $table->index(['assignee_id', 'assignee_person_id', 'id'], 'swia_assignee_page');
        $table->foreign('id', 'swia_inquiry_fk')->references('sis_website_form_inbox', 'id')->onDelete('CASCADE');
        // Unavailable accounts remain explicitly assigned until an authorized colleague clears/reassigns them.
    }

    public function down(): void
    {
        throw new LogicException(
            'Delete or deliberately dispose of inquiry assignments before removing their storage.'
        );
    }
}
