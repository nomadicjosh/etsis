<?php if ( ! defined('BASE_PATH') ) exit('No direct script access allowed');
/**
 * Events List View
 *  
 * @license GPLv3
 * 
 * @since       4.1.7
 * @package     eduTrac SIS
 * @author      Joshua Parker <joshmac3@icloud.com>
 */

$app = \Liten\Liten::getInstance();
$app->view->extend('_layouts/dashboard');
$app->view->block('dashboard');
$screen = 'calevent';
?>

<ul class="breadcrumb">
	<li><?=_t( 'You are here' );?></li>
	<li><a href="<?=get_base_url();?>dashboard/" class="glyphicons dashboard"><i></i> <?=_t( 'Dashboard' );?></a></li>
	<li class="divider"></li>
	<li><a href="<?=get_base_url();?>calendar/" class="glyphicons calendar"><i></i> <?=_t( 'Events Calendar' );?></a></li>
	<li class="divider"></li>
	<li><?=_t( 'Events List' );?></li>
</ul>

<h3><?=_t( 'Events List' );?></h3>
<div class="innerLR">
    
    <?=_etsis_flash()->showMessage();?>
    
    <?php jstree_sidebar_menu($screen); ?>

	<!-- Widget -->
	<div class="widget widget-heading-simple widget-body-gray <?=($app->hook->has_filter('sidebar_menu')) ? 'col-md-12' : 'col-md-10';?>">
		<div class="widget-body">
			
			<!-- Table -->
			<table class="dynamicTable tableTools table table-striped table-bordered table-condensed table-white">
			
				<!-- Table heading -->
				<thead>
					<tr>
						<th class="text-center"><?=_t( 'Event Name' );?></th>
						<th class="text-center"><?=_t( 'Event Type' );?></th>
						<th class="text-center"><?=_t( 'Category' );?></th>
						<th class="text-center"><?=_t( 'Room' );?></th>
						<th class="text-center"><?=_t( 'Requestor' );?></th>
						<th class="text-center"><?=_t( 'Start Date/Time' );?></th>
						<th class="text-center"><?=_t( 'Actions' );?></th>
					</tr>
				</thead>
				<!-- // Table heading END -->
				
				<!-- Table body -->
				<tbody>
				<?php if($event != '') : foreach($event as $k => $v) { ?>
                <tr class="gradeX">
                    <td class="text-center"><?=_h($v['title']);?></td>
                    <td class="text-center"><?=_h($v['eventType']);?></td>
                    <td class="text-center"><?=_h($v['cat_name']);?></td>
                    <td class="text-center"><?=_h($v['roomCode']);?></td>
                    <td class="text-center"><?=get_name(_h($v['requestor']));?></td>
                    <td class="text-center"><?=date('D, M d, o',strtotime(_h($v['startDate'])));?> <?=date('h:i A',strtotime(_h($v['startTime'])));?></td>
                    <td class="text-center">
                    	<a href="<?=get_base_url();?>calendar/eventID/<?=_h($v['eventID']);?>/" title="View Event" class="btn btn-default"><i class="fa fa-eye"></i></a>
                    </td>
                </tr>
				<?php } endif; ?>
				</tbody>
				<!-- // Table body END -->
				
			</table>
			<!-- // Table END -->
			
		</div>
	</div>
	
	<!-- // Widget END -->
	
</div>	
	
		
		</div>
		<!-- // Content END -->
<?php $app->view->stop(); ?>