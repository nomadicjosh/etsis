<?php if ( ! defined('BASE_PATH') ) exit('No direct script access allowed');
/**
 * Paypal Settings View
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
$screen = 'ppal';
?>

<ul class="breadcrumb">
	<li><?=_t( 'You are here' );?></li>
	<li><a href="<?=get_base_url();?>dashboard/" class="glyphicons dashboard"><i></i> <?=_t( 'Dashboard' );?></a></li>
	<li class="divider"></li>
	<li><?=_t( 'Paypal Settings' );?></li>
</ul>

<h3><?=_t( 'Paypal Settings' );?></h3>
<div class="innerLR">
    
    <?=_etsis_flash()->showMessage();?>

	<?php jstree_sidebar_menu($screen); ?>

	<!-- Form -->
	<form class="form-horizontal margin-none" action="<?=get_base_url();?>financial/paypal/" id="validateSubmitForm" method="post" autocomplete="off">
		
		<!-- Widget -->
		<div class="widget widget-heading-simple widget-body-gray <?=($app->hook->has_filter('sidebar_menu')) ? 'col-md-12' : 'col-md-10';?>">
		
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
							<label class="col-md-3 control-label"><font color="red">*</font> <?=_t( 'Enable?' );?></label>
							<div class="col-md-8">
								<select name="enable_paypal_gateway" class="selectpicker form-control" data-style="btn-info" data-size="10" data-live-search="true" required>
                            		<option value="">&nbsp;</option>
                            		<option value="1"<?=selected( _h(get_option( 'enable_paypal_gateway' )), '1', false ); ?>><?=_t( "Yes" );?></option>
                                	<option value="0"<?=selected( _h(get_option( 'enable_paypal_gateway' )), '0', false ); ?>><?=_t( "No" );?></option>
                            	</select>
							</div>
						</div>
						<!-- // Group END -->
						
						<!-- Group -->
                        <div class="form-group">
                            <label class="col-md-3 control-label"><font color="red">*</font> <?=_t( 'Post Over SSL?' );?></label>
                            <div class="col-md-8">
                                <select name="paypal_use_ssl" class="selectpicker form-control" data-style="btn-info" data-size="10" data-live-search="true" required>
                                    <option value="">&nbsp;</option>
                                    <option value="true"<?=selected( _h(get_option( 'paypal_use_ssl' )), 'true', false ); ?>><?=_t( "Yes" );?></option>
                                    <option value="false"<?=selected( _h(get_option( 'paypal_use_ssl' )), 'false', false ); ?>><?=_t( "No" );?></option>
                                </select>
                            </div>
                        </div>
                        <!-- // Group END -->
						
						<!-- Group -->
                        <div class="form-group">
                            <label class="col-md-3 control-label"><font color="red">*</font> <?=_t( 'Business Email' );?> <a href="#modal1" data-toggle="modal"><img src="<?=get_base_url();?>static/common/theme/images/help.png" /></a></label>
                            <div class="col-md-8">
                                <input type="text" name="paypal_business_email" class="form-control" value="<?=_h(get_option('paypal_business_email'));?>" required/> 
                            </div>
                        </div>
                        <!-- // Group END -->
						
					</div>
					<!-- // Column END -->
					
					<!-- Column -->
					<div class="col-md-6">
						
						<!-- Group -->
                        <div class="form-group">
                            <label class="col-md-3 control-label"><font color="red">*</font> <?=_t( 'Currency Code' );?></label>
                            <div class="col-md-8">
                                <select name="paypal_currency_code" class="selectpicker form-control" data-style="btn-info" data-size="10" data-live-search="true" required>
                                    <option value="">&nbsp;</option>
                                    <option value="USD"<?=selected( _h(get_option( 'paypal_currency_code' )), 'USD', false ); ?>><?=_t( "US Dollar" );?></option>
                                    <option value="AUD"<?=selected( _h(get_option( 'paypal_currency_code' )), 'AUD', false ); ?>><?=_t( "Austrailian Dollar" );?></option>
                                    <option value="EUR"<?=selected( _h(get_option( 'paypal_currency_code' )), 'EUR', false ); ?>><?=_t( "Euro" );?></option>
                                </select>
                            </div>
                        </div>
                        <!-- // Group END -->
                        
                        <!-- Group -->
                        <div class="form-group">
                            <label class="col-md-3 control-label"><font color="red">*</font> <?=_t( 'Testing?' );?> <a href="#modal2" data-toggle="modal"><img src="<?=get_base_url();?>static/common/theme/images/help.png" /></a></label>
                            <div class="col-md-8">
                                <select name="paypal_sandbox_mode" class="selectpicker form-control" data-style="btn-info" data-size="10" data-live-search="true" required>
                                    <option value="">&nbsp;</option>
                                    <option value="true"<?=selected( _h(get_option( 'paypal_sandbox_mode' )), 'true', false ); ?>><?=_t( "Yes" );?></option>
                                    <option value="false"<?=selected( _h(get_option( 'paypal_sandbox_mode' )), 'false', false ); ?>><?=_t( "No" );?></option>
                                </select>
                            </div>
                        </div>
                        <!-- // Group END -->
						
					</div>
					<!-- // Column END -->
				</div>
				<!-- // Row END -->
				
				<!-- Form actions -->
				<div class="form-actions">
					<button type="submit" class="btn btn-icon btn-primary glyphicons circle_ok"><i></i><?=_t( 'Save' );?></button>
				</div>
				<!-- // Form actions END -->
				
			</div>
			
			
    	    <div class="alerts alerts-info">
                    <?=_t( 'IPN URL to enter into PayPal settings:'); ?><br />
                    <strong><?=get_base_url();?>stu/paypal-ipn/</strong>
                    <p><?=_t( 'There is no need to enable IPN in PayPal. eduTrac SIS will automatically let PayPal know to use the above URL.');?></p>
            </div>
        	
		</div>
		<!-- // Widget END -->
		
	</form>
	<!-- // Form END -->
	
	<!-- Modal -->
	<div class="modal fade" id="modal1">
		<div class="modal-dialog">
			<div class="modal-content">
				<!-- Modal heading -->
				<div class="modal-header">
					<button type="button" class="close" data-dismiss="modal" aria-hidden="true">&times;</button>
					<h3 class="modal-title"><?=_t( 'Business Email' );?></h3>
				</div>
				<!-- // Modal heading END -->
				<!-- Modal body -->
				<div class="modal-body">
					<p><?=_t( 'Set this to the email that is specified on your Paypal account. If you are in testing mode, make sure to set this to the email address in your Sandbox account.' );?></p>
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
	<div class="modal fade" id="modal2">
		<div class="modal-dialog">
			<div class="modal-content">
				<!-- Modal heading -->
				<div class="modal-header">
					<button type="button" class="close" data-dismiss="modal" aria-hidden="true">&times;</button>
					<h3 class="modal-title"><?=_t( 'Sandbox Mode' );?></h3>
				</div>
				<!-- // Modal heading END -->
				<!-- Modal body -->
				<div class="modal-body">
					<p><?=_t( "If you are testing to make sure that the communication between your site and Paypal is working, set this to 'Yes' otherwise set it to 'No' for Live production." );?></p>
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