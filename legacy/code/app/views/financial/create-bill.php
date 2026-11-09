<?php if ( ! defined('BASE_PATH') ) exit('No direct script access allowed');
/**
 * Create Bill View
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
    <li><?=_t( 'Create Bill' );?></li>
</ul>

<h3><?=_t( 'Create Bill' );?></h3>
<div class="innerLR">
    
    <?=_etsis_flash()->showMessage();?>

    <!-- Form -->
    <form class="form-horizontal margin-none" action="<?=get_base_url();?>financial/create-bill/" id="validateSubmitForm" method="post" autocomplete="off">
        
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
                    <li class="glyphicons barcode active"><a href="<?=get_base_url();?>financial/create-bill/" data-toggle="tab"><i></i> <?=_t( 'Generate Bill/Add Fee' );?></a></li>
                    <li class="glyphicons sort"><a href="<?=get_base_url();?>financial/batch/"><i></i> <?=_t( 'Batch Fees' );?></a></li>
                    <li class="glyphicons credit_card tab-stacked"><a href="<?=get_base_url();?>financial/add-payment/"><i></i> <?=_t( 'Add Payment' );?></a></li>
                    <li class="glyphicons money tab-stacked"><a href="<?=get_base_url();?>financial/issue-refund/"><i></i> <span><?=_t( 'Issue Refund' );?></span></a></li>
                    <li class="glyphicons leaf tab-stacked"><a href="<?=get_base_url();?>financial/payment-plan/"><i></i> <span><?=_t( 'Payment Plan' );?></span></a></li>
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
                        
                    </div>
                    <!-- // Column END -->
                    
                    <!-- Column -->
                    <div class="col-md-6">
                        
                        <!-- Group -->
                        <div class="form-group">
                            <label class="col-md-3 control-label"><font color="red">*</font> <?=_t( 'Bill / Fee Date' );?></label>
                            <div class="col-md-8">
                                <div class="input-group date col-md-12" id="datepicker6">
                                    <input class="form-control" name="billDate" type="text" required/>
                                    <span class="input-group-addon"><i class="fa fa-th"></i></span>
                                </div>
                            </div>
                        </div>
                        <!-- // Group END -->
                        
                    </div>
                    <!-- // Column END -->
                    
                </div>
                <!-- // Row END -->
                
                <hr class="separator" />
                
                <!-- Row -->
                <div class="row">
                    <!-- Column -->
                    <div class="col-md-6">
                        
                        <!-- Group -->
                        <div class="form-group">
                            <label class="col-md-3 control-label"><font color="red">*</font> <?=_t( 'Fees' );?></label>
                            <div class="col-md-8">
                                <select multiple id="pre-selected-options"<?=saio();?> name="feeID[]" class="multiselect" required>
                                    <?php fee_table_dropdown(); ?>
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
                            <label class="col-md-3 control-label"><font color="red">*</font> <?=_t( 'Term' );?></label>
                            <div class="col-md-8">
                                <select name="termCode"<?=saio();?> class="selectpicker form-control" data-style="btn-info" data-size="10" data-live-search="true" required>
                                    <option value="">&nbsp;</option>
                                    <?php table_dropdown('term','active = "1" AND termCode <> "NULL"','termCode','termCode','termName'); ?>
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
                            <label class="col-md-3 control-label"><?=_t( 'Comment' );?> <a href="#modal" data-toggle="modal"><img src="<?=get_base_url();?>static/common/theme/images/help.png" /></a></label>
                            <div class="col-md-8">
                                <textarea id="comment" class="form-control" name="stu_comments" rows="5" data-height="auto"></textarea>
                                <input type="button" class="btn btn-default" value="Insert Timestamp" onclick="addMsg('<?=\Jenssegers\Date\Date::now()->format('D, M d, o @ h:i A');?> <?=get_name(get_persondata('personID'));?>','comment'); return false;" />
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
                    <button type="submit"<?=saids();?> class="btn btn-icon btn-primary glyphicons circle_ok"><i></i><?=_t( 'Submit' );?></button>
                </div>
                <!-- // Form actions END -->
                
            </div>
        </div>
        <!-- // Widget END -->
        
    </form>
    <!-- // Form END -->
    
    <!-- Modal -->
	<div class="modal fade" id="modal">
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
					<p><?=_t("Comments will only be posted when a bill is initially created. If you are adding a fee to an already existing bill and want to add a comment about the new addition, you can do so when viewing the student's bill. Unlike other system comments, these comments will be seen by students.");?></p>
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
    
</div>  
        
        </div>
        <!-- // Content END -->
<?php $app->view->stop(); ?>