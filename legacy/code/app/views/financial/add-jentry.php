<?php if ( ! defined('BASE_PATH') ) exit('No direct script access allowed');
/**
 * Add General Ledger Journal Entry View
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
$screen = 'jent';
?>

<script type="text/javascript">
$(document).ready(function() {
	//update total function.
	function update_balance() { 
		debit_amnt = 0;
		credit_amnt = 0;
		balance_amnt = 0;
		i = 1;
			$('.amount').each(function(i){
				amount = parseInt($(this).val());
			if(amount < 0) {
				if(!isNaN(amount)) {
				credit_amnt = amount+credit_amnt;
				}
			} else { 
				if(!isNaN(amount)) {
				debit_amnt = amount+debit_amnt;
				}
			}
			$('#debit_amnt').html(debit_amnt);
			$('#credit_amnt').html(credit_amnt);
			$('#balance_amnt').html(debit_amnt+credit_amnt);
			});
	}//function update_balance Ends here.
//calculations.
$('#items').on('change', '.amount', function(){
	if(isNaN($(this).val())) {
		$(this).val('');
		alert('Please enter number.');
	}
	update_balance();
});
//Remove row.
$('#items').on('click', '.delme', function() {
   $(this).parents('.item-row').remove();
	update_balance();
});
//add row add here.
$("#addrow").click(function(){
$(".item-row:last").after('<tr class="item-row"><td class="center item-name"><select name="glacctID[]" class="glacctID selectpicker form-control" data-style="btn-info" data-size="10" data-live-search="true" required><option value="">&nbsp;</option><?php gl_acct_dropdown(); ?></select></td><td><textarea id="mustHaveId" class="form-control" name="gl_trans_memo[]" placeholder="Transaction Description" rows="2"></textarea></td><td><input type="text" name="amount[]" class="form-control amount" required/></td><td><a href="javascript:;" title="Remove row" class="delme btn btn-default"><i class="fa fa-minus"></i></a></td></tr>');
});
  			$('#validateSubmitForm').submit(function(e) {
$('.amount').each(function() {
    if($(this).val().length == 0) { 
		error = 1;
	} else { 
		error = 0;
	}
});
$('.glacctID').each(function() {
    if($(this).val().length == 0) { 
		error1 = 1;
	} else { 
		error1 = 0;
	}
});
if($('#balance_amnt').html() != "0") { 
alert("Error: Journal entry balance must equal 0 (zero)");
	return false;
} else if(error != 0) { 
	alert("All amount fields are required.");
	return false;
} else if(error1 != 0) { 
	alert("You have to select all accounts.");
			return false;
		}
	});
});
</script>

<ul class="breadcrumb">
    <li><?=_t( 'You are here' );?></li>
    <li><a href="<?=get_base_url();?>dashboard/" class="glyphicons dashboard"><i></i> <?=_t( 'Dashboard' );?></a></li>
    <li class="divider"></li>
    <li><?=_t( 'Add Journal Entry' );?></li>
</ul>

<h3><?=_t( 'Add Journal Entry' );?></h3>
<div class="innerLR">
    
    <?=_etsis_flash()->showMessage();?>
    
    <?php jstree_sidebar_menu($screen); ?>

    <!-- Form -->
    <form class="form-horizontal margin-none" action="<?=get_base_url();?>financial/add-jentry/" id="validateSubmitForm" method="post" autocomplete="off">
        
        <!-- Widget -->
        <div class="widget widget-heading-simple widget-body-gray <?=($app->hook->has_filter('sidebar_menu')) ? 'col-md-12' : 'col-md-10';?>">
            
            <!-- Tabs Heading -->
            <div class="tabsbar">
                <ul>
                    <li class="glyphicons notes"><a href="<?=get_base_url();?>financial/journal-entries/"><i></i> <?=_t( 'Entries' );?></a></li>
                    <li class="glyphicons circle_plus active"><a href="<?=get_base_url();?>financial/add-jentry/" data-toggle="tab"><i></i> <?=_t( 'Add Entry' );?></a></li>
                </ul>
            </div>
            <!-- // Tabs Heading END -->
        
        
            <!-- Widget heading -->
            <div class="widget-head">
                <h4 class="heading"><font color="red">*</font> <?=_t( 'Indicates field is required' );?></h4>
            </div>
            <!-- // Widget heading END -->
            
            <div class="widget-body">
            
                <!-- Row -->
                <div class="row">
                    <!-- Column -->
                    <div class="col-md-6">
                        
                        <!-- Group -->
                        <div class="form-group">
                            <label class="col-md-3 control-label"><font color="red">*</font> <?=_t( 'Date' );?></label>
                            <div class="col-md-8">
                                <div class="input-group date col-md-8" id="datepicker6">
                                    <input class="form-control" name="gl_jentry_date" type="text" required/>
                                    <span class="input-group-addon"><i class="fa fa-th"></i></span>
                                </div>
                            </div>
                        </div>
                        <!-- // Group END -->
                        
                        <!-- Group -->
                        <div class="form-group">
                            <label class="col-md-3 control-label"><font color="red">*</font> <?=_t( 'Entry Title' );?></label>
                            <div class="col-md-8">
                                <input class="form-control" type="text" name="gl_jentry_title" required />
                            </div>
                        </div>
                        <!-- // Group END -->
                        
                    </div>
                    <!-- // Column END -->
                    
                    <!-- Column -->
                    <div class="col-md-6">
                    	
                    	<!-- Group -->
                        <div class="form-group">
                            <label class="col-md-3 control-label"><?=_t( 'Manual ID' );?></label>
                            <div class="col-md-8">
                                <input class="form-control" type="text" name="gl_jentry_manual_id" />
                            </div>
                        </div>
                        <!-- // Group END -->
                        
                        <!-- Group -->
                        <div class="form-group">
                            <label class="col-md-3 control-label"><?=_t( 'Description' );?></label>
                            <div class="col-md-8">
                                <textarea id="mustHaveId" class="form-control" name="gl_jentry_description" placeholder="Journal Entry Description" rows="2"></textarea>
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
                    <div class="col-md-12">
                        
                        <!-- Group -->
	                        <!-- Table -->
							<table id="items" class="table table-striped table-responsive swipe-horizontal table-primary">
								
								<strong><small><font style="color:green;"><?=_t( 'Include -(minus) symbol before the credit amount.' );?></font></small></strong>
								
								<!-- Table heading -->
								<thead>
									<tr>
										<th class="center"><font color="red">*</font> <?=_t( 'GL Account' );?></th>
										<th><?=_t( 'Memo' );?></th>
										<th><font color="red">*</font> <?=_t( 'Amount' );?></th>
										<th>&nbsp;</th>
									</tr>
								</thead>
								<!-- // Table heading END -->
								
								<!-- Table body -->
								<tbody>
									
									<!-- Table row -->
									<tr class="item-row">
										<td class="item-name" style="width:400px;">
											<select name="glacctID[]" class="glacctID selectpicker form-control" data-style="btn-info" data-size="10" data-live-search="true" required>
			                                    <option value="">&nbsp;</option>
			                                    <?php gl_acct_dropdown(); ?>
			                                </select>
										</td>
										<td>
											<textarea id="mustHaveId" class="form-control" name="gl_trans_memo[]" placeholder="Transaction Description" rows="2"></textarea>
										</td>
										<td>
											<input type="text" name="amount[]" class="form-control amount" required/>
										</td>
										<td><a id="addrow" href="javascript:;" title="Add a row" class="btn btn-default"><i class="fa fa-plus"></i></a></td>
									</tr>
									<!-- // Table row END -->
									
									<!-- Table row -->
									<tr>
										<td class="right" colspan="4" style="padding-right:2em;">
											<?=_t( 'Debit: ');?> <span style="font-weight:600;" id="debit_amnt">0.00</span><br />
							                <?=_t( 'Credit: ');?> <span style="font-weight:600;" id="credit_amnt">0.00</span> <br />
							                <?=_t( 'Balance:' );?>  <span style="font-weight:600;" id="balance_amnt">0.00</span><br />
										</td>
									</tr>
									<!-- // Table row END -->
									
								</tbody>
								<!-- // Table body END -->
								
							</table>
							<!-- // Table END -->
                        <!-- // Group END -->
                        
                    </div>
                    <!-- // Column END -->
                    
                </div>
                <!-- // Row END -->
                
                <hr class="separator" />
                
                <!-- Form actions -->
                <div class="form-actions">
                    <button type="submit" class="btn btn-icon btn-primary glyphicons circle_ok"><i></i><?=_t( 'Submit' );?></button>
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