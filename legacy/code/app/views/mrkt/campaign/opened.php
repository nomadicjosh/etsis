<?php if ( ! defined('BASE_PATH') ) exit('No direct script access allowed');
/**
 * Marketing Open Report View
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
    <li><a href="<?=get_base_url();?>mrkt/campaign/<?=_h($cpgn->id);?>/report/" class="glyphicons charts"><i></i> <?=_h($cpgn->subject);?></a></li>
	<li class="divider"></li>
	<li><?=_h($cpgn->subject);?> <?=_t('Open Report');?></li>
</ul>

<h3><?=_h($cpgn->subject);?> <?=_t('Open Report');?></h3>
<div class="innerLR">
    
    <?=_etsis_flash()->showMessage();?>
    
    <?php jstree_sidebar_menu($screen); ?>

	<!-- Widget -->
	<div class="widget widget-heading-simple widget-body-gray <?=($app->hook->has_filter('sidebar_menu')) ? 'col-md-12' : 'col-md-10';?>">
		<div class="widget-body">
            
            <!-- Row -->
            <div class="row">

                <!-- Column -->
                <div class="col-md-12">

                    <div id="dayopens"></div>

                </div>
                <!-- // Column END -->

            </div>
            <!-- // Row END -->

            <div class="separator bottom"></div>
            
            <!-- Row -->
            <div class="row">

                <!-- Column -->
                <div class="col-md-12">

                    <div id="houropens"></div>

                </div>
                <!-- // Column END -->

            </div>
            <!-- // Row END -->

            <div class="separator bottom"></div>
            
			<!-- Table -->
			<table class="dynamicTable table table-striped table-bordered table-condensed table-white">
                
                <thead>
                    <tr>
                        <th class="text-center"><?= _t('Recipient'); ?></th>
                        <th class="text-center"><?= _t('First Viewed'); ?></th>
                        <th class="text-center"><?= _t('Response Time'); ?></th>
                        <th class="text-center"><?= _t('Times Viewed'); ?></th>
                        <th class="text-center"><?= _t('Last Viewed'); ?></th>
                    </tr>
                </thead>
				
				<!-- Table body -->
				<tbody>
                    <?php foreach ($opens as $opened) : ?>
                        <tr class="gradeX">
                            <td class="text-center"><a href="<?=get_base_url();?>nae/<?=_h((int)$opened->sid);?>/"><?= _h($opened->email); ?></a></td>
                            <td class="text-center"><?= \Jenssegers\Date\Date::parse(_h($opened->first_open))->format('M. d, Y h:i A'); ?></td>
                            <td class="text-center"><?= etsis_response_time(_h((int)$opened->cid), _h((int)$opened->sid), _h($opened->first_open)); ?></td>
                            <td class="text-center"><span class="label label-inverse" style="font-size:1em;font-weight: bold;"><?= _h((int)$opened->viewed); ?></span></td>
                            <td class="text-center"><?= \Jenssegers\Date\Date::parse(_h($opened->LastUpdate))->format('M. d, Y h:i A'); ?></td>
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
	
<script>
    var did = '<?=_h($cpgn->id);?>';
</script>

		</div>
		<!-- // Content END -->
<?php $app->view->stop(); ?>