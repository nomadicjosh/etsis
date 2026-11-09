<?php if ( ! defined('BASE_PATH') ) exit('No direct script access allowed');
/**
 * View Billing Table View
 *  
 * @license GPLv3
 * 
 * @since       6.3.1
 * @package     eduTrac SIS
 * @author      Joshua Parker <joshmac3@icloud.com>
 */

$app = \Liten\Liten::getInstance();
$app->view->extend('_layouts/dashboard');
$app->view->block('dashboard');
$screen = 'btbl';
?>

<ul class="breadcrumb">
	<li><?=_t( 'You are here');?></li>
	<li><a href="<?=get_base_url();?>dashboard/" class="glyphicons dashboard"><i></i> <?=_t( 'Dashboard' );?></a></li>
	<li class="divider"></li>
    <li><a href="<?=get_base_url();?>financial/billing-table/"><i class="fa fa-table"></i> <?=_t( 'Billing Table' );?></a></li>
	<li class="divider"></li>
	<li><?=_h($btbl->name);?></li>
</ul>

<h3><?=_h($btbl->name);?> (<?=_h($btbl->FeeType);?>)</h3>
<div class="innerLR">
    
    <?=_etsis_flash()->showMessage();?>
    
    <?php jstree_sidebar_menu($screen); ?>

	<!-- Form -->
	<form class="form-horizontal margin-none" action="<?=get_base_url();?>financial/billing-table/<?=_h($btbl->id);?>/" id="validateSubmitForm" method="post" autocomplete="off">
		
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
							<label class="col-md-3 control-label" for="name"><font color="red">*</font> <?=_t( 'Name' );?></label>
							<div class="col-md-8"><input class="form-control"<?=saio();?> id="name" name="name" type="text" value="<?=_h($btbl->name);?>" required /></div>
						</div>
						<!-- // Group END -->
						
						<!-- Group -->
						<div class="form-group">
							<label class="col-md-3 control-label" for="amount"><font color="red">*</font> <?=_t( 'Amount' );?></label>
							<div class="col-md-8">
                                <input class="form-control"<?=saio();?> name="amount" type="text" placeholder="100,000" value="<?=money_format("%i",(double)_h($btbl->amount));?>" required />
						    </div>
						</div>
						<!-- // Group END -->
                        
                        <!-- Group -->
                        <div class="form-group">
                            <label class="col-md-3 control-label" for="type"><font color="red">*</font> <?=_t( 'Type' );?></label>
                            <div class="col-md-8">
                                <select name="type"<?=saio();?> class="selectpicker form-control" data-style="btn-info" data-size="10" data-live-search="true" required>
                                    <option value="">&nbsp;</option>
                                    <option value="F"<?=selected('F',_h($btbl->type),false);?>><?=_t( 'Fee' );?></option>
                                    <option value="T"<?=selected('T',_h($btbl->type),false);?>><?=_t( 'Tuition' );?></option>
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
                            <label class="col-md-3 control-label" for="term"><?=_t( 'Term' );?></label>
                            <div class="col-md-8">
                                <input type="hidden"<?=saio();?> id="input-tags" name="term" value="<?=_h($btbl->term);?>" />
                            </div>
                        </div>
                        <!-- // Group END -->
                        
                        <!-- Group -->
                        <div class="form-group">
                            <label class="col-md-3 control-label" for="status"><font color="red">*</font> <?=_t( 'Status' );?></label>
                            <div class="col-md-8">
                                <select name="status"<?=saio();?> class="selectpicker form-control" data-style="btn-info" data-size="10" data-live-search="true" required>
                                    <option value="">&nbsp;</option>
                                    <option value="A"<?=selected('A', _h($btbl->status), false);?>><?=_t( 'Active' );?></option>
                                    <option value="I"<?=selected('I', _h($btbl->status), false);?>><?=_t( 'Inactive' );?></option>
                                </select>
                            </div>
                        </div>
                        <!-- // Group END -->
						
					</div>
					<!-- // Column END -->
					
				</div>
				<!-- // Row END -->
			
				<hr class="separator" />
                
                <div class="separator bottom"></div>
				
				<!-- Form actions -->
				<div class="form-actions">
					<button type="submit"<?=saids();?> class="btn btn-icon btn-primary glyphicons circle_ok"><i></i><?=_t( 'Submit' );?></button>
                    <button type="button" class="btn btn-primary" onclick="window.location='<?=get_base_url();?>financial/billing-table/'"><i></i><?=_t( 'Cancel' );?></button>
				</div>
				<!-- // Form actions END -->
				
			</div>
		</div>
		<!-- // Widget END -->
		
	</form>
	<!-- // Form END -->
	
</div>

<script src="<?=get_base_url();?>static/assets/components/modules/querybuilder/selectize/js/standalone/selectize.min.js" type="text/javascript"></script>
<script type="text/javascript">
$('#input-tags').selectize({
    plugins: ['remove_button'],
    delimiter: ',',
    persist: false,
    maxItems: null,
    valueField: 'code',
    labelField: 'name',
    searchField: ['name','code'],
    options: [
        <?php get_btbl_terms(); ?>
    ],
    rrender: {
        item: function(item, escape) {
            return '<div>' +
                (item.name ? '<span class="name">' + escape(item.name) + '</span>' : '') +
                (item.code ? '<span class="code">' + escape(item.code) + '</span>' : '') +
            '</div>';
        },
        option: function(item, escape) {
            var label = item.name || item.code;
            var caption = item.name ? item.code : null;
            return '<div>' +
                '<span class="label">' + escape(label) + '</span>' +
                (caption ? '<span class="caption">' + escape(caption) + '</span>' : '') +
            '</div>';
        }
    },
    create: function(input) {
        return {
            code: input
        };
    }
});
</script>
		
		</div>
		<!-- // Content END -->
<?php $app->view->stop(); ?>