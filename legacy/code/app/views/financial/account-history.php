<?php if ( ! defined('BASE_PATH') ) exit('No direct script access allowed');
/**
 * Student Acccount History View
 *  
 * @license GPLv3
 * 
 * @since       6.0.00
 * @package     eduTrac SIS
 * @author      Joshua Parker <joshmac3@icloud.com>
 */

$app = \Liten\Liten::getInstance();
$app->view->extend('_layouts/dashboard');
$app->view->block('dashboard');
$stu = get_student(_h($history[0]['stuID']));
?>

<script>
    var stuID = '<?=_h($history[0]['stuID']);?>';
</script>

<ul class="breadcrumb">
    <li><?=_t( 'You are here' );?></li>
    <li><a href="<?=get_base_url();?>dashboard/" class="glyphicons dashboard"><i></i> <?=_t( 'Dashboard' );?></a></li>
    <li class="divider"></li>
    <li><a href="<?=get_base_url();?>financial/" class="glyphicons search"><i></i> <?=_t( 'Bill Lookup' );?></a></li>
    <li class="divider"></li>
    <li><?=_t( 'Account History' );?></li>
</ul>

<div class="innerLR hidden-print">
    
    <?php get_stu_header($stu->stuID); ?>
    
    <div class="separator line bottom"></div>
    
    <?=_etsis_flash()->showMessage();?>
    
</div>

<div id="pdfTarget">
	<div class="innerAll shop-client-products cart invoice">
		<table class="table table-invoice">
			<tbody>
				<tr>
					<td class="right">
						<div class="innerL">
							<h4 class="separator bottom"><?=\Jenssegers\Date\Date::now()->format('M d, o @ h:i A');?></h4>
							<button type="button" data-toggle="print" class="btn btn-default print hidden-print"><i class="fa fa-fw fa-print"></i> <?=_t( 'Print history' );?></button>
							<!-- <button type="button" data-toggle="button-loading pdf" onclick="window.open('<?=get_base_url();?>financial/ah-pdf/<?=_h($history[0]['stuID']);?>/')" class="btn btn-primary hidden-print"><i class="fa fa-fw fa-download"></i> <?=_t( 'Save as PDF' );?></button> -->
						</div>
					</td>
				</tr>
			</tbody>
		</table>
		
		<div class="box-generic">
			<table class="table table-invoice">
				<tbody>
					<tr>
						<td style="width: 50%;">
							<p class="lead"><?=_t( 'Institution' );?></p>
                            <h2><?=_h(get_option('institution_name'));?></h2>
							<address class="margin-none">
								<?=_h(get_option('mailing_address'));?><br/>
								<abbr title="Work Email"><?=_t( 'e-mail' );?></abbr> <a href="mailto:<?=_h(get_option('contact_email'));?>"><?=_h(get_option('contact_email'));?></a><br /> 
								<abbr title="Work Phone"><?=_t( 'phone' );?></abbr> <?=_h(get_option('contact_phone'));?>
							</address>
						</td>
						<td class="right">
							<p class="lead"><?=_t( 'Student information' );?></p>
							<h2><?=get_name( $history[0]['stuID'] );?></h2>
							<address class="margin-none">
                                <?=_h($stu->address1);?> <?=(_h($stu->address2) != '') ? _h($stu->address2) : '';?> <?=_h($stu->city);?> <?=_h($stu->state);?> <?=_h($stu->zip);?><br />
                                <abbr title="Home Email"><?=_t( 'e-mail:' );?></abbr> <a href="mailto:<?=_h($stu->email1);?>"><?=_h($stu->email1);?></a><br /> 
                                <abbr title="Home Phone"><?=_t( 'phone' );?></abbr> <?=_h($stu->phone1);?>
								<div class="separator line"></div>
							</address>
						</td>
					</tr>
				</tbody>
			</table>
		</div>
		
		<table class="table table-bordered table-primary table-striped table-vertical-center">
			<thead>
				<tr>
					<th class="center" style="width: 1%;" class="center">No.</th>
					<th class="center" style="width: 10px;"><?=_t( 'Date' );?></th>
					<th class="center" style="width: 80px;"><?=_t( 'Description' );?></th>
					<th class="center" style="width: 80px;"><?=_t( 'Charges' );?></th>
                    <th class="center" style="width: 1%;">&nbsp;</th>
					<th class="center" style="width: 80px;"><?=_t( 'Payments' );?></th>
                    <th class="center" style="width: 80px;"><?=_t( 'Method' );?></th>
                    <th class="center" style="width: 80px;"><?=_t( 'Balance' );?></th>
				</tr>
			</thead>
			<tbody>
                <?php 
                    $balance = 0;
                    $num_rows = 0;
                    foreach($history as $k => $v) {
                        $PayAmount = (_h($v['PayAmount']) == '' ? 0 : _h($v['PayAmount']));
                        $FeeAmount = (_h($v['FeeAmount']) == '' ? 0 : _h($v['FeeAmount']));
                        $balance += ($PayAmount+$FeeAmount*-1);
                        $v[8] = $balance;
                        $num_rows++;
                ?>
                <tr>
					<td class="center"><?=$num_rows;?></td>
                    <td class="center"><?=date('m/d/Y', strtotime(_h($v['dateStamp'])));?></td>
					<td>
						<h5><?=_h($v['description']);?></h5>
						<?=_t( 'Term:' );?> <span class="label label-default"><?=_h($v['termCode']);?></span>
					</td>
                    <td class="center"><font color="#1E90FF"><?=_h($v['FeeAmount']);?></font></td>
                    <td>&nbsp;</td>
                    <td class="center"><font color="#2E8B57"><?=_h($v['PayAmount']);?></font></td>
                    <td class="center"><?=convert_payment_method(_h($v['method']));?><?=(_h($v['checkNum']) != '' ? ' (' . _h($v['checkNum']) . ')' : '');?></td>
					<td class="center"><?=($v[8] < 0) ? '<font color="#B22222">' . $v[8] . '</font>' : $v[8];?></td>
				</tr>
                <?php  } ?>			
			</tbody>
		</table>
		<div class="separator bottom hidden-print"></div>
        <?php if(count($plan) > 0) : ?>
        <!-- Row -->
		<div class="row">
            <?php foreach($plan as $k => $v) : ?>
			<!-- Column -->
			<div class="col-md-5">
				<div class="box-generic">
					<p class="margin-none"><strong><?=_t( 'Payment Plan' );?></strong>
                    <br/><strong><?=_t( 'Agreement Date:' );?></strong> <?=date('M d, Y', strtotime(_h( $v['startDate'] )));?>
                    <br/><strong><?=_t( 'Payment:' );?></strong> <?=_h( $v['amount'] );?> / <?=_h( $v['Frequency'] );?>
                    <br/><strong><?=_t( 'Agreement Ends:' );?></strong> <?=(_h( $v['endDate'] ) > '0000-00-00') ? _h( $v['endDate'] ) : _t( 'Open' );?>
                    <br/><strong><?=_t( 'Comments:' );?></strong> <?=_h( $v['comments'] );?></p>
				</div>
			</div>
			<!-- Column END -->
			<?php endforeach; ?>
		</div>
		<!-- // Row END -->
        <?php endif; ?>
		
	</div>
</div> 

    <!-- Modal -->
    <div class="modal fade" id="FERPA">
        <div class="modal-dialog">
            <div class="modal-content">
                <!-- Modal heading -->
                <div class="modal-header">
                    <button type="button" class="close" data-dismiss="modal" aria-hidden="true">&times;</button>
                    <h3 class="modal-title"><?=_t( 'Family Educational Rights and Privacy Act (FERPA)' );?></h3>
                </div>
                <!-- // Modal heading END -->
                <!-- Modal body -->
                <div class="modal-body">
                    <p><?=_t('"FERPA gives parents certain rights with respect to their children\'s education records. 
                    These rights transfer to the student when he or she reaches the age of 18 or attends a school beyond 
                    the high school level. Students to whom the rights have transferred are \'eligible students.\'"');?></p>
                    <p><?=_t('If the FERPA restriction states "Yes", then the student has requested that none of their 
                    information be given out without their permission. To get a better understanding of FERPA, visit 
                    the U.S. DOE\'s website @ ') . 
                    '<a href="http://www2.ed.gov/policy/gen/guid/fpco/ferpa/index.html">http://www2.ed.gov/policy/gen/guid/fpco/ferpa/index.html</a>.';?></p>
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
        <!-- // Content END -->
<?php $app->view->stop(); ?>