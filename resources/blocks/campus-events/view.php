<?php

$events = \Codefy\Framework\Helpers\app()->make(\Domain\Scheduling\Service\PublicEventService::class)->upcoming();
?>
<div class="campus-fragment"><?= \Codefy\Framework\Helpers\view('framework::frontend/portal/components/event-cards', [
        'events' => $events, 'eventsHeading' => $block->setting('heading', true),
        'eyebrow' => $block->setting('eyebrow', true), 'linkLabel' => $block->setting('link_label', true),
    ])->getBody(); ?></div>
