<?php if ( ! defined('BASE_PATH') ) exit('No direct script access allowed');
/**
 * Marketing Report View
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
	<li><?=_h($cpgn->subject);?> <?=_t('Report');?></li>
</ul>

<h3><?=_h($cpgn->subject);?> <?=_t('Report');?></h3>
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
						
						<div id="domains"></div>
						
					</div>
					<!-- // Column END -->
					
				</div>
				<!-- // Row END -->
            
			<!-- Table -->
			<table class="dynamicTable table table-striped table-bordered table-condensed table-white">
				
				<!-- Table body -->
				<tbody>
                    <tr class="gradeX">
                        <td class="text-center"><strong><?=_t('Subject');?></strong></td>
                        <td class="text-center"><?=_h($cpgn->subject);?></td>
                    </tr>

                    <tr class="gradeX">
                        <td class="text-center"><strong><?=_t('Date Entered');?></strong></td>
                        <td class="text-center"><?=\Jenssegers\Date\Date::parse(_h($cpgn->addDate))->format('M. d, Y h:i A');?></td>
                    </tr>

                    <tr class="gradeX">
                        <td class="text-center"><strong><?=_t('Date Sent');?></strong></td>
                        <td class="text-center"><?=\Jenssegers\Date\Date::parse(_h($cpgn->sendstart))->format('M. d, Y h:i A');?></td>
                    </tr>

                    <tr class="gradeX">
                        <td class="text-center"><strong><?=_t('Date Finished');?></strong></td>
                        <td class="text-center"><?=\Jenssegers\Date\Date::parse(_h($cpgn->sendfinish))->format('M. d, Y h:i A');?></td>
                    </tr>

                    <tr class="gradeX">
                        <td class="text-center"><strong><?=_t('Sent');?></strong></td>
                        <td class="text-center"><span class="label label-inverse" style="font-size:1em;font-weight: bold;"><?=_h((int)$cpgn->recipients);?></span></td>
                    </tr>

                    <tr class="gradeX">
                        <td class="text-center"><strong><?=_t('Bounced');?></strong></td>
                        <!--<td class="text-center"><span class="label label-inverse" style="font-size:1em;font-weight: bold;"><?=(_h((int)$cpgn->bounces) > 0 ? '<a style="color:#F4D03F" href="'.get_base_url().'mrkt/campaign'.'/'._h((int)$cpgn->id).'/report/bounced/">'._h($unique_bounces).'</a>' : 0);?></span></td>-->
                        <td class="text-center"><span class="label label-inverse" style="font-size:1em;font-weight: bold;"><?=_h($unique_bounces);?></span></td>
                    </tr>

                    <tr class="gradeX">
                        <td class="text-center"><strong><?=_t('% Bounced');?></strong></td>
                        <td class="text-center"><span class="label label-inverse" style="font-size:1em;font-weight: bold;"><?=($unique_bounces > 0 ? percent($unique_bounces, _h((int)$cpgn->recipients)) : 0);?>%</span></td>
                    </tr>

                    <tr class="gradeX">
                        <td class="text-center"><strong><?=_t('Opened');?></strong></td>
                        <td class="text-center"><span class="label label-inverse" style="font-size:1em;font-weight: bold;"><?=(_h((int)$cpgn->viewed) > 0 ? '<a style="color:#F4D03F" href="'.get_base_url().'mrkt/campaign'.'/'._h((int)$cpgn->id).'/report/opened/">'._h($unique_opens).'</a>' : 0);?></span></td>
                    </tr>

                    <tr class="gradeX">
                        <td class="text-center"><strong><?=_t('% Opened');?></strong></td>
                        <td class="text-center"><span class="label label-inverse" style="font-size:1em;font-weight: bold;"><?=($opened > 0 ? percent($unique_opens, _h((int)$cpgn->recipients)) : 0);?>%</span></td>
                    </tr>

                    <tr class="gradeX">
                        <td class="text-center"><strong><?=_t('Clicked');?></strong></td>
                        <td class="text-center"><span class="label label-inverse" style="font-size:1em;font-weight: bold;"><?=(_h($clicks) > 0 ? '<a style="color:#F4D03F" href="'.get_base_url().'mrkt/campaign'.'/'._h((int)$cpgn->id).'/report/clicked/">'._h($unique_clicks).'</a>' : 0);?></span></td>
                    </tr>

                    <tr class="gradeX">
                        <td class="text-center"><strong><?=_t('% Clicked');?></strong></td>
                        <td class="text-center"><span class="label label-inverse" style="font-size:1em;font-weight: bold;"><?=($clicks > 0 ? percent($unique_clicks, _h((int)$cpgn->recipients)) : 0);?>%</span></td>
                    </tr>
                </tbody>
				<!-- // Table body END -->
				
			</table>
			<!-- // Table END -->
            
            <div class="separator bottom"></div>
            
            <!-- Form actions -->
            <div class="form-actions">
                <button type="button" class="btn btn-icon btn-primary glyphicons circle_minus" onclick="window.location='<?=get_base_url();?>mrkt/'"><i></i><?=_t( 'Cancel' );?></button>
            </div>
            <!-- // Form actions END -->
			
		</div>
	</div>
	
	<!-- // Widget END -->
	
</div>	
	
		
		</div>
		<!-- // Content END -->
<?php $app->view->stop(); ?>