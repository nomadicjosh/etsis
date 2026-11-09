<?php if ( ! defined('BASE_PATH') ) exit('No direct script access allowed');
/**
 * Booking Requests View
 *  
 * @license GPLv3
 * 
 * @since       4.1.8
 * @package     eduTrac SIS
 * @author      Joshua Parker <joshmac3@icloud.com>
 */

$app = \Liten\Liten::getInstance();
$app->view->extend('_layouts/dashboard');
$app->view->block('dashboard');
$screen = 'calreq';
$dowMap = array('Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday');
?>

<ul class="breadcrumb">
	<li><?=_t( 'You are here' );?></li>
	<li><a href="<?=get_base_url();?>dashboard/" class="glyphicons dashboard"><i></i> <?=_t( 'Dashboard' );?></a></li>
	<li class="divider"></li>
	<li><a href="<?=get_base_url();?>calendar/" class="glyphicons calendar"><i></i> <?=_t( 'Events Calendar' );?></a></li>
	<li class="divider"></li>
	<li><?=_t( 'Pending Requests' );?></li>
</ul>

<h3><?=_t( 'Pending Requests' );?></h3>
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
				<?php if($request != '') : foreach($request as $k => $v) { ?>
                <tr class="gradeX">
                    <td class="text-center"><?=_h($v['title']);?></td>
                    <td class="text-center"><?=_h($v['eventType']);?></td>
                    <td class="text-center"><?=_h($v['cat_name']);?></td>
                    <td class="text-center"><?=_h($v['roomCode']);?></td>
                    <td class="text-center"><?=get_name(_h($v['requestor']));?></td>
                    <td class="text-center"><?=\Jenssegers\Date\Date::parse(_h($v['startDate']))->format('D, M d, o');?> <?=\Jenssegers\Date\Date::parse(_h($v['startTime']))->format('h:i A');?></td>
                    <td class="text-center">
                    	<a href="<?=get_base_url();?>calendar/requestID/<?=_h($v['requestID']);?>/" title="View Booking Request" class="btn btn-default"><i class="fa fa-eye"></i></a>
                    	<a href="#request_<?=_h($v['requestID']);?>" data-toggle="modal" class="btn btn-danger"><i class="fa fa-trash-o"></i></a>
                    	<!-- Modal -->
						<div class="modal fade" id="request_<?=_h($v['requestID']);?>">
							<div class="modal-dialog">
								<div class="modal-content">
									<!-- Modal heading -->
									<div class="modal-header">
										<button type="button" class="close" data-dismiss="modal" aria-hidden="true">&times;</button>
										<h3 class="modal-title"><?=_h($v['title']);?>, <?=$dowMap[_h($v['weekday'])];?> <br /> <?=\Jenssegers\Date\Date::parse(_h($v['startTime']))->format("h:i A");?> - <?=\Jenssegers\Date\Date::parse(_h($v['endTime']))->format("h:i A");?></h3>
									</div>
									<!-- // Modal heading END -->
									<!-- Modal body -->
									<div class="modal-body">
										<p><?= _t( "Are you sure you want to delete this booking request?" );?></p>
									</div>
									<!-- // Modal body END -->
									<!-- Modal footer -->
									<div class="modal-footer">
										<a href="<?=get_base_url();?>calendar/deleteBookingRequest/<?=_h($v['requestID']);?>" class="btn btn-default"><?=_t( 'Delete' );?></a>
										<a href="#" class="btn btn-primary" data-dismiss="modal"><?=_t( 'Cancel' );?></a> 
									</div>
									<!-- // Modal footer END -->
								</div>
							</div>
						</div>
						<!-- // Modal END -->
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