<?php

$catalog = \Codefy\Framework\Helpers\app()->make(\Domain\Portal\Service\CampusService::class)->catalog(null);
?>
<div class="campus-fragment"><?= \Codefy\Framework\Helpers\view('framework::frontend/portal/components/courses', [
        'items' => $catalog['items'], 'heading' => $block->setting('heading', true),
    ])->getBody(); ?></div>
