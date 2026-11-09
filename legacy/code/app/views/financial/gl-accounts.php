<?php if ( ! defined('BASE_PATH') ) exit('No direct script access allowed');
/**
 * General Ledger Accounts View
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
$screen = 'genl';
?>

<ul class="breadcrumb">
	<li><?=_t( 'You are here' );?></li>
	<li><a href="<?=get_base_url();?>dashboard/" class="glyphicons dashboard"><i></i> <?=_t( 'Dashboard' );?></a></li>
	<li class="divider"></li>
	<li><?=_t( 'GL Accounts' );?></li>
</ul>

<h3>
	<?=_t( 'Chart of Accounts' );?> 
	<a href="#addglAccount" data-toggle="modal" title="Add New Account" class="btn btn-default"><i class="fa fa-plus"></i></a>
</h3>
<div class="innerLR">
    
    <?=_etsis_flash()->showMessage();?>
    
    <?php jstree_sidebar_menu($screen); ?>

	<!-- Widget -->
	<div class="widget widget-heading-simple widget-body-gray <?=($app->hook->has_filter('sidebar_menu')) ? 'col-md-12' : 'col-md-10';?>">
        
        <!-- Tabs Heading -->
        <div class="tabsbar">
            <ul>
                <li class="glyphicons charts active"><a href="<?=get_base_url();?>financial/gl-accounts/" data-toggle="tab"><i></i> <?=_t( 'GL Accounts' );?></a></li>
                <li class="glyphicons search"><a href="<?=get_base_url();?>financial/jentry-filter/"><i></i> <?=_t( 'Journal Entries' );?></a></li>
                <li class="glyphicons search tab-stacked"><a href="<?=get_base_url();?>financial/gl-filter/"><i></i> <span><?=_t( 'General Ledger Summary' );?></span></a></li>
            </ul>
        </div>
        <!-- // Tabs Heading END -->
        
		<div class="widget-body">
			
			<!-- Table -->
			<table class="dynamicTable tableTools table table-striped table-bordered table-condensed table-white">
			
				<!-- Table heading -->
				<thead>
					<tr>
						<th class="center"><?=_t( 'Account Number' );?></th>
						<th class="center"><?=_t( 'Name' );?></th>
						<th class="center"><?=_t( 'Type' );?></th>
						<th class="center"><?=_t( 'Balance' );?></th>
						<th class="center"><?=_t( 'Actions' );?></th>
					</tr>
				</thead>
				<!-- // Table heading END -->
				
				<!-- Table body -->
				<tbody>
				<?php if($gl != '') : foreach($gl as $k => $v) { ?>
				<?php $balance = 0+_h($v['gl_trans_debit'])+_h($v['gl_trans_credit']); ?>
                <tr class="gradeX">
                    <td class="center"><?=_h($v['gl_acct_number']);?></td>
                    <td class="center"><?=_h($v['gl_acct_name']);?></td>
                    <td class="center"><?=_h($v['gl_acct_type']);?></td>
                    <?php if($balance < 0) : ?>
                    <td class="center" style="color:red;"><?=money_format("%i",(double)$balance);?></td>
                    <?php else : ?>
                	<td class="center"><?=money_format("%i",(double)$balance);?></td>
                	<?php endif; ?>
                    <td class="center">
                    	<!--<a href="<?=get_base_url();?>financial/view_ledger/" title="View Ledger" class="btn btn-circle"><i class="icon-eye-open"></i></a>-->
                    	<a href="#viewglAccount<?=_h($v['glacctID']);?>" data-toggle="modal" title="Edit Account" class="btn btn-default"><i class="fa fa-edit"></i></a>
                    </td>
                </tr>
				<?php } endif; ?>
				</tbody>
				<!-- // Table body END -->
				
			</table>
			<!-- // Table END -->
			
		</div>
	</div>
	<div class="separator bottom"></div>
    
    <!-- Form -->
    <form class="form-horizontal margin-none" action="<?=get_base_url();?>financial/gl-accounts/" id="validateSubmitForm" method="post" autocomplete="off">
    <div class="modal fade" id="addglAccount">
    	<div class="modal-dialog">
			<div class="modal-content">
				<!-- Modal heading -->
				<div class="modal-header">
					<button type="button" class="close" data-dismiss="modal" aria-hidden="true">&times;</button>
					<h3 class="modal-title"><?=_t( 'Account' );?></h3>
				</div>
				<!-- // Modal heading END -->
		        <div class="modal-body">
		            <!-- Group -->
		            <div class="form-group">
		                <label class="col-md-3 control-label"><font color="red">*</font> <?=_t( 'Account #' );?></label>
		                <div class="col-md-8">
		                    <input type="text" name="gl_acct_number" id="gl_acct_number" class="form-control" required />
		                </div>
		            </div>
		            <!-- // Group END -->
		            
		            <!-- Group -->
		            <div class="form-group">
		                <label class="col-md-3 control-label"><font color="red">*</font> <?=_t( 'Account Name' );?></label>
		                <div class="col-md-8">
		                    <input type="text" name="gl_acct_name" id="gl_acct_name" class="form-control" required />
		                </div>
		            </div>
		            <!-- // Group END -->
		            
		            <!-- Group -->
		            <div class="form-group">
		                <label class="col-md-3 control-label"><font color="red">*</font> <?=_t( 'Account Type' );?></label>
		                <div class="col-md-8">
		                    <?=general_ledger_type_select();?>
		                </div>
		            </div>
		            <!-- // Group END -->
		            
		            <!-- Group -->
		            <div class="form-group">
		                <label class="col-md-3 control-label"><?=_t( 'Memo' );?></label>
		                <div class="col-md-8">
		                    <textarea id="mustHaveId" class="form-control" name="gl_acct_memo" rows="2"></textarea>
		                </div>
		            </div>
		            <!-- // Group END -->
		        </div>
		        <div class="modal-footer">
		        	<button type="submit" class="btn btn-default"><?=_t( 'Save' );?></button>
		            <button data-dismiss="modal" class="btn btn-primary"><?=_t( 'Cancel' );?></button>
		        </div>
    		</div>
	   </div>
    </div>
    </form>
    <!-- Form -->
    
    <?php if($gl != '') : foreach($gl as $k => $v) { ?>
    <!-- Form -->
    <form class="form-horizontal margin-none" action="<?=get_base_url();?>financial/gl-accounts/" id="validateSubmitForm" method="post" autocomplete="off">
    <div class="modal fade" id="viewglAccount<?=_h($v['glacctID']);?>">
    	<div class="modal-dialog">
			<div class="modal-content">
				<!-- Modal heading -->
				<div class="modal-header">
					<button type="button" class="close" data-dismiss="modal" aria-hidden="true">&times;</button>
					<h3 class="modal-title"><?=_t( 'Account' );?></h3>
				</div>
				<!-- // Modal heading END -->
		        <div class="modal-body">
		            <!-- Group -->
		            <div class="form-group">
		                <label class="col-md-3 control-label"><font color="red">*</font> <?=_t( 'Account #' );?></label>
		                <div class="col-md-8">
		                    <input type="text" name="gl_acct_number" id="gl_acct_number" class="form-control" value="<?=$v['gl_acct_number'];?>" required />
		                </div>
		            </div>
		            <!-- // Group END -->
		            
		            <!-- Group -->
		            <div class="form-group">
		                <label class="col-md-3 control-label"><font color="red">*</font> <?=_t( 'Account Name' );?></label>
		                <div class="col-md-8">
		                    <input type="text" name="gl_acct_name" id="gl_acct_name" class="form-control" value="<?=$v['gl_acct_name'];?>" required />
		                </div>
		            </div>
		            <!-- // Group END -->
		            
		            <!-- Group -->
		            <div class="form-group">
		                <label class="col-md-3 control-label"><font color="red">*</font> <?=_t( 'Account Type' );?></label>
		                <div class="col-md-8">
		                    <?=general_ledger_type_select($v['gl_acct_type']);?>
		                </div>
		            </div>
		            <!-- // Group END -->
		            
		            <!-- Group -->
		            <div class="form-group">
		                <label class="col-md-3 control-label"><?=_t( 'Memo' );?></label>
		                <div class="col-md-8">
		                    <textarea id="mustHaveId" class="form-control" name="gl_acct_memo" rows="2"><?=$v['gl_acct_memo'];?></textarea>
		                </div>
		            </div>
		            <!-- // Group END -->
		        </div>
		        <div class="modal-footer">
                    <input type="hidden" name="glacctID" value="<?=_h($v['glacctID']);?>" />
		        	<button type="submit" class="btn btn-default"><?=_t( 'Update' );?></button>
		            <button data-dismiss="modal" class="btn btn-primary"><?=_t( 'Cancel' );?></button>
		        </div>
    		</div>
    	</div>
    </div>
    </form>
    <!-- Form -->
    <?php } endif; ?>
	
	<!-- // Widget END -->
	
</div>	
	
		
		</div>
		<!-- // Content END -->
<?php $app->view->stop(); ?>