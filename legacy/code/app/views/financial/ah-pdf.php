<?php if ( ! defined('BASE_PATH') ) exit('No direct script access allowed');
/**
 * Account History PDF View
 *  
 * PHP 5.4+
 *
 * eduTrac(tm) : Student Information System (http://www.7mediaws.org/)
 * @copyright (c) 2013 7 Media Web Solutions, LLC
 * 
 * @link        http://www.7mediaws.org/
 * @since       6.0.00
 * @package     eduTrac
 * @author      Joshua Parker <josh@7mediaws.org>
 */
$app = \Liten\Liten::getInstance();
$app->view->extend('_layouts/blank');
$app->view->block('blank');
$stuInfo = new \app\src\Student;
$stuInfo->Load_from_key(_h($history[0]['stuID']));

// create new PDF document
$pdf = new \app\src\tcpdf\Tcpdf(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);

// set header and footer fonts
$pdf->setHeaderFont(Array(PDF_FONT_NAME_MAIN, '', PDF_FONT_SIZE_MAIN));
$pdf->setFooterFont(Array(PDF_FONT_NAME_DATA, '', PDF_FONT_SIZE_DATA));

// set default monospaced font
$pdf->SetDefaultMonospacedFont(PDF_FONT_MONOSPACED);

// set margins
$pdf->SetMargins(PDF_MARGIN_LEFT, PDF_MARGIN_TOP, PDF_MARGIN_RIGHT);
$pdf->SetHeaderMargin(PDF_MARGIN_HEADER);
$pdf->SetFooterMargin(PDF_MARGIN_FOOTER);

// set auto page breaks
$pdf->SetAutoPageBreak(TRUE, PDF_MARGIN_BOTTOM);

// ---------------------------------------------------------

// set font
$pdf->SetFont('times', '', 10);

// add a page
$pdf->AddPage();

    $html .= '<link rel="stylesheet" type="text/css" href="' . url('/') . 'static/assets/components/library/bootstrap/css/bootstrap.min.css">';
    $html .= '<link rel="stylesheet" type="text/css" href="' . url('/') . 'static/assets/css/admin/module.admin.page.layout.section.layout-fluid-menu-top-full.min.css">';
    $html .= '<link rel="stylesheet" type="text/css" href="' . url('/') . 'static/assets/css/admin/custom.css">';
    $html .= '<link rel="stylesheet" type="text/css" href="' . url('/') . 'static/assets/css/admin/module.admin.page.invoice.min.css">';
    $html .= '<link rel="stylesheet" type="text/css" href="' . url('/') . 'static/assets/css/admin/module.admin.page.form_elements.min.css">';
    $html .= '<link rel="stylesheet" type="text/css" href="' . url('/') . 'static/assets/css/admin/module.admin.page.tables.min.css">';

$html .= '<div class="innerLR hidden-print">';

$html .= '<div id="pdfTarget">';
	$html .= '<div class="innerAll shop-client-products cart invoice">';
		$html .= '<table class="table table-invoice">';
			$html .= '<tbody>';
				$html .= '<tr>';
					$html .= '<td class="right">';
						$html .= '<div class="innerL">';
							$html .= '<h4 class="separator bottom">'.date('M d, o @ h:i A').'</h4>';
						$html .= '</div>';
					$html .= '</td>';
				$html .= '</tr>';
			$html .= '</tbody>';
		$html .= '</table>';
		
		$html .= '<div class="box-generic">';
			$html .= '<table class="table table-invoice">';
				$html .= '<tbody>';
					$html .= '<tr>';
						$html .= '<td style="width: 50%;">';
							$html .= '<p class="lead">'._t( 'Institution' ).'</p>';
                            $html .= '<h2>'._h($app->hook->{'get_option'}('institution_name')).'</h2>';
							$html .= '<address class="margin-none">';
								$html .= _h($app->hook->{'get_option'}('mailing_address')).'<br/>';
								$html .= '<abbr title="Work Email">'._t( 'e-mail' ).'</abbr> <a href="mailto:'._h($app->hook->{'get_option'}('contact_email')).'">'._h($app->hook->{'get_option'}('contact_email')).'</a><br />'; 
								$html .= '<abbr title="Work Phone">'._t( 'phone' ).'</abbr> '._h($app->hook->{'get_option'}('contact_phone'));
							$html .= '</address>';
						$html .= '</td>';
						$html .= '<td class="right">';
							$html .= '<p class="lead">'._t( 'Student information' ).'</p>';
							$html .= '<h2>'.get_name( $history[0]['stuID'] ).'</h2>';
							$html .= '<address class="margin-none">';
                                $html .= _h($stuInfo->getAddress1()).' '._h($stuInfo->getAddress2()).' '._h($stuInfo->getCity()).' '._h($stuInfo->getState()).' '._h($stuInfo->getZip()).'<br />';
								$html .= '<abbr title="Home Email">'._t( 'e-mail:' ).'</abbr> <a href="mailto:'._h($stuInfo->getEmail1()).'">'._h($stuInfo->getEmail1()).'</a><br />'; 
								$html .= '<abbr title="Home Phone">'._t( 'phone' ).'</abbr> '._h($stuInfo->getPhone1());
								$html .= '<div class="separator line"></div>';
							$html .= '</address>';
						$html .= '</td>';
					$html .= '</tr>';
				$html .= '</tbody>';
			$html .= '</table>';
		$html .= '</div>';
		
		$html .= '<table class="table table-bordered table-primary table-striped table-vertical-center">';
			$html .= '<thead>';
				$html .= '<tr>';
					$html .= '<th class="center" style="width: 1%;" class="center">No.</th>';
					$html .= '<th class="center" style="width: 10px;">'._t( 'Date' ).'</th>';
					$html .= '<th class="center" style="width: 80px;">'._t( 'Description' ).'</th>';
					$html .= '<th class="center" style="width: 80px;">'._t( 'Charges' ).'</th>';
                    $html .= '<th class="center" style="width: 1%;">&nbsp;</th>';
					$html .= '<th class="center" style="width: 80px;">'._t( 'Payments' ).'</th>';
                    $html .= '<th class="center" style="width: 80px;">'._t( 'Method' ).'</th>';
                    $html .= '<th class="center" style="width: 80px;">'._t( 'Balance' ).'</th>';
				$html .= '</tr>';
			$html .= '</thead>';
			$html .= '<tbody>';
                 
                    $balance = 0;
                    $num_rows = 0;
                    foreach($history as $k => $v) {
                    $balance += (bcadd(_h($v['PayAmount']),_h($v['FeeAmount'])*-1,2));
                    $v[8] = $balance;
                    $num_rows++;
                
                $html .= '<tr>';
					$html .= '<td class="center">'.$num_rows.'</td>';
                    $html .= '<td class="center">'.date('m/d/Y', strtotime(_h($v['timestamp']))).'</td>';
					$html .= '<td>';
						$html .= '<h5>'._h($v['description']).'</h5>';
						$html .= _t( 'Term:' ).' <span class="label label-default">'._h($v['termCode']).'</span>';
					$html .= '</td>';
                    $html .= '<td class="center"><font color="#1E90FF">'._h($v['FeeAmount']).'</font></td>';
                    $html .= '<td>&nbsp;</td>';
                    $html .= '<td class="center"><font color="#2E8B57">'._h($v['PayAmount']).'</font></td>';
                    $html .= '<td class="center">'.convert_payment_method(_h($v['method'])).'</td>';
					$html .= '<td class="center">'.($v[8] < 0) ? '<font color="#B22222">' . $v[8] . '</font>' : $v[8].'</td>';
                    $html .= '</tr>';
                    }
			$html .= '</tbody>';
		$html .= '</table>';
		$html .= '<div class="separator bottom hidden-print"></div>';
		
	$html .= '</div>';
$html .= '</div>';

// output the HTML content
$pdf->writeHTML($html, true, 0);

// ---------------------------------------------------------

$pdf->Button('print', 30, 10, 'Print', 'Print()', array('lineWidth'=>2, 'borderStyle'=>'beveled', 'fillColor'=>array(128, 196, 255), 'strokeColor'=>array(64, 64, 64)));

// Form validation functions
$js = <<<EOD
function Print() {
    print();
}
EOD;

// Add Javascript code
$pdf->IncludeJS($js);

// close and output PDF document
$pdf->Output(get_name(_h($history[0]['stuID'])).'-account-history.pdf', 'I');

//============================================================+
// END OF FILE
//============================================================+
$app->view->stop();