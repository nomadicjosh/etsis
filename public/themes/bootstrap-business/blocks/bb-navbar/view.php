<?php

use function Application\Shared\Helpers\menu_items;
use function Codefy\Framework\Helpers\config;
use function Codefy\Framework\Helpers\view;

//phpcs:disable
?>
<nav class="navbar navbar-expand-lg navbar-dark bg-dark">
    <div class="container px-5">
        <a class="navbar-brand" href="<?=phpb_full_url(urlRelativeToBaseUrl: '');?>"><?=config()->string(key: 'app.name');?></a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarSupportedContent" aria-controls="navbarSupportedContent" aria-expanded="false" aria-label="Toggle navigation"><span class="navbar-toggler-icon"></span></button>
        <div class="collapse navbar-collapse" id="navbarSupportedContent">
            <ul class="navbar-nav ms-auto mb-2 mb-lg-0">
                <?= view('framework::shared/site-navigation', [
                    'items' => menu_items('primary'), 'prefix' => 'website-primary',
                ])->getBody() ?>
            </ul>
        </div>
    </div>
</nav>
