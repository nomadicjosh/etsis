<?php if ( ! defined('BASE_PATH') ) exit('No direct script access allowed');
/**
 * Marketing Bounced Report View
 *
 * @license GPLv3
 * 
 * @since       6.3.0
 * @package     eduTrac SIS
 * @author      Joshua Parker <joshmac3@icloud.com>
 */
$app = \Liten\Liten::getInstance();
$app->view->extend('_layouts/dashboard');
$app->view->block('dashboard');
$screen = 'mrkt';
?>

<ul class="breadcrumb">
	<li><?=_t( 'You are here' );?></li>
	<li><a href="<?=get_base_url();?>dashboard/" class="glyphicons dashboard"><i></i> <?=_t( 'Dashboard' );?></a></li>
	<li class="divider"></li>
    <li><a href="<?=get_base_url();?>mrkt/" class="glyphicons inbox"><i></i> <?=_t( 'Campaigns' );?></a></li>
	<li class="divider"></li>
    <li><a href="<?=get_base_url();?>mrkt/campaign/<?=_h($cpgn->id);?>/report/" class="glyphicons inbox"><i></i> <?=_h($cpgn->subject);?></a></li>
	<li class="divider"></li>
	<li><?=_h($cpgn->subject);?> <?= _t('Bounce Report'); ?></li>
</ul>

<h3><?=_h($cpgn->subject);?> <?= _t('Bounce Report'); ?></h3>
<div class="innerLR">
    
    <?=_etsis_flash()->showMessage();?>
    
    <?php jstree_sidebar_menu($screen); ?>

	<!-- Widget -->
	<div class="widget widget-heading-simple widget-body-gray <?=($app->hook->has_filter('sidebar_menu')) ? 'col-md-12' : 'col-md-10';?>">
		<div class="widget-body">
            
			<!-- Table -->
			<table class="dynamicTable table table-striped table-bordered table-condensed table-white">
                
                <thead>
                    <tr>
                        <th class="text-center"><?= _t('URL'); ?></th>
                        <th class="text-center"><?= _t('Recipient'); ?></th>
                        <th class="text-center"><?= _t('First Clicked'); ?></th>
                        <th class="text-center"><?= _t('Times Clicked'); ?></th>
                        <th class="text-center"><?= _t('Last Clicked'); ?></th>
                    </tr>
                </thead>
				
				<!-- Table body -->
				<tbody>
                    <?php foreach ($clicks as $clicked) : ?>
                        <tr class="gradeX">
                            <td class="text-center"><?= etsis_url_shorten(_h($clicked->url)); ?></td>
                            <td class="text-center"><a href="<?=get_base_url();?>nae/<?=_h((int)$clicked->sid);?>/"><?= _h($clicked->email); ?></a></td>
                            <td class="text-center"><?= \Jenssegers\Date\Date::parse(_h($clicked->addDate))->format('M. d, Y h:i A'); ?></td>
                            <td class="text-center"><span class="label label-inverse" style="font-size:1em;font-weight: bold;"><?= _h($clicked->clicked); ?></span></td>
                            <td class="text-center"><?= \Jenssegers\Date\Date::parse(_h($clicked->LastUpdate))->format('M. d, Y h:i A'); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
				<!-- // Table body END -->
				
			</table>
			<!-- // Table END -->
            
            <div class="separator bottom"></div>
            
            <!-- Form actions -->
            <div class="form-actions">
                <button type="button" class="btn btn-icon btn-primary glyphicons circle_minus" onclick="window.location='<?=get_base_url();?>mrkt/campaign/<?=_h($cpgn->id);?>/report/'"><i></i><?=_t( 'Cancel' );?></button>
            </div>
            <!-- // Form actions END -->
			
		</div>
	</div>
	
	<!-- // Widget END -->
	
</div>

		</div>
		<!-- // Content END -->
<?php $app->view->stop(); ?>