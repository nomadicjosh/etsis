<?php if ( ! defined('BASE_PATH') ) exit('No direct script access allowed');
/**
 * View Booking Request View
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
$screen = 'calreq';
$dowMap = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
if(isset($app->req->post['check_event'])) {
	$data = [];
	$data['title'] = $app->req->post['title'];
	$data['roomCode'] = $app->req->post['roomCode'];
	$data['termCode'] = $app->req->post['termCode'];
	$data['startDate'] = $app->req->post['startDate'];
	$data['endDate'] = $app->req->post['endDate'];
	$data['startTime'] = $app->req->post['startTime'];
	$data['endTime'] = $app->req->post['endTime'];
	$data['repeats'] = $app->req->post['repeats'];
	$data['repeatFreq'] = $app->req->post['repeatFreq'];
	$data['check_event'] = $app->req->post['check_event'];
}
if(isset($app->req->post['book_request'])) {
	$data = [];
	$data['title'] = $app->req->post['title'];
	$data['description'] = $app->req->post['description'];
	$data['eventType'] = $app->req->post['eventType'];
	$data['catID'] = $app->req->post['catID'];
	$data['roomCode'] = $app->req->post['roomCode'];
	$data['weekday'] = convert_day($app->req->post['startDate']);
	$data['startDate'] = $app->req->post['startDate'];
	$data['endDate'] = $app->req->post['endDate'];
	$data['startTime'] = $app->req->post['startTime'];
	$data['endTime'] = $app->req->post['endTime'];
	$data['repeats'] = $app->req->post['repeats'];
	$data['repeatFreq'] = $app->req->post['repeatFreq'];
	$data['requestor'] = $app->req->post['requestor'];
	$data['requestID'] = $app->req->post['requestID'];
	$data['book_request'] = $app->req->post['book_request'];
	$data['addedBy'] = $app->req->post['addedBy'];
	room_booking($data);
}
if(isset($app->req->post['update_request'])) {
	$data = [];
	$data['eventType'] = $app->req->post['eventType'];
	$data['catID'] = $app->req->post['catID'];
	$data['requestor'] = $app->req->post['requestor'];
	$data['roomCode'] = $app->req->post['roomCode'];
	$data['title'] = $app->req->post['title'];
	$data['description'] = $app->req->post['description'];
	$data['weekday'] = convert_day($app->req->post['startDate']);
	$data['startDate'] = $app->req->post['startDate'];
	$data['endDate'] = $app->req->post['endDate'];
	$data['startTime'] = $app->req->post['startTime'];
	$data['endTime'] = $app->req->post['endTime'];
	$data['repeats'] = $app->req->post['repeats'];
	$data['repeatFreq'] = $app->req->post['repeatFreq'];
	$data['requestID'] = $app->req->post['requestID'];
	update_booking_request($data);
}
?>

<ul class="breadcrumb">
	<li><?=_t( 'You are here' );?></li>
	<li><a href="<?=get_base_url();?>dashboard/" class="glyphicons dashboard"><i></i> <?=_t( 'Dashboard' );?></a></li>
	<li class="divider"></li>
	<li><a href="<?=get_base_url();?>calendar/" class="glyphicons calendar"><i></i> <?=_t( 'Events Calendar' );?></a></li>
	<li class="divider"></li>
	<li><?=_h($req[0]['title']);?></li>
</ul>

<h3><?=_h($req[0]['title']);?></h3>
<div class="innerLR">
	
	<?php if(isset($app->req->post['check_event'])) : ?>
            <?=room_booking($data);?>
    <?php endif; ?>
    
    <?=_etsis_flash()->showMessage();?>
    
    <?php jstree_sidebar_menu($screen); ?>
    
	<div class="widget widget-heading-simple widget-body-white <?=($app->hook->has_filter('sidebar_menu')) ? 'col-md-12' : 'col-md-10';?>">
		<div class="widget-body">
			<div class="alerts alerts-info">
				<p><?=_t( 'It is recommended that you check the availability of a room before submitting the form. If there are changes to the original request, you need to make the changes and click "Update" before processing the request.' );?></p>
			</div>
		</div>
	</div>

	<!-- Form -->
	<form class="form-horizontal margin-none" action="<?=get_base_url();?>calendar/requestID/<?=_h($req[0]['requestID']);?>/" id="validateSubmitForm" method="post" autocomplete="off">
		
		<!-- Widget -->
		<div class="widget widget-heading-simple widget-body-gray <?=($app->hook->has_filter('sidebar_menu')) ? 'col-md-12' : 'col-md-10';?>">
			
			<div class="widget-body">
			
				<!-- Row -->
				<div class="row">
					<!-- Column -->
					<div class="col-md-6">
						
						<!-- Group -->
                        <div class="form-group">
                            <label class="col-md-3 control-label"><font color="red">*</font> <?=_t( 'Requestor' );?></label>
                            <div class="col-md-8">
                                <select name="requestor" class="selectpicker form-control" data-style="btn-info" data-size="10" data-live-search="true" required>
                                    <option value="">&nbsp;</option>
                                    <?php get_requestor(_h($req[0]['requestor'])); ?>
                                </select>
                            </div>
                        </div>
                        <!-- // Group END -->
						
						<!-- Group -->
                        <div class="form-group">
                            <label class="col-md-3 control-label"><font color="red">*</font> <?=_t( 'Request Type' );?></label>
                            <div class="col-md-8">
                                <select name="eventType" class="selectpicker form-control" data-style="btn-info" data-size="10" data-live-search="true" required>
                                    <option value="">&nbsp;</option>
                                    <option value="Meeting"<?php if(_h($req[0]['eventType']) == 'Meeting') { echo " selected='selected'"; } ?>><?=_t( 'Meeting' );?></option>
                                    <option value="Event"<?php if(_h($req[0]['eventType']) == 'Event') { echo " selected='selected'"; } ?>><?=_t( 'Event' );?></option>
                                </select>
                            </div>
                        </div>
                        <!-- // Group END -->
                        
                        <!-- Group -->
                        <div class="form-group">
                            <label class="col-md-3 control-label"><font color="red">*</font> <?=_t( 'Category' );?></label>
                            <div class="col-md-8">
                                <select name="catID" class="selectpicker form-control" data-style="btn-info" data-size="10" data-live-search="true" required>
                                    <option value="">&nbsp;</option>
                                    <?php eventCategory(_h($req[0]['catID'])); ?>
                                </select>
                            </div>
                        </div>
                        <!-- // Group END -->
                        
                        <!-- Group -->
                        <div class="form-group">
                            <label class="col-md-3 control-label"><font color="red">*</font> <?=_t( 'Event Name' );?></label>
                            <div class="col-md-8">
                                <input type="text" name="title" class="form-control" value="<?=_h($req[0]['title']);?>" required />
                            </div>
                        </div>
                        <!-- // Group END -->
                        
                        <!-- Group -->
                        <div class="form-group">
                            <label class="col-md-3 control-label"><?=_t( 'Description' );?></label>
                            <div class="col-md-8">
                                <input type="text" name="description" class="form-control" maxlength="40" value="<?=_h($req[0]['description']);?>" />
                            </div>
                        </div>
                        <!-- // Group END -->
                        
                        <!-- Group -->
                        <div class="form-group">
                            <label class="col-md-3 control-label"><?=_t( 'Room' );?></label>
                            <div class="col-md-8">
                                <select name="roomCode" class="selectpicker form-control" data-style="btn-info" data-size="10" data-live-search="true">
                                    <option value="NULL">&nbsp;</option>
                                    <?=table_dropdown('room', 'roomCode <> "NULL"', 'roomCode', 'roomCode', 'roomNumber', _h($req[0]['roomCode']) );?>
                                </select>
                            </div>
                        </div>
                        <!-- // Group END -->
						
					</div>
					<!-- // Column END -->
					
					<!-- Column -->
					<div class="col-md-6">
						
						<!-- Group -->
                        <div class="form-group">
                            <label class="col-md-3 control-label"><font color="red">*</font> <?=_t( 'First Day' );?></label>
                            <div class="col-md-8">
                                <div class="input-group date" id="datepicker6">
                                    <input class="form-control" name="startDate" type="text" value="<?=_h($req[0]['startDate']);?>" required/>
                                    <span class="input-group-addon"><i class="fa fa-th"></i></span>
                                </div>
                            </div>
                        </div>
                        <!-- // Group END -->
						
						<!-- Group -->
                        <div class="form-group">
                            <label class="col-md-3 control-label"><font color="red">*</font> <?=_t( 'Last Day' );?></label>
                            <div class="col-md-8">
                                <div class="input-group date" id="datepicker7">
                                    <input class="form-control" name="endDate" type="text" value="<?=_h($req[0]['endDate']);?>" required/>
                                    <span class="input-group-addon"><i class="fa fa-th"></i></span>
                                </div>
                            </div>
                        </div>
                        <!-- // Group END -->
                        
                        <!-- Group -->
                        <div class="form-group">
                            <label class="col-md-3 control-label"><font color="red">*</font> <?=_t( 'Start Time' );?></label>
                            <div class="col-md-8">
                                <div class="input-group bootstrap-timepicker">
	        				        <input id="convertTime1" type="text" name="startTime" class="form-control" value="<?=_h($req[0]['startTime']);?>" required/>
	        				        <span class="input-group-addon"><i class="fa fa-clock-o"></i></span>
						        </div>
                            </div>
                        </div>
                        <!-- // Group END -->
                        
                        <!-- Group -->
                        <div class="form-group">
                            <label class="col-md-3 control-label"><font color="red">*</font> <?=_t( 'End Time' );?></label>
                            <div class="col-md-8">
                                <div class="input-group bootstrap-timepicker">
	        				        <input id="convertTime2" type="text" name="endTime" class="form-control" value="<?=_h($req[0]['endTime']);?>" required/>
	        				        <span class="input-group-addon"><i class="fa fa-clock-o"></i></span>
						        </div>
                            </div>
                        </div>
                        <!-- // Group END -->
                        
                        <!-- Group -->
                        <div class="form-group">
                            <label class="col-md-3 control-label"><?=_t( 'Repeat?' );?></label>
                            <div class="col-md-4">
                                <input type="checkbox" name="repeats" id="repeats" value="1"<?php if(_h($req[0]['repeats']) == 1) { echo ' checked="checked"'; } ?> />
                            </div>
                        </div>
                        <!-- // Group END -->
                        
                        <!-- Group -->
                        <div class="form-group">
                            <label class="col-md-3 control-label"><?=_t( 'Repeat Occurrence' );?></label>
                            <div class="col-md-6">
                                    <input type="radio" value="1" id="repeatFreq" name="repeatFreq"<?php if(_h($req[0]['repeatFreq']) == 1) { echo ' checked="checked"'; } ?> />
                                    <label><?=_t( 'Every Day' );?></label>&nbsp;&nbsp;
                                    
                                    <input type="radio" value="7" id="repeatFreq" name="repeatFreq"<?php if(_h($req[0]['repeatFreq']) == 7) { echo ' checked="checked"'; } ?> />
                                    <label><?=_t( 'Weekly' );?></label>&nbsp;&nbsp;
                                    
                                    <input type="radio" value="14" id="repeatFreq" name="repeatFreq"<?php if(_h($req[0]['repeatFreq']) == 14) { echo ' checked="checked"'; } ?> />
                                    <label><?=_t( 'Biweekly' );?></label>
                            </div>
                        </div>
                        <!-- // Group END -->
						
					</div>
					<!-- // Column END -->
					
				</div>
				<!-- // Row END -->
			
				<hr class="separator" />
				
				<!-- Form actions -->
				<div class="form-actions">
					<input type="hidden" name="requestID" value="<?=_h($req[0]['requestID']);?>" />
                    <input type="hidden" name="addedBy" value="<?=get_persondata('personID');?>" />
                    <input type="submit" name="check_event" class="btn btn-warning" value="<?=_t( 'Check Availability' );?>" />
                    <input type="submit" name="book_request" class="btn btn-default" value="<?=_t( 'Book It' );?>" />
                    <input type="submit" name="update_request" class="btn btn-default" value="<?=_t( 'Update' );?>" />
                    <button type="button" class="btn btn-icon btn-primary glyphicons circle_minus" onclick="window.location='<?=get_base_url();?>calendar/requests/'"><i></i><?=_t( 'Cancel' );?></button>
				</div>
				<!-- // Form actions END -->
				
			</div>
		</div>
		<!-- // Widget END -->
		
	</form>
	<!-- // Form END -->
	
</div>	
		
		</div>
		<!-- // Content END -->
<?php $app->view->stop(); ?>