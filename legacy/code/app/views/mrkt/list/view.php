<?php if ( ! defined('BASE_PATH') ) exit('No direct script access allowed');
/**
 * Create Campaign View
 *  
 * @license GPLv3
 * 
 * @since       6.3.0
 * @package     eduTrac SIS
 * @author      Joshua Parker <joshmac3@icloud.com>
 */
$app = \Liten\Liten::getInstance();
$app->view->extend('_layouts/dashboard');
$app->view->block('dashboard');
$screen = 'lists';
?>

<ul class="breadcrumb">
	<li><?=_t( 'You are here' );?></li>
	<li><a href="<?=get_base_url();?>dashboard/" class="glyphicons dashboard"><i></i> <?=_t( 'Dashboard' );?></a></li>
	<li class="divider"></li>
	<li><a href="<?=get_base_url();?>mrkt/" class="glyphicons inbox"><i></i> <?=_t( 'Campaigns' );?></a></li>
	<li class="divider"></li>
    <li><a href="<?=get_base_url();?>mrkt/list/" class="glyphicons list"><i></i> <?=_t( 'Email Lists' );?></a></li>
	<li class="divider"></li>
	<li><?= _t('View/Edit Email List'); ?></li>
</ul>

<h3><?= _t('View/Edit Email List'); ?></h3>
<div class="innerLR">
    
    <?=_etsis_flash()->showMessage();?>

    <?php jstree_sidebar_menu($screen); ?>
	
	<!-- Form -->
	<form class="form-horizontal margin-none" action="<?=get_base_url();?>mrkt/list/<?=_h((int)$list->id);?>/" id="validateSubmitForm" method="post">
		
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
							<label class="col-md-3 control-label"><?=_t( 'Code' );?></label>
							<div class="col-md-8">
                                <input type="text" class="form-control" value="<?=_h($list->code);?>" readonly required>
                            </div>
						</div>
						<!-- // Group END -->
						
						<!-- Group -->
						<div class="form-group">
							<label class="col-md-3 control-label"><font color="red">*</font> <?=_t( 'List Name' );?></label>
							<div class="col-md-8">
                                <input type="text" class="form-control" name="name" value="<?=_h($list->name);?>" required>
                            </div>
						</div>
						<!-- // Group END -->
                        
                        <!-- Group -->
                        <div class="form-group">
                            <label class="col-md-3 control-label"><?=_t( 'Short Description' );?></label>
                            <div class="col-md-8">
                                <textarea class="form-control" rows="3" name="description"><?=_h($list->description);?></textarea>
                            </div>
                        </div>
						<!-- // Group END -->
						
					</div>
					<!-- // Column END -->
                    
                    <!-- Column -->
					<div class="col-md-6">
						
						<!-- Group -->
						<div class="form-group">
							<label class="col-md-3 control-label"><font color="red">*</font> <?=_t( 'Status' );?> <a href="#status" data-toggle="modal"><span class="badge btn-primary"><i class="fa fa-question"></i></span></a></label>
							<div class="col-md-8">
                                <select name="status" class="selectpicker form-control" data-style="btn-info" data-size="10" data-live-search="true" required>
                                    <option>&nbsp;</option>
                                    <option value="open"<?=selected('open', _h($list->status), false);?>><?=_t('Open');?></option>
                                    <option value="closed"<?=selected('closed', _h($list->status), false);?>><?=_t('Closed');?></option>
                                </select>
                            </div>
						</div>
						<!-- // Group END -->
                        
                        <!-- Group -->
                        <div class="form-group">
                            <label class="col-md-3 control-label"><?=_t( 'Owner' );?></label>
                            <div class="col-md-8">
                                <input type="text" class="form-control" value="<?=get_name(_h((int)$list->owner));?>" readonly>
                            </div>
                        </div>
						<!-- // Group END -->
                        
                        <!-- Group -->
                        <div class="form-group">
                            <label class="col-md-3 control-label"><?=_t( 'Short Description' );?></label>
                            <div class="col-md-8">
                                <input type="text" class="form-control" value="<?= Jenssegers\Date\Date::parse(_h($list->LastUpdate))->format('M. d, Y @ h:i A');?>" readonly>
                            </div>
                        </div>
						<!-- // Group END -->
						
					</div>
					<!-- // Column END -->
                    
                    <!-- Column -->
					<div class="col-md-12">
                        <h3><?=_t('List Rule');?></h3>
                        <!-- Group -->
                        <div class="form-group">
                            <div class="col-md-12">
                                <div id="builder"></div>
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
                    <div class="btn-group">
                        <div id="result" class="hide">
                            <textarea id="rldeRule" style="resize: none;height:10em; width:800px;" name="rule" class="rldeRule form-control" readonly="readonly" required></textarea>
                            <button type="submit" class="btn btn-success"><?=_t( 'Save' );?></button><br /><br />
                         </div>
                    <a class="btn btn-danger reset"><?=_t( 'Reset' );?></a>
                    <a class="btn btn-primary parse-sql" data-stmt="false"><?=_t( 'Load Rule' );?></a>
                    <?php if(_escape($list->rule) != null) : ?>
                    <button type="button" class="btn btn-inverse" onclick="window.location='<?=get_base_url();?>mrkt/list/<?=_escape($list->id);?>/c/'"><i></i><?=_t( 'Clear' );?></button>
                    <?php endif; ?>
                    <button type="button" class="btn btn-primary" onclick="window.location='<?=get_base_url();?>mrkt/list/'"><i></i><?=_t( 'Cancel' );?></button>
                    </div>
				</div>
				<!-- // Form actions END -->
				
			</div>
		</div>
		<!-- // Widget END -->
		
	</form>
	<!-- // Form END -->
	<div class="separator bottom"></div>
    
    <!-- modal -->
    <div class="modal" id="status">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span></button>
                    <h4 class="modal-title"><?=_t( 'Status' );?></h4>
                </div>
                <div class="modal-body">
                    <p><?=_t( "Email can only be sent to lists that are open." );?></p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-default pull-left" data-dismiss="modal"><?= _t('Close'); ?></button>
                </div>
            </div>
            <!-- /.modal-content -->
        </div>
        <!-- /.modal-dialog -->
    </div>
    <!-- /.modal -->
    
	<!-- // Widget END -->
	
</div>

<script type="text/javascript" src="<?=get_base_url();?>static/assets/components/modules/querybuilder/bootstrap-select/js/bootstrap-select.min.js"></script>
<script type="text/javascript" src="<?=get_base_url();?>static/assets/components/modules/querybuilder/bootbox/bootbox.js"></script>
<script type="text/javascript" src="<?=get_base_url();?>static/assets/components/modules/querybuilder/selectize/js/standalone/selectize.min.js"></script>
<script type="text/javascript" src="<?=get_base_url();?>static/assets/components/modules/querybuilder/jquery-extendext/jQuery.extendext.min.js"></script>
<script type="text/javascript" src="<?=get_base_url();?>static/assets/components/modules/querybuilder/sql-parser/browser/sql-parser.js"></script>
<script type="text/javascript" src="<?=get_base_url();?>static/assets/components/modules/querybuilder/doT/doT.js"></script>
<script type="text/javascript" src="<?=get_base_url();?>static/assets/components/modules/querybuilder/interact/interact.js"></script>
<script type="text/javascript" src="<?=get_base_url();?>static/assets/components/modules/querybuilder/js/query-builder.js"></script>
<script type="text/javascript" src="<?=get_base_url();?>static/assets/components/modules/momentjs/moment.js"></script>
<script type="text/javascript" src="<?=get_base_url();?>static/assets/components/modules/bootstrap-datepicker/js/bootstrap-datepicker.js"></script>

<script>
$('[data-toggle="tooltip"]').tooltip();
<?php if(!empty($list->rule)) : ?>
var sql_import_export = "<?=$list->rule;?>";
<?php endif; ?>

// Fix for Selectize
$('#builder').on('afterCreateRuleInput.queryBuilder', function(e, rule) {
  if (rule.filter.plugin == 'selectize') {
    rule.$el.find('.rule-value-container').css('min-width', '200px')
      .find('.selectize-control').removeClass('form-control');
  }
});

var options = {
  allow_empty: true,

  //default_filter: 'name',
  sort_filters: true,

  optgroups: {
    nae: {
      en: 'Person'
    },
    staf: {
      en: 'Staff'
    },
    stu: {
      en: 'Student'
    },
    stal: {
      en: 'Student Academic Level'
    }
  },

  plugins: {
    'bt-tooltip-errors': { delay: 100},
    'sortable': null,
    'filter-description': { mode: 'bootbox' },
    'bt-selectpicker': null,
    'unique-filter': null,
    'bt-checkbox': { color: 'primary' },
    'invert': null,
    'not-group': null
  },

  // standard operators in custom optgroups
  operators: [
    {type: 'equal',            optgroup: 'basic'},
    {type: 'not_equal',        optgroup: 'basic'},
    {type: 'in',               optgroup: 'basic'},
    {type: 'not_in',           optgroup: 'basic'},
    {type: 'less',             optgroup: 'numbers'},
    {type: 'less_or_equal',    optgroup: 'numbers'},
    {type: 'greater',          optgroup: 'numbers'},
    {type: 'greater_or_equal', optgroup: 'numbers'},
    {type: 'between',          optgroup: 'numbers'},
    {type: 'not_between',      optgroup: 'numbers'},
    {type: 'begins_with',      optgroup: 'strings'},
    {type: 'not_begins_with',  optgroup: 'strings'},
    {type: 'contains',         optgroup: 'strings'},
    {type: 'not_contains',     optgroup: 'strings'},
    {type: 'ends_with',        optgroup: 'strings'},
    {type: 'not_ends_with',    optgroup: 'strings'},
    {type: 'is_empty'     },
    {type: 'is_not_empty' },
    {type: 'is_null'      },
    {type: 'is_not_null'  }
  ],

  filters: [
  /*
   * Person
   */
  {
    id: 'person.personType',
    label: 'Person Type',
    type: 'string',
    input: 'select',
    plugin: 'selectize',
    multiple: true,
    plugin_config: {
      valueField: 'id',
      labelField: 'name',
      searchField: 'name',
      sortField: 'name',
      create: true,
      plugins: ['remove_button']
    },
    values: {
        "FAC": "(FAC) Faculty",
        "ADJ": "(ADJ) Adjunct",
        "STA": "(STA) Staff",
        "APL": "(APL) Applicant",
        "STU": "(STU) Student"
    },
    valueSetter: function (rule, value) {
        rule.$el.find('.rule-value-container select')[0].selectize.setValue(value);
    },
    optgroup: 'nae'
  },
  {
    id: 'person.gender',
    label: 'Gender',
    type: 'string',
    input: 'select',
    plugin: 'selectize',
    multiple: true,
    plugin_config: {
      valueField: 'id',
      labelField: 'name',
      searchField: 'name',
      sortField: 'name',
      create: true,
      plugins: ['remove_button']
    },
    values: {
        "M": "(M) Male",
        "F": "(F) Female"
    },
    valueSetter: function (rule, value) {
        rule.$el.find('.rule-value-container select')[0].selectize.setValue(value);
    },
    optgroup: 'nae'
  },
  {
    id: 'person.veteran',
    label: 'Veteran',
    type: 'string',
    input: 'select',
    plugin: 'selectize',
    plugin_config: {
      valueField: 'id',
      labelField: 'name',
      searchField: 'name',
      sortField: 'name',
      create: true,
      plugins: ['remove_button']
    },
    values: {
        "1": "Yes",
        "0": "No"
    },
    valueSetter: function (rule, value) {
        rule.$el.find('.rule-value-container select')[0].selectize.setValue(value);
    },
    optgroup: 'nae'
  },
  {
    id: 'person.status',
    label: 'Status',
    type: 'string',
    input: 'select',
    plugin: 'selectize',
    plugin_config: {
      valueField: 'id',
      labelField: 'name',
      searchField: 'name',
      sortField: 'name',
      create: true,
      plugins: ['remove_button']
    },
    values: {
        "A": "(A) Active",
        "I": "(I) Inactive"
    },
    valueSetter: function (rule, value) {
        rule.$el.find('.rule-value-container select')[0].selectize.setValue(value);
    },
    optgroup: 'nae'
  },
  {
    id: 'person.tags',
    label: 'Tag',
    type: 'string',
    input: 'select',
    plugin: 'selectize',
    multiple: true,
    plugin_config: {
      valueField: 'id',
      labelField: 'name',
      searchField: 'name',
      sortField: 'name',
      create: true,
      plugins: ['remove_button']
    },
    values: {
        <?php get_rlde_person_tags(); ?>
    },
    valueSetter: function (rule, value) {
        rule.$el.find('.rule-value-container select')[0].selectize.setValue(value);
    },
    optgroup: 'nae'
  },
  /*
   * Staff
   */
  {
    id: 'staff.deptCode',
    label: 'Department',
    type: 'string',
    input: 'select',
    plugin: 'selectize',
    multiple: true,
    plugin_config: {
      valueField: 'id',
      labelField: 'name',
      searchField: 'name',
      sortField: 'name',
      create: true,
      plugins: ['remove_button']
    },
    values: {
        <?php get_rlde_dept(); ?>
    },
    valueSetter: function (rule, value) {
        rule.$el.find('.rule-value-container select')[0].selectize.setValue(value);
    },
    optgroup: 'staf'
  },
  {
    id: 'staff.status',
    label: 'Status',
    type: 'string',
    input: 'select',
    plugin: 'selectize',
    plugin_config: {
      valueField: 'id',
      labelField: 'name',
      searchField: 'name',
      sortField: 'name',
      create: true,
      plugins: ['remove_button']
    },
    values: {
        "A": "(A) Active",
        "I": "(I) Inactive"
    },
    valueSetter: function (rule, value) {
        rule.$el.find('.rule-value-container select')[0].selectize.setValue(value);
    },
    optgroup: 'staf'
  },
  {
    id: 'staff.tags',
    label: 'Tag',
    type: 'string',
    input: 'select',
    plugin: 'selectize',
    multiple: true,
    plugin_config: {
      valueField: 'id',
      labelField: 'name',
      searchField: 'name',
      sortField: 'name',
      create: true,
      plugins: ['remove_button']
    },
    values: {
        <?php get_rlde_staff_tags(); ?>
    },
    valueSetter: function (rule, value) {
        rule.$el.find('.rule-value-container select')[0].selectize.setValue(value);
    },
    optgroup: 'staf'
  },
  /*
   * Student
   */
  {
    id: 'student.status',
    label: 'Status',
    type: 'string',
    input: 'select',
    plugin: 'selectize',
    plugin_config: {
      valueField: 'id',
      labelField: 'name',
      searchField: 'name',
      sortField: 'name',
      create: true,
      plugins: ['remove_button']
    },
    values: {
        "A": "(A) Active",
        "I": "(I) Inactive"
    },
    valueSetter: function (rule, value) {
        rule.$el.find('.rule-value-container select')[0].selectize.setValue(value);
    },
    optgroup: 'stu'
  },
  /*
   * Student Academic Level
   */
  {
    id: 'stal.acadProgCode',
    label: 'Academic Program',
    type: 'string',
    input: 'select',
    plugin: 'selectize',
    multiple: true,
    plugin_config: {
      valueField: 'id',
      labelField: 'name',
      searchField: 'name',
      sortField: 'name',
      create: true,
      plugins: ['remove_button']
    },
    values: {
        <?php get_rlde_prog(); ?>
    },
    valueSetter: function (rule, value) {
        rule.$el.find('.rule-value-container select')[0].selectize.setValue(value);
    },
    optgroup: 'stal'
  },
  {
    id: 'stal.acadLevelCode',
    label: 'Academic Level',
    type: 'string',
    input: 'select',
    plugin: 'selectize',
    multiple: true,
    plugin_config: {
      valueField: 'id',
      labelField: 'name',
      searchField: 'name',
      sortField: 'name',
      create: true,
      plugins: ['remove_button']
    },
    values: {
        <?php get_rlde_acad_levels(); ?>
    },
    valueSetter: function (rule, value) {
        rule.$el.find('.rule-value-container select')[0].selectize.setValue(value);
    },
    optgroup: 'stal'
  },
  {
    id: 'stal.currentClassLevel',
    label: 'Class Level',
    type: 'string',
    input: 'select',
    plugin: 'selectize',
    multiple: true,
    plugin_config: {
      valueField: 'id',
      labelField: 'name',
      searchField: 'name',
      sortField: 'name',
      create: true,
      plugins: ['remove_button']
    },
    values: {
        <?php get_rlde_clas_levels(); ?>
    },
    valueSetter: function (rule, value) {
        rule.$el.find('.rule-value-container select')[0].selectize.setValue(value);
    },
    optgroup: 'stal'
  },
  {
    id: 'stal.acadStanding',
    label: 'Academic Standing',
    type: 'string',
    input: 'select',
    plugin: 'selectize',
    multiple: true,
    plugin_config: {
      valueField: 'id',
      labelField: 'name',
      searchField: 'name',
      sortField: 'name',
      create: true,
      plugins: ['remove_button']
    },
    values: {
        "GOOD": "(GOOD) Good",
        "PROB": "(PROB) Probation"
    },
    valueSetter: function (rule, value) {
        rule.$el.find('.rule-value-container select')[0].selectize.setValue(value);
    },
    optgroup: 'stal'
  },
  {
    id: 'stal.startTerm',
    label: 'Start Term',
    type: 'string',
    input: 'select',
    plugin: 'selectize',
    multiple: true,
    plugin_config: {
      valueField: 'id',
      labelField: 'name',
      searchField: 'name',
      sortField: 'name',
      create: true,
      plugins: ['remove_button']
    },
    values: {
        <?php get_rlde_terms(); ?>
    },
    valueSetter: function (rule, value) {
        rule.$el.find('.rule-value-container select')[0].selectize.setValue(value);
    },
    optgroup: 'stal'
  },
  {
    id: 'stal.startDate',
    label: 'ACLV Start Date',
    type: 'date',
    plugin: 'datepicker',
    plugin_config: {
      format: 'yyyy-mm-dd',
      todayBtn: 'linked',
      todayHighlight: true,
      autoclose: true
    },
    optgroup: 'stal'
  },
  {
    id: 'stal.endDate',
    label: 'ACLV End Date',
    type: 'date',
    plugin: 'datepicker',
    plugin_config: {
      format: 'yyyy-mm-dd',
      todayBtn: 'linked',
      todayHighlight: true,
      autoclose: true
    },
    optgroup: 'stal'
  }
  ]
};

// init
$('#builder').queryBuilder(options);
<?php if(!empty($list->rule)) : ?>
$('#builder').queryBuilder('setRulesFromSQL', sql_import_export);
<?php endif; ?>

// reset builder
$('.reset').on('click', function() {
  $('#builder').queryBuilder('reset');
  $('#result').addClass('hide').find('.rldeRule').empty();
});

$('.parse-sql').on('click', function() {
  var res = $('#builder').queryBuilder('getSQL', $(this).data('stmt'), false);
  $('#result').removeClass('hide')
    .find('.rldeRule').val(
      res.sql + (res.params ? '\n\n' + JSON.stringify(res.params, undefined, 2) : '')
    );
});
</script>
	
		
		</div>
		<!-- // Content END -->
<?php $app->view->stop(); ?>