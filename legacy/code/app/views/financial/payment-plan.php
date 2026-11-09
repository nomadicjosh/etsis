<?php if ( ! defined('BASE_PATH') ) exit('No direct script access allowed');
/**
 * Payment Plan View
 *  
 * @license GPLv3
 * 
 * @since       3.0.0
 * @package     eduTrac SIS
 * @author      Joshua Parker <joshmac3@icloud.com>
 */

$app = \Liten\Liten::getInstance();
$app->view->extend('_layouts/dashboard');
$app->view->block('dashboard');
?>

<script type="text/javascript">
$(document).ready(function(){
  $("#stuID").autocomplete({
        source: '<?=get_base_url();?>sect/stuLookup/', // The source of the AJAX results
        minLength: 2, // The minimum amount of characters that must be typed before the autocomplete is triggered
        focus: function( event, ui ) { // What happens when an autocomplete result is focused on
            $("#stuID").val( ui.item.value );
            return false;
      },
      select: function ( event, ui ) { // What happens when an autocomplete result is selected
          $("#stuID").val( ui.item.value );
          $('#StudentID').val( ui.item.id );
      }
  });
});
function addMsg(text,element_id) {
	document.getElementById(element_id).value += text;
}
</script>

<ul class="breadcrumb">
    <li><?=_t( 'You are here' );?></li>
    <li><a href="<?=get_base_url();?>dashboard/" class="glyphicons dashboard"><i></i> <?=_t( 'Dashboard' );?></a></li>
    <li class="divider"></li>
    <li><?=_t( 'Payment Plan' );?></li>
</ul>

<h3><?=_t( 'Payment Plan' );?></h3>
<div class="innerLR">
    
    <?=_etsis_flash()->showMessage();?>

    <!-- Form -->
    <form class="form-horizontal margin-none" action="<?=get_base_url();?>financial/payment-plan/" id="validateSubmitForm" method="post" autocomplete="off">
        
        <!-- Widget -->
        <div class="widget widget-heading-simple widget-body-gray">
        
            <!-- Widget heading -->
            <div class="widget-head">
                <h4 class="heading"><font color="red">*</font> <?=_t( 'Indicates field is required' );?></h4>
            </div>
            <!-- // Widget heading END -->
            
            <!-- Tabs Heading -->
            <div class="tabsbar">
                <ul>
                    <li class="glyphicons barcode"><a href="<?=get_base_url();?>financial/create-bill/"><i></i> <?=_t( 'Generate Bill/Add Fee' );?></a></li>
                    <li class="glyphicons sort"><a href="<?=get_base_url();?>financial/batch/"><i></i> <?=_t( 'Batch Fees' );?></a></li>
                    <li class="glyphicons credit_card tab-stacked"><a href="<?=get_base_url();?>financial/add-payment/"><i></i> <?=_t( 'Add Payment' );?></a></li>
                    <li class="glyphicons money tab-stacked"><a href="<?=get_base_url();?>financial/issue-refund/"><i></i> <span><?=_t( 'Issue Refund' );?></span></a></li>
                    <li class="glyphicons leaf tab-stacked active"><a href="<?=get_base_url();?>financial/payment-plan/" data-toggle="tab"><i></i> <span><?=_t( 'Payment Plan' );?></span></a></li>
                </ul>
            </div>
            <!-- // Tabs Heading END -->
            
            <div class="widget-body">
            
                <!-- Row -->
                <div class="row">
                    <!-- Column -->
                    <div class="col-md-6">
                        
                        <!-- Group -->
                        <div class="form-group">
                            <label class="col-md-3 control-label"><font color="red">*</font> <?=_t( 'Student ID/Name' );?></label>
                            <div class="col-md-8">
                                <input type="text"<?=saio();?> id="stuID" class="form-control" required />
                                <input type="hidden" id="StudentID" name="stuID" />
                            </div>
                        </div>
                        <!-- // Group END -->
                        
                        <!-- Group -->
                        <div class="form-group">
                            <label class="col-md-3 control-label"><font color="red">*</font> <?=_t( 'Payment Frequency' );?></label>
                            <div class="col-md-8">
                                <select name="payFrequency"<?=saio();?> class="selectpicker form-control" data-style="btn-info" data-size="10" data-live-search="true" required>
                                    <option value="">&nbsp;</option>
                                    <option value="1"><?=_t( 'Daily' );?></option>
                                    <option value="7"><?=_t( 'Weekly' );?></option>
                                    <option value="14"><?=_t( 'Bi-Weekly' );?></option>
                                    <option value="30"><?=_t( 'Monthly' );?></option>
                                    <option value="365"><?=_t( 'Yearly' );?></option>
                                </select>
                            </div>
                        </div>
                        <!-- // Group END -->
                        
                        <!-- Group -->
                        <div class="form-group">
                            <label class="col-md-3 control-label"><font color="red">*</font> <?=_t( 'Frequency Amount' );?></label>
                            <div class="col-md-8">
                                <input type="text"<?=saio();?> name="amount" class="form-control" required />
                            </div>
                        </div>
                        <!-- // Group END -->
                        
                        <!-- Group -->
                        <div class="form-group">
                            <label class="col-md-3 control-label"><font color="red">*</font> <?=_t( 'Start / End Date' );?> <a href="#dates" data-toggle="modal"><img src="<?=get_base_url();?>static/common/theme/images/help.png" /></a></label>
                            <div class="col-md-4">
                                <div class="input-group date" id="datepicker6">
                                    <input class="form-control"<?=saio();?> name="startDate" type="text" required/>
                                    <span class="input-group-addon"<?=saids();?>><i class="fa fa-th"></i></span>
                                </div>
                            </div>
                            
                            <div class="col-md-4">
                                <div class="input-group date" id="datepicker7">
                                    <input class="form-control"<?=saio();?> name="endDate" type="text" />
                                    <span class="input-group-addon"<?=saids();?>><i class="fa fa-th"></i></span>
                                </div>
                            </div>
                        </div>
                        <!-- // Group END -->
                        
                    </div>
                    <!-- // Column END -->
                    
                    <!-- Column -->
                    <div class="col-md-6">
                        
                        <!-- Group -->
                        <div class="form-group">
                            <label class="col-md-3 control-label"><?=_t( 'Term' );?> <a href="#term" data-toggle="modal"><img src="<?=get_base_url();?>static/common/theme/images/help.png" /></a></label>
                            <div class="col-md-8">
                                <select name="termCode"<?=saio();?> class="selectpicker form-control" data-style="btn-info" data-size="10" data-live-search="true" >
                                    <option value="">&nbsp;</option>
                                    <?php table_dropdown('term','active = "1" AND termCode <> "NULL"','termCode','termCode','termName'); ?>
                                </select>
                            </div>
                        </div>
                        <!-- // Group END -->
                        
                        <!-- Group -->
                        <div class="form-group">
                            <label class="col-md-3 control-label"><?=_t( 'Comments' );?> <a href="#comments" data-toggle="modal"><img src="<?=get_base_url();?>static/common/theme/images/help.png" /></a></label>
                            <div class="col-md-8">
                                <textarea class="form-control" id="comment" name="comments"<?=saio();?>  cols="15" rows="5"></textarea>
                                <input type="button"<?=saio();?> class="btn btn-default" value="Insert Timestamp" onclick="addMsg('<?=\Jenssegers\Date\Date::now()->format('D, M d, o @ h:i A');?> <?=get_name(get_persondata('personID'));?>','comment'); return false;" />
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
                    <input type="hidden" name="addDate" value="<?=date('Y-m-d');?>" />
                    <input type="hidden" name="addedBy" value="<?=get_persondata('personID');?>" />
                    <button type="submit"<?=saids();?> class="btn btn-icon btn-primary glyphicons circle_ok"><i></i><?=_t( 'Submit' );?></button>
                </div>
                <!-- // Form actions END -->
                
            </div>
        </div>
        <!-- // Widget END -->
        
    </form>
    <!-- // Form END -->
    
    <div class="separator bottom"></div>
	
	<!-- Widget -->
    <div class="widget widget-heading-simple widget-body-gray">
        <div class="widget-body">
        
            <!-- Table -->
            <table class="dynamicTable tableTools table table-striped table-bordered table-condensed table-primary">
            
                <!-- Table heading -->
                <thead>
                    <tr>
                        <th class="text-center"><?=_t( 'Student ID' );?></th>
                        <th class="text-center"><?=_t( 'Name' );?></th>
                        <th class="text-center"><?=_t( 'Amount' );?></th>
                        <th class="text-center"><?=_t( 'Pay Frequency' );?></th>
                        <th class="text-center"><?=_t( 'Start Date' );?></th>
                        <th class="text-center"><?=_t( 'End Date' );?></th>
                        <th class="text-center"><?=_t( 'Actions' );?></th>
                    </tr>
                </thead>
                <!-- // Table heading END -->
                
                <!-- Table body -->
                <tbody>
                <?php if($plan != '') : foreach($plan as $key => $value) { ?>
                <tr class="gradeX">
                    <td class="text-center"><?=_h($value['stuID']);?></td>
                    <td class="text-center"><?=get_name(_h($value['stuID']));?></td>
                    <td class="text-center"><?=_h($value['amount']);?></td>
                    <td class="text-center"><?=_h($value['Frequency']);?></td>
                    <td class="text-center"><?=_h($value['startDate']);?></td>
                    <td class="text-center"><?=(_h($value['endDate']) <= '0000-00-00') ? '' : _h($value['endDate']);?></td>
                    <td class="text-center">
                    	<div class="btn-group dropup">
                            <button class="btn btn-default btn-xs" type="button"><?=_t( 'Actions' ); ?></button>
                            <button data-toggle="dropdown" class="btn btn-xs btn-primary dropdown-toggle" type="button">
                                <span class="caret"></span>
                                <span class="sr-only"><?=_t( 'Toggle Dropdown' ); ?></span>
                            </button>
                            <ul role="menu" class="dropdown-menu dropup-text pull-right">
                                <li><a href="#myModal<?=_h($value['id']);?>" data-toggle="modal"><?=_t( 'View' ); ?></a></li>
                                <li><a href="#delete<?=_h($value['id']);?>" data-toggle="modal"><?=_t( 'Delete' ); ?></a></li>
                            </ul>
                        </div>
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
    
    <?php if($plan != '') : foreach($plan as $key => $value) { ?>
        <!-- Form -->
        <form class="form-horizontal margin-none" action="<?=get_base_url();?>financial/payment-plan/" id="validateSubmitForm" method="post" autocomplete="off">
            <div class="modal fade" id="myModal<?=_h($value['id']);?>">
            	<div class="modal-dialog">
					<div class="modal-content">
						<!-- Modal heading -->
						<div class="modal-header">
							<button type="button" class="close" data-dismiss="modal" aria-hidden="true">&times;</button>
							<h3 class="modal-title"><?=get_name(_h($value['stuID']));?></h3>
						</div>
						<!-- // Modal heading END -->
		                <div class="modal-body">
                            <!-- Group -->
		                    <div class="form-group">
		                        <label class="col-md-3 control-label" for="status"><font color="red">*</font> <?=_t( 'Payment Frequency' );?></label>
		                        <div class="col-md-8">
		                            <select name="payFrequency"<?=saio();?> class="selectpicker form-control" data-style="btn-info" data-size="10" data-live-search="true" required>
		                                <option value="">&nbsp;</option>
                                        <option value="1"<?=selected('1',_h($value['payFrequency']),false);?>><?=_t( 'Daily' );?></option>
                                        <option value="7"<?=selected('7',_h($value['payFrequency']),false);?>><?=_t( 'Weekly' );?></option>
                                        <option value="14"<?=selected('14',_h($value['payFrequency']),false);?>><?=_t( 'Bi-Weekly' );?></option>
                                        <option value="30"<?=selected('30',_h($value['payFrequency']),false);?>><?=_t( 'Monthly' );?></option>
                                        <option value="365"<?=selected('365',_h($value['payFrequency']),false);?>><?=_t( 'Yearly' );?></option>
		                            </select>
		                        </div>
		                    </div>
		                    <!-- // Group END -->
                            
		                    <!-- Group -->
		                    <div class="form-group">
		                        <label class="col-md-3 control-label" for="name"><font color="red">*</font> <?=_t( 'Start / End Date' );?> <a href="#dates" data-toggle="modal"><img src="<?=get_base_url();?>static/common/theme/images/help.png" /></a></label>
                                <div class="col-md-4">
                                    <div class="input-group date" id="datepicker6">
                                        <input class="form-control"<?=saio();?> name="startDate" type="text" value="<?=_h($value['startDate']);?>" required/>
                                        <span class="input-group-addon"<?=saids();?>><i class="fa fa-th"></i></span>
                                    </div>
                                </div>
                                
                                <div class="col-md-4">
                                    <div class="input-group date" id="datepicker7">
                                        <input class="form-control"<?=saio();?> name="endDate" type="text" value="<?=(_h($value['endDate']) <= '0000-00-00') ? '' : _h($value['endDate']);?>" />
                                        <span class="input-group-addon"<?=saids();?>><i class="fa fa-th"></i></span>
                                    </div>
                                </div>
		                    </div>
		                    <!-- // Group END -->
		                    
		                    <!-- Group -->
                            <div class="form-group">
                                <label class="col-md-3 control-label"><?=_t( 'Term' );?> <a href="#term" data-toggle="modal"><img src="<?=get_base_url();?>static/common/theme/images/help.png" /></a></label>
                                <div class="col-md-8">
                                    <select name="termCode"<?=saio();?> class="selectpicker form-control" data-style="btn-info" data-size="10" data-live-search="true" >
                                        <option value="">&nbsp;</option>
                                        <?php table_dropdown('term','termCode <> "NULL"','termCode','termCode','termName',_h($value['termCode'])); ?>
                                    </select>
                                </div>
                            </div>
                            <!-- // Group END -->
                            
                            <!-- Group -->
                            <div class="form-group">
                                <label class="col-md-3 control-label"><?=_t( 'Comments' );?> <a href="#comments" data-toggle="modal"><img src="<?=get_base_url();?>static/common/theme/images/help.png" /></a></label>
                                <div class="col-md-8">
                                    <textarea class="form-control" id="<?=_h($value['id']);?>" name="comments"<?=saio();?>  cols="15" rows="5"><?=_h($value['comments']);?></textarea>
                                    <input type="button"<?=saio();?> class="btn btn-default" value="Insert Timestamp" onclick="addMsg('<?=date('D, M d, o @ h:i A',strtotime(date('Y-m-d h:i A')));?> <?=get_name(get_persondata('personID'));?>','<?=_h($value['id']);?>'); return false;" />
                                </div>
                            </div>
                            <!-- // Group END -->
                            
                            <!-- Group -->
                            <div class="form-group">
                                <label class="col-md-3 control-label"><?=_t( 'Posted By' );?></label>
                                <div class="col-md-8">
                                    <input type="text" class="form-control" readonly value="<?=get_name(_h($value['addedBy']));?>" />
                                </div>
                            </div>
                            <!-- // Group END -->
                            
                            <!-- Group -->
                            <div class="form-group">
                                <label class="col-md-3 control-label"><?=_t( 'Last Update' );?></label>
                                <div class="col-md-8">
                                    <input type="text" class="form-control" readonly value="<?=date('D, M d, o @ h:i A',strtotime(_h($value['LastUpdate'])));?>" />
                                </div>
                            </div>
                            <!-- // Group END -->
		                </div>
		                <div class="modal-footer">
                            <input name="id" type="hidden" value="<?=_h($value['id']);?>" />
                            <input name="stuID" type="hidden" value="<?=_h($value['stuID']);?>" />
		                    <button type="submit"<?=saids();?> class="btn btn-default"><?=_t( 'Update' );?></button>
		                    <button data-dismiss="modal" class="btn btn-primary"><?=_t( 'Cancel' );?></button>
		                </div>
	               </div>
              </div>
            </div>
        </form>
        <!-- // Form END -->
    <?php } endif; ?>
    
    <!-- Modal -->
    <div class="modal fade" id="dates">
        <div class="modal-dialog">
            <div class="modal-content">
                <!-- Modal heading -->
                <div class="modal-header">
                    <button type="button" class="close" data-dismiss="modal" aria-hidden="true">&times;</button>
                    <h3 class="modal-title"><?=_t( 'Start and End Date' );?></h3>
                </div>
                <!-- // Modal heading END -->
                <!-- Modal body -->
                <div class="modal-body">
                    <p><?=_t('The start date will act as the date the student and the institution agreed upon said payment plan. Start date is a required field but End date is optional.');?></p>
                </div>
                <!-- // Modal body END -->
                <!-- Modal footer -->
                <div class="modal-footer">
                    <a href="#" class="btn btn-default" data-dismiss="modal"><?=_t( 'Close' );?></a> 
                </div>
                <!-- // Modal footer END -->
            </div>
        </div>
    </div>
    <!-- // Modal END -->
    <!-- Modal -->
    <div class="modal fade" id="term">
        <div class="modal-dialog">
            <div class="modal-content">
                <!-- Modal heading -->
                <div class="modal-header">
                    <button type="button" class="close" data-dismiss="modal" aria-hidden="true">&times;</button>
                    <h3 class="modal-title"><?=_t( 'Term' );?></h3>
                </div>
                <!-- // Modal heading END -->
                <!-- Modal body -->
                <div class="modal-body">
                    <p><?=_t('Ignore this field if the payment plan is not associated with a particular term.');?></p>
                </div>
                <!-- // Modal body END -->
                <!-- Modal footer -->
                <div class="modal-footer">
                    <a href="#" class="btn btn-default" data-dismiss="modal"><?=_t( 'Close' );?></a> 
                </div>
                <!-- // Modal footer END -->
            </div>
        </div>
    </div>
    <!-- // Modal END -->
    <!-- Modal -->
    <div class="modal fade" id="comments">
        <div class="modal-dialog">
            <div class="modal-content">
                <!-- Modal heading -->
                <div class="modal-header">
                    <button type="button" class="close" data-dismiss="modal" aria-hidden="true">&times;</button>
                    <h3 class="modal-title"><?=_t( 'Comments' );?></h3>
                </div>
                <!-- // Modal heading END -->
                <!-- Modal body -->
                <div class="modal-body">
                    <p><?=_t('Place any notes and or instructions for the student. This will appear on the account history screen for both the student as well as student accounts.');?></p>
                </div>
                <!-- // Modal body END -->
                <!-- Modal footer -->
                <div class="modal-footer">
                    <a href="#" class="btn btn-default" data-dismiss="modal"><?=_t( 'Close' );?></a> 
                </div>
                <!-- // Modal footer END -->
            </div>
        </div>
    </div>
    <!-- // Modal END -->
    <?php if($plan != '') : foreach($plan as $key => $value) { ?>
    <!-- Modal -->
    <div class="modal fade" id="delete<?=_h($value['id']);?>">
        <div class="modal-dialog">
            <div class="modal-content">
                <!-- Modal heading -->
                <div class="modal-header">
                    <button type="button" class="close" data-dismiss="modal" aria-hidden="true">&times;</button>
                    <h3 class="modal-title"><?=_t( 'Payment Plan:' );?> <?=_h($value['amount']);?> / <?=_h($value['Frequency']);?></h3>
                </div>
                <!-- // Modal heading END -->
                <!-- Modal body -->
                <div class="modal-body">
                    <p><?=_t('Are you sure you want to delete this payment plan?');?></p>
                </div>
                <!-- // Modal body END -->
                <!-- Modal footer -->
                <div class="modal-footer">
                    <a href="<?=get_base_url();?>financial/payment-plan/delete/<?=_h($value['id']);?>/" class="btn btn-default"><?=_t( 'Delete' );?></a>
                    <a href="#" class="btn btn-primary" data-dismiss="modal"><?=_t( 'Close' );?></a> 
                </div>
                <!-- // Modal footer END -->
            </div>
        </div>
    </div>
    <!-- // Modal END -->
    <?php } endif; ?>
</div>  
        
        </div>
        <!-- // Content END -->
<?php $app->view->stop(); ?>