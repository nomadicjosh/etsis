<?php if ( ! defined('BASE_PATH') ) exit('No direct script access allowed');
/**
 * myetSIS Room Request Form View
 *  
 * @license GPLv3
 * 
 * @since       4.3
 * @package     eduTrac SIS
 * @author      Joshua Parker <joshmac3@icloud.com>
 */

$app = \Liten\Liten::getInstance();
$app->view->extend('_layouts/dashboard');
$app->view->block('dashboard');

$dowMap = [ 'Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday' ];
if($app->req->post['check_event']) {
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
if($app->req->post['email_request']) {
	$data = [];
	$data['eventType'] = $app->req->post['eventType'];
	$data['catID'] = $app->req->post['catID'];
	$data['requestor'] = $app->req->post['requestor'];
	$data['roomCode'] = $app->req->post['roomCode'];
	$data['termCode'] = $app->req->post['termCode'];
	$data['title'] = $app->req->post['title'];
	$data['description'] = $app->req->post['description'];
	$data['weekday'] = convert_day($app->req->post['startDate']);
	$data['startDate'] = $app->req->post['startDate'];
	$data['endDate'] = $app->req->post['endDate'];
	$data['startTime'] = $app->req->post['startTime'];
	$data['endTime'] = $app->req->post['endTime'];
	$data['repeats'] = $app->req->post['repeats'];
	$data['repeatFreq'] = $app->req->post['repeatFreq'];
	$data['addDate'] = $app->req->post['addDate'];
	room_request($data);
}
?>

<div class="col-md-12">
	<div class="separator bottom"></div>
	<div class="separator bottom"></div>

	<h3 class="glyphicons book"><i></i><?=_t( 'Room/Event Request Form' );?></h3>
	<div class="separator bottom"></div>
	<h4 class="heading small"><font color="red">*</font> <?=_t( 'Indicates field is required.' );?></h4>
	<div class="separator bottom"></div>
	
	<?php if($app->req->post['check_event']) : ?>
            <?=room_booking($data);?>
    <?php endif; ?>
	
	<?=_etsis_flash()->showMessage();?>
	
	<div class="widget widget-heading-simple widget-body-white">
		<div class="widget-body">
			<div class="alerts alerts-info">
				<p><?=_t( 'It is recommended that you check the availability of your request before submitting the form. Once the form is submitted, an email will be sent to the room scheduler. If there are no issues, when the room scheduler approves your request, you will receive a confirmation email.' );?></p>
			</div>
		</div>
	</div>

<!-- Form -->
<form class="form-horizontal margin-none" action="<?=get_base_url();?>calendar/request-form/" id="validateSubmitForm" method="post" autocomplete="off">
<div class="widget widget-heading-simple widget-body-white">
	<div class="widget-body">
		<div class="row">
			<div class="col-md-12">
				<form class="margin-none">
					<div class="row innerB">
						<div class="col-md-6">
							<label class="control-label"><font color="red">*</font> <?=_t( 'Request Type' );?></label>
							<select name="eventType" class="selectpicker form-control" data-style="btn-info" data-size="10" data-live-search="true" required>
                                <option value="">&nbsp;</option>
                                <option value="Meeting"<?php if($app->req->post['eventType'] == 'Meeting') { echo " selected='selected'"; } ?>><?=_t( 'Meeting' );?></option>
                                <option value="Event"<?php if($app->req->post['eventType'] == 'Event') { echo " selected='selected'"; } ?>><?=_t( 'Event' );?></option>
                            </select>
						</div>
						<div class="col-md-6">
							<label class="control-label"><font color="red">*</font> <?=_t( 'Category' );?></label>
							<select name="catID" class="selectpicker form-control" data-style="btn-info" data-size="10" data-live-search="true" required>
                                <option value="">&nbsp;</option>
                                <?php eventCategory($app->req->post['catID']); ?>
                            </select>
						</div>
						<div class="col-md-6">
							<label class="control-label"><font color="red">*</font> <?=_t( 'Event Name' );?></label>
							<input type="text" name="title" class="form-control" value="<?=$app->req->post['title'];?>" required/>
						</div>
						<div class="col-md-6">
							<label class="control-label"><?=_t( 'Description' );?></label>
							<input type="text" name="description" class="form-control" maxlength="40" value="<?=$app->req->post['description'];?>" />
						</div>
						<div class="col-md-6">
							<label class="control-label"><?=_t( 'Room' );?></label>
							<select name="roomCode" class="selectpicker form-control" data-style="btn-info" data-size="10" data-live-search="true">
                                <option value="NULL">&nbsp;</option>
                                <?=table_dropdown('room', 'roomCode <> "NULL"', 'roomCode', 'roomCode', 'roomNumber', $app->req->post['roomCode'] );?>
                            </select>
						</div>
						<div class="col-md-6">
							<label class="control-label"><font color="red">*</font> <?=_t( 'Last Day' );?></label>
							<div class="input-group date" id="datepicker7">
                                <input class="form-control" name="endDate" type="text" value="<?=$app->req->post['endDate'];?>" required/>
                                <span class="input-group-addon"><i class="fa fa-th"></i></span>
                            </div>
						</div>
						<div class="col-md-6">
							<label class="control-label"><font color="red">*</font> <?=_t( 'First Day' );?></label>
							<div class="input-group date" id="datepicker6">
                                <input class="form-control" name="startDate" type="text" value="<?=$app->req->post['startDate'];?>" required/>
                                <span class="input-group-addon"><i class="fa fa-th"></i></span>
                            </div>
						</div>
						<div class="col-md-6">
							<label class="control-label"><font color="red">*</font> <?=_t( 'End Time' );?></label>
							<div class="input-group bootstrap-timepicker">
        				        <input id="convertTime2" type="text" name="endTime" class="form-control" value="<?=$app->req->post['endTime'];?>" required/>
        				        <span class="input-group-addon"><i class="fa fa-clock-o"></i></span>
					        </div>
						</div>
						<div class="col-md-6">
							<label class="control-label"><font color="red">*</font> <?=_t( 'Start Time' );?></label>
							<div class="input-group bootstrap-timepicker">
        				        <input id="convertTime1" type="text" name="startTime" class="form-control" value="<?=$app->req->post['startTime'];?>" required/>
        				        <span class="input-group-addon"><i class="fa fa-clock-o"></i></span>
					        </div>
						</div>
						<div class="col-md-6">
							<label class="control-label"><?=_t( 'Repeat?' );?></label>
							<div class="input-group bootstrap-timepicker">
        				        <input type="checkbox" name="repeats" id="repeats" value="1"<?php if($app->req->post['repeats'] == 1) { echo ' checked="checked"'; } ?> />
					        </div>
						</div>
						<div class="col-md-6">
							<label class="control-label"><?=_t( 'Repeat Occurrence' );?></label>
							<input type="radio" value="1" id="repeatFreq" name="repeatFreq"<?php if($app->req->post['repeatFreq'] == 1) { echo ' checked="checked"'; } ?> />
                            <label><?=_t( 'Every Day' );?></label>&nbsp;&nbsp;
                            
                            <input type="radio" value="7" id="repeatFreq" name="repeatFreq"<?php if($app->req->post['repeatFreq'] == 7) { echo ' checked="checked"'; } ?> />
                            <label><?=_t( 'Weekly' );?></label>&nbsp;&nbsp;
                            
                            <input type="radio" value="14" id="repeatFreq" name="repeatFreq"<?php if($app->req->post['repeatFreq'] == 14) { echo ' checked="checked"'; } ?> />
                            <label><?=_t( 'Biweekly' );?></label>
						</div>
					</div>
					<!-- Form actions -->
					<div class="form-actions">
                        <input type="hidden" name="requestor" value="<?=get_persondata( 'personID' );?>" />
						<input type="hidden" name="addDate" value="<?=\Jenssegers\Date\Date::now()->format( 'Y-m-d' );?>" />
						<input type="hidden" name="termCode" value="" />
						<input type="submit" name="check_event" class="btn btn-warning" value="<?=_t( 'Check Availability' );?>" />
						<input type="submit" name="email_request" class="btn btn-default" value="<?=_t( 'Send' );?>" />
					</div>
					<!-- // Form actions END -->
				</form>
			</div>
		</div>
	</div>
</div>
</form>
<!-- // Form END -->

</div>
	</div>
</div>

	
		</div>
		<!-- // Content END -->
<?php $app->view->stop(); ?>