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
$screen = 'mrkt';
?>

<script src="//cdn.tinymce.com/4/tinymce.min.js"></script>
<script type="text/javascript">
    /////////////
    // TINYMCE //
    /////////////

    // Initialize TinyMCE
    tinyMCE.init({
        theme: 'modern',
        mode: 'specific_textareas',
        editor_selector: 'template',
        elements: 'message',
        extended_valid_elements: 'doctype|html|head|meta|title|link|style|body',
        height: '800',
        autosave_ask_before_unload: false,
        relative_urls: false,
        remove_script_host: false,
        plugins: [
            "advlist autolink lists link image charmap print preview anchor",
            "searchreplace visualblocks code",
            "insertdatetime media table contextmenu paste",
            "template"
        ],
        toolbar: "insertfile undo redo | styleselect | bold italic | alignleft aligncenter alignright alignjustify | bullist numlist outdent indent | link image | template | placeholder",
        invalid_elements: "script,object,embed",
        templates: [
            <?php foreach (get_dept_template()as $t) : ?>
                {"title": "<?= _h($t->name); ?>", "description": "<?= _h($t->description); ?>", "url": "<?= get_base_url() . 'mrkt/getTemplate/' . _h((int)$t->id) . '/'; ?>"},
            <?php endforeach; ?>
        ],
        file_picker_callback: elFinderBrowser,
        setup: function (editor) {
            editor.addButton('placeholder', {
                type: 'menubutton',
                text: 'Email Placeholders',
                icon: false,
                menu: [
                    {text: 'Date', onclick: function () {
                            editor.insertContent('{todays_date}');
                        }},
                    {text: 'Campaign Subject', onclick: function () {
                            editor.insertContent('{subject}');
                        }},
                    {text: 'View Online', onclick: function () {
                            editor.insertContent('{view_online}');
                        }},
                    {text: 'First Name', onclick: function () {
                            editor.insertContent('{first_name}');
                        }},
                    {text: 'Last Name', onclick: function () {
                            editor.insertContent('{last_name}');
                        }},
                    {text: 'Email', onclick: function () {
                            editor.insertContent('{email}');
                        }},
                    {text: 'Address1', onclick: function () {
                            editor.insertContent('{address1}');
                        }},
                    {text: 'Address2', onclick: function () {
                            editor.insertContent('{address2}');
                        }},
                    {text: 'City', onclick: function () {
                            editor.insertContent('{city}');
                        }},
                    {text: 'State', onclick: function () {
                            editor.insertContent('{state}');
                        }},
                    {text: 'Postal Code', onclick: function () {
                            editor.insertContent('{postal_code}');
                        }},
                    {text: 'Country', onclick: function () {
                            editor.insertContent('{country}');
                        }}
                ]
            });
        }
    });
    function elFinderBrowser(callback, value, meta) {
        tinymce.activeEditor.windowManager.open({
            file: '<?= get_base_url(); ?>mrkt/elfinder/', // use an absolute path!
            title: 'elFinder 2.0',
            width: 900,
            height: 600,
            resizable: 'yes'
        }, {
            oninsert: function (file) {
                // Provide file and text for the link dialog
                if (meta.filetype == 'file') {
//            callback('mypage.html', {text: 'My text'});
                    callback(file.url);
                }

                // Provide image and alt text for the image dialog
                if (meta.filetype == 'image') {
//            callback('myimage.jpg', {alt: 'My alt text'});
                    callback(file.url);
                }

                // Provide alternative source and posted for the media dialog
                if (meta.filetype == 'media') {
//            callback('movie.mp4', {source2: 'alt.ogg', poster: 'image.jpg'});
                    callback(file.url);
                }
            }
        });
        return false;
    };
</script>

<ul class="breadcrumb">
	<li><?=_t( 'You are here' );?></li>
	<li><a href="<?=get_base_url();?>dashboard/" class="glyphicons dashboard"><i></i> <?=_t( 'Dashboard' );?></a></li>
	<li class="divider"></li>
	<li><a href="<?=get_base_url();?>mrkt/" class="glyphicons inbox"><i></i> <?=_t( 'Campaigns' );?></a></li>
	<li class="divider"></li>
	<li><?= _t('View/Edit Campaign'); ?></li>
</ul>

<h3><?= _t('View/Edit Campaign'); ?></h3>
<div class="innerLR">
    
    <?=_etsis_flash()->showMessage();?>

    <?php jstree_sidebar_menu($screen); ?>
	
	<!-- Form -->
	<form class="form-horizontal margin-none" action="<?=get_base_url();?>mrkt/campaign/<?=_h((int)$cpgn->id);?>/" id="validateSubmitForm" method="post">
		
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
							<label class="col-md-3 control-label"><font color="red">*</font> <?=_t( 'Email Subject' );?></label>
							<div class="col-md-8">
                                <input type="text" class="form-control" name="subject" value="<?=_h($cpgn->subject);?>" required>
                            </div>
						</div>
						<!-- // Group END -->
						
						<!-- Group -->
						<div class="form-group">
							<label class="col-md-3 control-label"><font color="red">*</font> <?=_t( 'From Name' );?></label>
							<div class="col-md-8">
                                <input type="text" class="form-control" name="from_name" value="<?=_h($cpgn->from_name);?>" required>
                            </div>
						</div>
						<!-- // Group END -->
					
						<!-- Group -->
                        <div class="form-group">
                            <label class="col-md-3 control-label"><font color="red">*</font> <?=_t( 'From Email' );?></label>
                            <div class="col-md-8">
                                <input type="text" class="form-control" name="from_email" value="<?=_h($cpgn->from_email);?>" required>
                            </div>
                        </div>
						<!-- // Group END -->
                        
                        <!-- Group -->
						<div class="form-group">
                            <label class="col-md-3 control-label"><font color="red">*</font> <?= _t('Send Start'); ?></label>
							<div class="col-md-8 input-append date form_datetime">
                                <input type='text' class="form-control" name="sendstart" value="<?=_h($cpgn->sendstart);?>" required/>
                                <span class="add-on"><i class="icon-th"></i></span>
                            </div>
						</div>
						<!-- // Group END -->
                        
                        <!-- Group -->
						<div class="form-group">
							<label class="col-md-3 control-label"><font color="red">*</font> <?=_t( 'Archive?' );?></label>
							<div class="col-md-8">
                                <select name="archive" class="selectpicker form-control" data-style="btn-info" data-size="10" data-live-search="true" required>
                                    <option>&nbsp;</option>
                                    <option value="1"<?=selected('1', _h($cpgn->archive), false);?>><?=_t('Yes');?></option>
                                    <option value="0"<?=selected('0', _h($cpgn->archive), false);?>><?=_t('No');?></option>
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
							<label class="col-md-3 control-label"><font color="red">*</font> <?=_t( 'Status' );?></label>
							<div class="col-md-8">
                                <select name="status" class="selectpicker form-control" data-style="btn-info" data-size="10" data-live-search="true" required>
                                    <option>&nbsp;</option>
                                    <option value="ready"<?=selected('ready', _h($cpgn->status), false);?>><?=_t('Ready');?></option>
                                    <option value="processing"<?=selected('processing', _h($cpgn->status), false);?>><?=_t('Processing');?></option>
                                    <option value="paused"<?=selected('paused', _h($cpgn->status), false);?>><?=_t('Paused');?></option>
                                    <option value="processing"<?=selected('processing', _h($cpgn->status), false);?>><?=_t('Resume');?></option>
                                    <option value="sent"<?=selected('sent', _h($cpgn->status), false);?>><?=_t('Sent');?></option>
                                </select>
                            </div>
						</div>
						<!-- // Group END -->
						
						<!-- Group -->
						<div class="form-group">
							<label class="col-md-3 control-label"><font color="red">*</font> <?=_t( 'List' );?></label>
							<div class="col-md-8">
                                <select name="lid" class="selectpicker form-control" data-style="btn-info" data-size="10" data-live-search="true" required>
                                    <option>&nbsp;</option>
                                    <?php get_campaign_lists(_h((int)$cpgn->id)); ?>
                                </select>
                            </div>
						</div>
						<!-- // Group END -->
                        
                        <!-- Group -->
                        <div class="form-group">
                            <label class="col-md-3 control-label"><?=_t( 'Owner' );?></label>
                            <div class="col-md-8">
                                <input type="text" class="form-control" value="<?=get_name(_h((int)$cpgn->owner));?>" readonly>
                            </div>
                        </div>
						<!-- // Group END -->
                        
                        <!-- Group -->
                        <div class="form-group">
                            <label class="col-md-3 control-label"><?=_t( 'Modified' );?></label>
                            <div class="col-md-8">
                                <input type="text" class="form-control" value="<?= Jenssegers\Date\Date::parse(_h($cpgn->LastUpdate))->format('M. d, Y @ h:i A');?>" readonly>
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
					<h3><font color="red">*</font> <?=_t( 'Compose Message' );?>
					<!-- Column -->
					<div class="col-md-12">
                        <!-- Group -->
						<div class="form-group">
							<div class="col-md-12">
                                <textarea class="form-control template" rows="3" name="html"><?=_escape($cpgn->html);?></textarea>
                            </div>
						</div>
						<!-- // Group END -->
                    </div>
                </div>
                
                <hr class="separator" />
                
                <!-- Row -->
				<div class="row">
					<h3><font color="red">*</font> <?=_t( 'Footer' );?>
					<!-- Column -->
					<div class="col-md-12">
                        <!-- Group -->
						<div class="form-group">
							<div class="col-md-12">
                                <textarea class="form-control" rows="8" name="footer" required><?=_h($cpgn->footer);?></textarea>
                            </div>
						</div>
						<!-- // Group END -->
                    </div>
                </div>
                
                <hr class="separator" />
                
				<!-- Form actions -->
				<div class="form-actions">
					<button type="submit" class="btn btn-icon btn-primary glyphicons circle_ok"><i></i><?=_t( 'Submit' );?></button>
                    <a href="#" data-toggle="modal" data-target="#smtp-<?= _h((int)$cpgn->id); ?>" title="Send Test"><button type="button" class="btn bg-purple"><i class="fa fa-share"></i></button></a>
					<button type="button" class="btn btn-icon btn-primary glyphicons circle_minus" onclick="window.location='<?=get_base_url();?>mrkt/'"><i></i><?=_t( 'Cancel' );?></button>
				</div>
				<!-- // Form actions END -->
				
			</div>
		</div>
		<!-- // Widget END -->
		
	</form>
	<!-- // Form END -->
	<div class="separator bottom"></div>
    
    <div class="modal" id="smtp-<?= _h((int)$cpgn->id); ?>">
        <form method="post" action="<?= get_base_url(); ?>mrkt/campaign/<?= _h((int)$cpgn->id); ?>/test/" data-toggle="validator" autocomplete="off">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header">
                        <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                            <span aria-hidden="true">&times;</span></button>
                        <h4 class="modal-title"><?= _t('Send Test Email'); ?></h4>
                    </div>
                    <div class="modal-body">
                        <input type="text" class="form-control" name="email" value="<?=get_persondata('email');?>" />
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-default pull-left" data-dismiss="modal"><?= _t('Close'); ?></button>
                        <button type="submit" class="btn btn-primary"><?= _t('Send'); ?></button>
                    </div>
                </div>
                <!-- /.modal-content -->
            </div>
            <!-- /.modal-dialog -->
        </form>
    </div>
    <!-- /.modal -->
    
	<!-- // Widget END -->
	
</div>	
	
		
		</div>
		<!-- // Content END -->
<?php $app->view->stop(); ?>