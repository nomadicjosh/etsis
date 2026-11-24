<?php

$public = \Codefy\Framework\Helpers\app()->make(\Domain\Portal\Service\ProgramDiscoveryService::class)->page();
?>
<div class="campus-fragment"><?= \Codefy\Framework\Helpers\view('framework::frontend/portal/components/programs', [
        'programs' => $public['items'], 'programsHeading' => $block->setting('heading', true),
        'programsIntroduction' => $block->setting('introduction', true),
        'eyebrow' => $block->setting('eyebrow', true), 'linkLabel' => $block->setting('link_label', true),
    ])->getBody(); ?></div>
