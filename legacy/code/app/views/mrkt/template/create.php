<?php if ( ! defined('BASE_PATH') ) exit('No direct script access allowed');
/**
 * Create Template View
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
$screen = 'temps';
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
    <li><a href="<?=get_base_url();?>mrkt/template/" class="glyphicons notes_2"><i></i> <?=_t( 'Templates' );?></a></li>
	<li class="divider"></li>
	<li><?=_t( 'Create Template' );?></li>
</ul>

<h3><?=_t( 'Create Template' );?></h3>
<div class="innerLR">
    
    <?=_etsis_flash()->showMessage();?>

    <?php jstree_sidebar_menu($screen); ?>
	
	<!-- Form -->
	<form class="form-horizontal margin-none" action="<?=get_base_url();?>mrkt/template/create/" id="validateSubmitForm" method="post">
		
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
							<label class="col-md-3 control-label"><font color="red">*</font> <?=_t( 'Name' );?></label>
							<div class="col-md-8">
                                <input type="text" class="form-control" name="name" value="<?=(_h($app->req->post['name']) != '' ? _h($app->req->post['name']) : '');?>" required>
                            </div>
						</div>
						<!-- // Group END -->
						
						<!-- Group -->
						<div class="form-group">
							<label class="col-md-3 control-label"><?=_t( 'Short Description' );?></label>
							<div class="col-md-8">
                                <textarea class="form-control" rows="3" name="description"><?=(_h($app->req->post['description']) != '' ? _h($app->req->post['description']) : '');?></textarea>
                            </div>
						</div>
						<!-- // Group END -->
					
						<!-- Group -->
                        <div class="form-group">
                            <label class="col-md-3 control-label"><?=_t( 'Created' );?></label>
                            <div class="col-md-8">
                                <input type="text" class="form-control" value="<?= Jenssegers\Date\Date::now()->format('M. d, Y @ h:i A');?>" readonly/>
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
					<h3><font color="red">*</font> <?=_t( 'HTML Message' );?>
					<!-- Column -->
					<div class="col-md-12">
                        <!-- Group -->
						<div class="form-group">
							<div class="col-md-12">
                                <textarea class="form-control template" rows="3" name="content"><?=(_h($app->req->post['content']) != '' ? _h($app->req->post['content']) : '');?></textarea>
                            </div>
						</div>
						<!-- // Group END -->
                    </div>
                </div>
                
                <hr class="separator" />
                
				<!-- Form actions -->
				<div class="form-actions">
					<button type="submit" class="btn btn-icon btn-primary glyphicons circle_ok"><i></i><?=_t( 'Submit' );?></button>
					<button type="button" class="btn btn-icon btn-primary glyphicons circle_minus" onclick="window.location='<?=get_base_url();?>mrkt/template/'"><i></i><?=_t( 'Cancel' );?></button>
				</div>
				<!-- // Form actions END -->
				
			</div>
		</div>
		<!-- // Widget END -->
		
	</form>
	<!-- // Form END -->
	<div class="separator bottom"></div>
    
	<!-- // Widget END -->
	
</div>	
	
		
		</div>
		<!-- // Content END -->
<?php $app->view->stop(); ?>