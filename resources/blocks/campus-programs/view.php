<?php

$public = \Codefy\Framework\Helpers\app()->make(\Domain\Portal\Service\CampusService::class)->publicData();
?>
<div class="campus-fragment"><?= \Codefy\Framework\Helpers\view('framework::frontend/portal/components/programs', ['programs' => $public['programs']])->getBody(); ?></div>
