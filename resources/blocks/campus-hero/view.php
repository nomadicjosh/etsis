<div class="campus-fragment"><?= \Codefy\Framework\Helpers\view('framework::frontend/portal/components/hero', [
        'headline' => $block->setting('headline', true), 'introduction' => $block->setting('introduction', true),
    ])->getBody(); ?></div>
