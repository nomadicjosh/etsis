<?php
if (!defined('BASE_PATH'))
    exit('No direct script access allowed');
use app\src\Core\Exception\NotFoundException;
use app\src\Core\Exception\Exception;
use PDOException as ORMException;
use Cascade\Cascade;

/**
 * Financial Router
 *
 * @license GPLv3
 *         
 * @since 5.0.0
 * @package eduTrac SIS
 * @author Joshua Parker <joshmac3@icloud.com>
 */
/**
 * Before router check.
 */
$app->before('GET|POST', '/financial(.*)', function () {

    if (!is_user_logged_in()) {
        _etsis_flash()->error(_t('401 - Error: Unauthorized.'), get_base_url() . 'login' . '/');
        exit();
    }
});

$css = [
    'css/admin/module.admin.page.form_elements.min.css',
    'css/admin/module.admin.page.tables.min.css'
];

$js = [
    'components/modules/admin/forms/elements/bootstrap-select/assets/lib/js/bootstrap-select.js?v=v2.1.0',
    'components/modules/admin/forms/elements/bootstrap-select/assets/custom/js/bootstrap-select.init.js?v=v2.1.0',
    'components/modules/admin/forms/elements/select2/assets/lib/js/select2.js?v=v2.1.0',
    'components/modules/admin/forms/elements/select2/assets/custom/js/select2.init.js?v=v2.1.0',
    'components/modules/admin/forms/elements/bootstrap-datepicker/assets/lib/js/bootstrap-datepicker.js?v=v2.1.0',
    'components/modules/admin/forms/elements/bootstrap-datepicker/assets/custom/js/bootstrap-datepicker.init.js?v=v2.1.0',
    'components/modules/admin/tables/datatables/assets/lib/js/jquery.dataTables.min.js?v=v2.1.0',
    'components/modules/admin/tables/datatables/assets/lib/extras/TableTools/media/js/TableTools.min.js?v=v2.1.0',
    'components/modules/admin/tables/datatables/assets/custom/js/DT_bootstrap.js?v=v2.1.0',
    'components/modules/admin/tables/datatables/assets/custom/js/datatables.init.js?v=v2.1.0',
    'components/modules/admin/forms/elements/multiselect/assets/lib/js/jquery.multi-select.js?v=v2.1.0',
    'components/modules/admin/forms/elements/multiselect/assets/custom/js/multiselect.init.js?v=v2.1.0'
];

$app->group('/financial', function () use($app) {

    /**
     * Before router check.
     */
    $app->before('GET|POST', '/', function () {
        if (!hasPermission('access_student_accounts')) {
            _etsis_flash()->error(_t('403 - Error: Forbidden.'), get_base_url() . 'dashboard' . '/');
        }
    });

    $app->match('GET|POST', '/', function () use($app) {
        if ($app->req->isPost()) {
            try {
                $post = $app->req->post['bill'];
                $bill = $app->db->stu_acct_bill()
                    ->setTableAlias('a')
                    ->select('a.billID,a.stuID,a.termCode,c.personID,c.altID,c.fname,c.lname,c.uname')
                    ->_join('term', 'a.termCode = b.termCode', 'b')
                    ->_join('person', 'a.stuID = c.personID', 'c')
                    ->whereLike('CONCAT(c.fname," ",c.lname)', "%$post%")->_or_()
                    ->whereLike('CONCAT(c.lname," ",c.fname)', "%$post%")->_or_()
                    ->whereLike('CONCAT(c.lname,", ",c.fname)', "%$post%")->_or_()
                    ->whereLike('c.fname', "%$post%")->_or_()
                    ->whereLike('c.lname', "%$post%")->_or_()
                    ->whereLike('c.uname', "%$post%")->_or_()
                    ->whereLike('c.personID', "%$post%")->_or_()
                    ->whereLike('c.altID', "%$post%")->_or_()
                    ->whereLike('a.stuID', "%$post%")->_or_()
                    ->whereLike('a.billID', "%$post%")->_or_()
                    ->whereLike('a.termCode', "%$post%")
                    ->groupBy('a.billID')
                    ->orderBy('c.lname', 'DESC');
                $q = $bill->find(function ($data) {
                    $array = [];
                    foreach ($data as $d) {
                        $array[] = $d;
                    }
                    return $array;
                });
            } catch (NotFoundException $e) {
                Cascade::getLogger('error')->error($e->getMessage());
                _etsis_flash()->error(_etsis_flash()->notice(409));
            } catch (ORMException $e) {
                Cascade::getLogger('error')->error($e->getMessage());
                _etsis_flash()->error(_etsis_flash()->notice(409));
            } catch (Exception $e) {
                Cascade::getLogger('error')->error($e->getMessage());
                _etsis_flash()->error(_etsis_flash()->notice(409));
            }
        }

        etsis_register_style('form');
        etsis_register_style('table');
        etsis_register_script('select');
        etsis_register_script('select2');
        etsis_register_script('datatables');

        $app->view->display('financial/index', [
            'title' => 'Student Bill Search',
            'search' => $q
        ]);
    });

    /**
     * Before router check.
     */
    $app->before('GET|POST', '/create-bill/', function () {
        if (!hasPermission('access_student_accounts')) {
            _etsis_flash()->error(_t('403 - Error: Forbidden.'), get_base_url() . 'dashboard' . '/');
        }
    });

    $app->match('GET|POST', '/create-bill/', function () use($app) {

        if ($app->req->isPost()) {
            try {
                /**
                 * Check to see if a bill was already created for the particular student
                 * for the requested term.
                 */
                $bill = $app->db->stu_acct_bill()
                    ->where('stuID = ?', $app->req->post['stuID'])->_and_()
                    ->where('termCode = ?', $app->req->post['termCode']);
                $q1 = $bill->find(function ($data) {
                    $array = [];
                    foreach ($data as $d) {
                        $array[] = $d;
                    }
                    return $array;
                });
                $a = [];
                foreach ($q1 as $r) {
                    $a[] = $r;
                }
                /**
                 * If the above records return 0, then create a new bill and enter an array
                 * of fees for the student into the stu_acct_fee table.
                 */
                if (count($q1) <= 0) {
                    $q = $app->db->stu_acct_bill();
                    $q->billID = generate_stu_billID();
                    $q->stuID = $app->req->post['stuID'];
                    $q->termCode = $app->req->post['termCode'];
                    $q->authCode = \app\src\ID::code(7) . '-' . \app\src\ID::code(7) . '-' . \app\src\ID::code(7);
                    $q->stu_comments = $app->req->post['stu_comments'];
                    $q->postedBy = get_persondata('personID');
                    $q->billingDate = $app->req->post['billDate'];
                    $q->billTimeStamp = Jenssegers\Date\Date::now();
                    $q->save();
                    $_id = $q->lastInsertId();
                    $size = count($app->req->post['feeID']);
                    $i = 0;
                    while ($i < $size) {
                        $acct = $app->db->stu_acct_bill()
                            ->where('id = ?', (int) $_id)
                            ->findOne();
                        $fee = $app->db->billing_table()
                            ->where('id = ?', (int) $app->req->post['feeID'][$i])
                            ->findOne();

                        $q = $app->db->stu_acct_fee();
                        $q->billID = _escape($acct->billID);
                        $q->stuID = $app->req->post['stuID'];
                        $q->termCode = $app->req->post['termCode'];
                        $q->type = 'Fee';
                        $q->description = _escape($fee->name);
                        $q->amount = _escape($fee->amount);
                        $q->feeDate = $app->req->post['billDate'];
                        $q->feeTimeStamp = Jenssegers\Date\Date::now();
                        $q->postedBy = get_persondata('personID');
                        $q->save();
                        ++$i;
                    }
                    etsis_cache_flush_namespace('student_account');
                    etsis_logger_activity_log_write('New Record', 'Student Bill', get_name($app->req->post['stuID']) . ' (' . $app->req->post['termCode'] . ')', get_persondata('uname'));
                } /**
                 * If a bill already exists for a student for the requested term,
                 * then new fees will be added to the bill.
                 */ else {
                    $size = count($app->req->post['feeID']);
                    $i = 0;
                    while ($i < $size) {
                        $fee = $app->db->billing_table()
                            ->where('id = ?', (int) $app->req->post['feeID'][$i])
                            ->findOne();

                        $q = $app->db->stu_acct_fee();
                        $q->billID = _escape($r['billID']);
                        $q->stuID = $app->req->post['stuID'];
                        $q->termCode = $app->req->post['termCode'];
                        $q->type = 'Fee';
                        $q->description = _escape($fee->name);
                        $q->amount = _escape($fee->amount);
                        $q->feeDate = $app->req->post['billDate'];
                        $q->feeTimeStamp = Jenssegers\Date\Date::now();
                        $q->postedBy = get_persondata('personID');
                        $q->save();
                        ++$i;
                    }
                    etsis_cache_flush_namespace('student_account');
                    etsis_logger_activity_log_write('New Record', 'Student Fee', get_name($app->req->post['stuID']) . ' (' . $app->req->post['termCode'] . ')', get_persondata('uname'));
                }
                $getBill = $app->db->stu_acct_bill()
                    ->where('stuID = ?', $app->req->post['stuID'])->_and_()
                    ->where('termCode = ?', $app->req->post['termCode'])
                    ->findOne();

                _etsis_flash()->success(_etsis_flash()->notice(200), get_base_url() . 'financial' . '/' . _escape($getBill->billID) . '/');
            } catch (NotFoundException $e) {
                Cascade::getLogger('error')->error($e->getMessage());
                _etsis_flash()->error(_etsis_flash()->notice(409));
            } catch (ORMException $e) {
                Cascade::getLogger('error')->error($e->getMessage());
                _etsis_flash()->error(_etsis_flash()->notice(409));
            } catch (Exception $e) {
                Cascade::getLogger('error')->error($e->getMessage());
                _etsis_flash()->error(_etsis_flash()->notice(409));
            }
        }

        etsis_register_style('form');
        etsis_register_style('jquery-ui');
        etsis_register_script('select');
        etsis_register_script('select2');
        etsis_register_script('jquery-ui');
        etsis_register_script('datepicker');
        etsis_register_script('multiselect');

        $app->view->display('financial/create-bill', [
            'title' => 'Generate Student Bill'
        ]);
    });

    /**
     * Before router check.
     */
    $app->before('GET|POST', '/batch/', function () {
        if (!hasPermission('access_student_accounts')) {
            _etsis_flash()->error(_t('403 - Error: Forbidden.'), get_base_url() . 'dashboard' . '/');
        }
    });

    $app->match('GET|POST', '/batch/', function () use($app) {
        if ($app->req->isPost()) {
            try {
                if (empty($app->req->post['population'])) {
                    _etsis_flash()->error('409 - Error: You must choose a population', $app->req->server['HTTP_REFERER']);
                    exit();
                }
                if ($app->req->post['stu_program'] && $app->req->post['major'] || $app->req->post['stu_program'] && $app->req->post['acadLevelCode'] || $app->req->post['major'] && $app->req->post['acadLevelCode']) {
                    _etsis_flash()->error('409 - Error: You must choose a population', $app->req->server['HTTP_REFERER']);
                    exit();
                }
                if ($app->req->post['stu_program']) {
                    /**
                     * Check to see if a bill was already created for the particular student
                     * population for the requested term.
                     */
                    $billExist = $app->db->query("SELECT 
                            stuID 
                        FROM sacp 
                        WHERE acadProgCode = ? 
                        AND currStatus = 'A'  
                        AND stuID 
                        NOT IN (SELECT stuID FROM stu_acct_bill WHERE termCode = ?) 
                        AND endDate IS NULL 
                        OR endDate <= '0000-00-00'", [
                        $app->req->post['stu_program'],
                        $app->req->post['termCode']
                    ]);
                    $q1 = $billExist->find(function ($data) {
                        $array = [];
                        foreach ($data as $d) {
                            $array[] = $d;
                        }
                        return $array;
                    });
                    /**
                     * Retrieve a list of students based on particular population
                     * who already have a bill for the term in question.
                     */
                    $stuList = $app->db->query("SELECT 
                            sacp.stuID, b.billID 
                        FROM sacp, stu_acct_bill AS b 
                        WHERE sacp.acadProgCode = ? 
                        AND sacp.currStatus = 'A' 
                        AND sacp.stuID = b.stuID 
                        AND b.termCode = ? 
                        AND sacp.endDate = IS NULL 
                        OR sacp.endDate <= '0000-00-00'", [
                        $app->req->post['stu_program'],
                        $app->req->post['termCode']
                    ]);
                    $q2 = $stuList->find(function ($data) {
                        $array = [];
                        foreach ($data as $d) {
                            $array[] = $d;
                        }
                        return $array;
                    });
                }
                if ($app->req->post['major']) {
                    /**
                     * Check to see if a bill was already created for the particular student
                     * population for the requested term.
                     */
                    $billExist = $app->db->query("SELECT 
                            a.stuID 
                        FROM sacp a 
                        LEFT JOIN acad_program b ON a.acadProgCode = b.acadProgCode 
                        WHERE b.majorCode = ? 
                        AND a.currStatus = 'A' 
                        AND a.endDate <= '0000-00-00' 
                        AND a.stuID 
                        NOT IN (SELECT stuID FROM stu_acct_bill WHERE termCode = ?)", [
                        $app->req->post['major'],
                        $app->req->post['termCode']
                    ]);
                    $q1 = $billExist->find(function ($data) {
                        $array = [];
                        foreach ($data as $d) {
                            $array[] = $d;
                        }
                        return $array;
                    });
                    /**
                     * Retrieve a list of students based on particular population
                     * who already have a bill for the term in question.
                     */
                    $stuList = $app->db->query("SELECT 
                            a.stuID,b.billID 
                        FROM sacp a 
                        LEFT JOIN stu_acct_bill b ON a.stuID = b.stuID 
                        LEFT JOIN acad_program c ON a.acadProgCode = c.acadProgCode  
                        WHERE c.majorCode = ? 
                        AND a.currStatus = 'A' 
                        AND a.endDate = '0000-00-00' 
                        AND b.termCode = ?", [
                        $app->req->post['major'],
                        $app->req->post['termCode']
                    ]);
                    $q2 = $stuList->find(function ($data) {
                        $array = [];
                        foreach ($data as $d) {
                            $array[] = $d;
                        }
                        return $array;
                    });
                }
                if ($app->req->post['acadLevelCode']) {
                    /**
                     * Check to see if a bill was already created for the particular student
                     * population for the requested term.
                     */
                    $billExist = $app->db->query("SELECT 
                            a.stuID 
                        FROM sacp a
                        LEFT JOIN acad_program b ON a.acadProgCode = b.acadProgCode
                        WHERE b.acadLevelCode = ? 
                        AND a.currStatus = 'A' 
                        AND a.endDate <= '0000-00-00' 
                        AND a.stuID 
                        NOT IN (SELECT stuID FROM stu_acct_bill WHERE termCode = ?)", [
                        $app->req->post['acadLevelCode'],
                        $app->req->post['termCode']
                    ]);
                    $q1 = $billExist->find(function ($data) {
                        $array = [];
                        foreach ($data as $d) {
                            $array[] = $d;
                        }
                        return $array;
                    });
                    /**
                     * Retrieve a list of students based on particular population
                     * who already have a bill for the term in question.
                     */
                    $stuList = $app->db->query("SELECT 
                            a.stuID,b.billID 
                        FROM sacp a 
                        LEFT JOIN stu_acct_bill b ON a.stuID = b.stuID 
                        LEFT JOIN acad_program c ON a.acadProgCode = c.acadProgCode  
                        WHERE c.acadLevelCode = ? 
                        AND a.currStatus = 'A' 
                        AND a.endDate <= '0000-00-00' 
                        AND b.termCode = ?", [
                        $app->req->post['acadLevelCode'],
                        $app->req->post['termCode']
                    ]);
                    $q2 = $stuList->find(function ($data) {
                        $array = [];
                        foreach ($data as $d) {
                            $array[] = $d;
                        }
                        return $array;
                    });
                }

                /**
                 * If a bill for a population of students does not exist,
                 * then create a new bill and enter an array
                 * of fees for the student into the stu_acct_fee table.
                 */
                if (count($q1) > 0) {
                    foreach ($q1 as $r1) {
                        $q3 = $app->db->stu_acct_bill();
                        $q3->billID = generate_stu_billID();
                        $q3->stuID = _escape($r1['stuID']);
                        $q3->termCode = $app->req->post['termCode'];
                        $q3->authCode = \app\src\ID::code(7) . '-' . \app\src\ID::code(7) . '-' . \app\src\ID::code(7);
                        $q3->postedBy = get_persondata('personID');
                        $q3->billingDate = $app->req->post['billDate'];
                        $q3->billTimeStamp = Jenssegers\Date\Date::now();
                        $q3->save();
                        $_id = $q3->lastInsertId();

                        etsis_logger_activity_log_write('New Record', 'Student Bill', get_name(_escape($r1['stuID'])) . ' (' . $app->req->post['termCode'] . ')', get_persondata('uname'));

                        $acct = $app->db->stu_acct_bill()
                            ->where('id = ?', (int) $_id)
                            ->findOne();

                        $size = count($app->req->post['feeID']);
                        $i = 0;
                        while ($i < $size) {
                            $fee = $app->db->billing_table()
                                ->where('id = ?', (int) $app->req->post['feeID'][$i])
                                ->findOne();

                            $q4 = $app->db->stu_acct_fee();
                            $q4->billID = _escape($acct->billID);
                            $q4->stuID = _escape($r1['stuID']);
                            $q4->termCode = $app->req->post['termCode'];
                            $q4->type = 'Fee';
                            $q4->description = _escape($fee->name);
                            $q4->amount = _escape($fee->amount);
                            $q4->feeDate = $app->req->post['billDate'];
                            $q4->feeTimeStamp = Jenssegers\Date\Date::now();
                            $q4->postedBy = get_persondata('personID');
                            $q4->save();
                            etsis_logger_activity_log_write('New Record', 'Student Fee', get_name(_escape($r1['stuID'])) . ' (' . $app->req->post['termCode'] . ')', get_persondata('uname'));
                            ++$i;
                        }
                    }
                    etsis_cache_flush_namespace('student_account');
                }
                /**
                 * If a bill already exists for a student population for the requested term,
                 * then new fees will be added to the bill.
                 */
                if (count($q2) > 0) {
                    foreach ($q2 as $r2) {
                        $size = count($app->req->post['feeID']);
                        $i = 0;
                        while ($i < $size) {
                            $fee = $app->db->billing_table()
                                ->where('id = ?', (int) $app->req->post['feeID'][$i])
                                ->findOne();

                            $q3 = $app->db->stu_acct_fee();
                            $q3->billID = _escape($r2['billID']);
                            $q3->stuID = _escape($r2['stuID']);
                            $q3->termCode = $app->req->post['termCode'];
                            $q3->type = 'Fee';
                            $q3->description = _escape($fee->name);
                            $q3->amount = _escape($fee->amount);
                            $q3->feeDate = $app->req->post['billDate'];
                            $q3->feeTimeStamp = Jenssegers\Date\Date::now();
                            $q3->postedBy = get_persondata('personID');
                            $q3->save();
                            etsis_logger_activity_log_write('New Record', 'Student Fee', get_name(_escape($r2['stuID'])) . ' (' . $app->req->post['termCode'] . ')', get_persondata('uname'));
                            ++$i;
                        }
                    }
                }
                etsis_cache_flush_namespace('student_account');
                _etsis_flash()->success(_etsis_flash()->notice(200), $app->req->server['HTTP_REFERER']);
            } catch (NotFoundException $e) {
                Cascade::getLogger('error')->error($e->getMessage());
                _etsis_flash()->error(_etsis_flash()->notice(409), $app->req->server['HTTP_REFERER']);
            } catch (ORMException $e) {
                Cascade::getLogger('error')->error($e->getMessage());
                _etsis_flash()->error(_etsis_flash()->notice(409), $app->req->server['HTTP_REFERER']);
            } catch (Exception $e) {
                Cascade::getLogger('error')->error($e->getMessage());
                _etsis_flash()->error(_etsis_flash()->notice(409), $app->req->server['HTTP_REFERER']);
            }
        }

        etsis_register_style('form');
        etsis_register_script('select');
        etsis_register_script('select2');
        etsis_register_script('datepicker');
        etsis_register_script('multiselect');

        $app->view->display('financial/batch', [
            'title' => 'Batch Fees'
        ]);
    });

    /**
     * Before router check.
     */
    $app->before('GET|POST', '/add-payment/', function () {
        if (!hasPermission('access_student_accounts')) {
            _etsis_flash()->error(_t('403 - Error: Forbidden.'), get_base_url() . 'dashboard' . '/');
        }
    });

    $app->match('GET|POST', '/add-payment/', function () use($app, $css, $js) {

        if ($app->req->isPost()) {
            try {
                $payment = $app->db->stu_acct_bill()
                    ->where('stuID = ?', $app->req->post['stuID'])->_and_()
                    ->where('termCode = ?', $app->req->post['termCode'])
                    ->findOne();

                if (empty($app->req->post['checkNum'])) {
                    $check = NULL;
                } else {
                    $check = $app->req->post['checkNum'];
                }
                $q = $app->db->payment();
                $q->stuID = $app->req->post['stuID'];
                $q->termCode = $app->req->post['termCode'];
                $q->amount = $app->req->post['amount'];
                $q->paymentDate = $app->req->post['paymentDate'];
                $q->checkNum = $check;
                $q->paymentTypeID = $app->req->post['paymentTypeID'];
                $q->comment = $app->req->post['comment'];
                $q->postedBy = get_persondata('personID');
                $q->save();

                etsis_cache_flush_namespace('student_account');
                etsis_logger_activity_log_write('New Record', 'Student Payment', get_name($app->req->post['stuID']), get_persondata('uname'));
                _etsis_flash()->success(_etsis_flash()->notice(200), get_base_url() . 'financial' . '/' . _escape($payment->billID) . '/payments/');
            } catch (NotFoundException $e) {
                Cascade::getLogger('error')->error($e->getMessage());
                _etsis_flash()->error(_etsis_flash()->notice(409));
            } catch (ORMException $e) {
                Cascade::getLogger('error')->error($e->getMessage());
                _etsis_flash()->error(_etsis_flash()->notice(409));
            } catch (Exception $e) {
                Cascade::getLogger('error')->error($e->getMessage());
                _etsis_flash()->error(_etsis_flash()->notice(409));
            }
        }

        etsis_register_style('form');
        etsis_register_style('jquery-ui');
        etsis_register_script('select');
        etsis_register_script('select2');
        etsis_register_script('jquery-ui');
        etsis_register_script('datepicker');

        $app->view->display('financial/add-payment', [
            'title' => 'Add Bill Payment'
        ]);
    });

    /**
     * Before router check.
     */
    $app->before('GET|POST', '/issue-refund/', function () {
        if (!hasPermission('access_student_accounts')) {
            _etsis_flash()->error(_t('403 - Error: Forbidden.'), get_base_url() . 'dashboard' . '/');
        }
    });

    $app->match('GET|POST', '/issue-refund/', function () use($app, $css, $js) {

        if ($app->req->isPost()) {
            try {
                $refund = $app->db->stu_acct_bill()
                    ->where('stuID = ?', $app->req->post['stuID'])->_and_()
                    ->where('termCode = ?', $app->req->post['termCode'])
                    ->findOne();

                $q = $app->db->refund();
                $q->stuID = $app->req->post['stuID'];
                $q->termCode = $app->req->post['termCode'];
                $q->amount = $app->req->post['amount'];
                $q->refundDate = $app->req->post['refundDate'];
                $q->comment = $app->req->post['comment'];
                $q->postedBy = get_persondata('personID');
                $q->save();

                etsis_cache_flush_namespace('student_account');
                etsis_logger_activity_log_write('New Record', 'Student Refund', get_name($app->req->post['stuID']), get_persondata('uname'));
                _etsis_flash()->success(_etsis_flash()->notice(200), get_base_url() . 'financial' . '/' . _escape($refund->billID) . '/refunds/');
            } catch (NotFoundException $e) {
                Cascade::getLogger('error')->error($e->getMessage());
                _etsis_flash()->error(_etsis_flash()->notice(409));
            } catch (ORMException $e) {
                Cascade::getLogger('error')->error($e->getMessage());
                _etsis_flash()->error(_etsis_flash()->notice(409));
            } catch (Exception $e) {
                Cascade::getLogger('error')->error($e->getMessage());
                _etsis_flash()->error(_etsis_flash()->notice(409));
            }
        }

        etsis_register_style('form');
        etsis_register_style('jquery-ui');
        etsis_register_script('select');
        etsis_register_script('select2');
        etsis_register_script('jquery-ui');
        etsis_register_script('datepicker');

        $app->view->display('financial/issue-refund', [
            'title' => 'Issue Student Refund'
        ]);
    });

    /**
     * Before router check.
     */
    $app->before('GET|POST', '/payment-plan/', function () {
        if (!hasPermission('access_student_accounts')) {
            _etsis_flash()->error(_t('403 - Error: Forbidden.'), get_base_url() . 'dashboard' . '/');
        }
    });

    $app->match('GET|POST', '/payment-plan/', function () use($app) {

        if ($app->req->isPost()) {
            try {
                if (!empty($app->req->post['id'])) {
                    $pp = $app->db->stu_acct_pp();
                    foreach ($app->req->post as $k => $v) {
                        $pp->$k = $v;
                    }
                    $pp->where('id = ?', $app->req->post['id']);
                    $pp->update();

                    etsis_cache_flush_namespace('student_account');
                    etsis_logger_activity_log_write('Update Record', 'Payment Plan', get_name($app->req->post['stuID']), get_persondata('uname'));
                    _etsis_flash()->success(_etsis_flash()->notice(200), $app->req->server['HTTP_REFERER']);
                } else {
                    $pp = $app->db->stu_acct_pp();
                    foreach ($app->req->post as $k => $v) {
                        $pp->$k = $v;
                    }
                    $pp->save();

                    etsis_cache_flush_namespace('student_account');
                    etsis_logger_activity_log_write('New Record', 'Payment Plan', get_name($app->req->post['stuID']), get_persondata('uname'));
                    _etsis_flash()->success(_etsis_flash()->notice(200), $app->req->server['HTTP_REFERER']);
                }
            } catch (NotFoundException $e) {
                Cascade::getLogger('error')->error($e->getMessage());
                _etsis_flash()->error(_etsis_flash()->notice(409));
            } catch (ORMException $e) {
                Cascade::getLogger('error')->error($e->getMessage());
                _etsis_flash()->error(_etsis_flash()->notice(409));
            } catch (Exception $e) {
                Cascade::getLogger('error')->error($e->getMessage());
                _etsis_flash()->error(_etsis_flash()->notice(409));
            }
        }

        try {
            $plan = $app->db->query('SELECT *,' . ' CASE payFrequency' . ' WHEN "1" THEN "Daily"' . ' WHEN "7" THEN "Weekly"' . ' WHEN "14" THEN "Bi-Weekly"' . ' WHEN "30" THEN "Monthly"' . ' ELSE "Yearly"' . ' END AS Frequency' . ' FROM stu_acct_pp' . ' ORDER BY id');
            $query = etsis_cache_get('payment_plan', 'student_account');
            if (empty($query)) {
                $query = $plan->find(function ($data) {
                    $array = [];
                    foreach ($data as $d) {
                        $array[] = $d;
                    }
                    return $array;
                });
                etsis_cache_add('payment_plan', $query, 'student_account');
            }
        } catch (NotFoundException $e) {
            Cascade::getLogger('error')->error($e->getMessage());
            _etsis_flash()->error(_etsis_flash()->notice(409));
        } catch (ORMException $e) {
            Cascade::getLogger('error')->error($e->getMessage());
            _etsis_flash()->error(_etsis_flash()->notice(409));
        } catch (Exception $e) {
            Cascade::getLogger('error')->error($e->getMessage());
            _etsis_flash()->error(_etsis_flash()->notice(409));
        }

        etsis_register_style('form');
        etsis_register_style('jquery-ui');
        etsis_register_style('table');
        etsis_register_script('select');
        etsis_register_script('select2');
        etsis_register_script('jquery-ui');
        etsis_register_script('datepicker');
        etsis_register_script('datatables');

        $app->view->display('financial/payment-plan', [
            'title' => 'Payment Plan',
            'plan' => $query
        ]);
    });

    /**
     * Before router check.
     */
    $app->before('GET', '/payment-plan/delete/(\d+)/', function () {
        if (!hasPermission('delete_student')) {
            _etsis_flash()->error(_t('Permission denied to delete record.'), get_base_url() . 'dashboard' . '/');
            exit();
        }
    });

    $app->get('/payment-plan/delete/(\d+)/', function ($id) use($app) {
        try {
            $pp = $app->db->stu_acct_pp()
                ->where('id = ?', $id);
            $pp->delete();

            etsis_cache_flush_namespace('student_account');
            etsis_logger_activity_log_write('Delete Record', 'Financial', 'Payment Plan', get_persondata('uname'));
            _etsis_flash()->success(_etsis_flash()->notice(200));
        } catch (NotFoundException $e) {
            Cascade::getLogger('error')->error($e->getMessage());
            _etsis_flash()->error(_etsis_flash()->notice(409));
        } catch (ORMException $e) {
            Cascade::getLogger('error')->error($e->getMessage());
            _etsis_flash()->error(_etsis_flash()->notice(409));
        } catch (Exception $e) {
            Cascade::getLogger('error')->error($e->getMessage());
            _etsis_flash()->error(_etsis_flash()->notice(409));
        }
        etsis_redirect($app->req->server['HTTP_REFERER']);
    });

    /**
     * Before router check.
     */
    $app->before('GET|POST', '/paypal/', function () {
        if (!hasPermission('access_payment_gateway')) {
            _etsis_flash()->error(_t('403 - Error: Forbidden.'), get_base_url() . 'dashboard' . '/');
        }
    });

    $app->match('GET|POST', '/paypal/', function () use($app, $css, $js) {

        if ($app->req->isPost()) {
            $options = [
                'enable_paypal_gateway',
                'paypal_use_ssl',
                'paypal_business_email',
                'paypal_currency_code',
                'paypal_sandbox_mode'
            ];
            foreach ($options as $option_name) {
                if (!isset($app->req->post[$option_name]))
                    continue;
                $value = $app->req->post[$option_name];
                $app->hook->{'update_option'}($option_name, $value);
            }
            // Update more options here
            $app->hook->{'do_action'}('update_options');
            /* Write to logs */
            etsis_logger_activity_log_write('Update', 'Settings', 'Paypal Settings', get_persondata('uname'));
            etsis_redirect($app->req->server['HTTP_REFERER']);
        }

        etsis_register_style('form');
        etsis_register_script('select');
        etsis_register_script('select2');

        $app->view->display('financial/paypal', [
            'title' => 'Paypal Gateway Settings'
        ]);
    });

    /**
     * Before router check.
     */
    $app->before('GET|POST', '/account-history/(\d+)/', function () {
        if (!hasPermission('access_student_accounts')) {
            _etsis_flash()->error(_t('403 - Error: Forbidden.'), get_base_url() . 'dashboard' . '/');
        }
    });

    $app->get('/account-history/(\d+)/', function ($id) use($app) {
        try {
            $plan = $app->db->query('SELECT *,' . ' CASE payFrequency' . ' WHEN "1" THEN "Daily"' . ' WHEN "7" THEN "Weekly"' . ' WHEN "14" THEN "Bi-Weekly"' . ' WHEN "30" THEN "Monthly"' . ' ELSE "Yearly"' . ' END AS Frequency' . ' FROM stu_acct_pp' . ' WHERE stuID = ?', [
                $id
            ]);
            $sql = etsis_cache_get('payment_plan' . $id, 'student_account');
            if (empty($sql)) {
                $sql = $plan->find(function ($data) {
                    $array = [];
                    foreach ($data as $d) {
                        $array[] = $d;
                    }
                    return $array;
                });
                etsis_cache_add('payment_plan' . $id, $sql, 'student_account');
            }
            $history = $app->db->query('SELECT 
            id AS FeeID, billID AS billID, stuID AS stuID,
            termCode AS termCode, type as type, description AS description,
            amount AS FeeAmount, NULL AS PayAmount, NULL AS checkNum, NULL AS method, feeDate AS dateStamp 
            FROM stu_acct_fee 
            WHERE stuID = ? 
            AND type = "Fee"
            UNION ALL 
                SELECT NULL as FeeID, NULL AS billID, stuID AS stuID, termCode AS termCode,
                NULL as type, "Tuition" as description, total AS FeeAmount, NULL AS PayAmount,
                NULL AS checkNum, NULL as method, tuitionTimeStamp AS dateStamp 
                FROM stu_acct_tuition 
                WHERE stuID = ? 
            UNION ALL 
            SELECT NULL as FeeID, NULL AS billID, stuID AS stuID, termCode AS termCode,
            NULL as type, "Payment" as description, NULL AS FeeAmount, amount AS PayAmount, 
            checkNum AS checkNum, paymentTypeID as method, paymentDate AS datestamp 
            FROM payment 
            WHERE stuID = ? 
            ORDER BY dateStamp ASC', [
                $id,
                $id,
                $id
            ]);
            $q = etsis_cache_get('history' . $id, 'student_account');
            if (empty($q)) {
                $q = $history->find(function ($data) {
                    $array = [];
                    foreach ($data as $d) {
                        $array[] = $d;
                    }
                    return $array;
                });
                etsis_cache_add('history' . $id, $q, 'student_account');
            }
        } catch (NotFoundException $e) {
            Cascade::getLogger('error')->error($e->getMessage());
            _etsis_flash()->error(_etsis_flash()->notice(409));
        } catch (ORMException $e) {
            Cascade::getLogger('error')->error($e->getMessage());
            _etsis_flash()->error(_etsis_flash()->notice(409));
        } catch (Exception $e) {
            Cascade::getLogger('error')->error($e->getMessage());
            _etsis_flash()->error(_etsis_flash()->notice(409));
        }

        /**
         * If the database table doesn't exist, then it
         * is false and a 404 should be sent.
         */
        if ($q === false) {

            $app->view->display('error/404', [
                'title' => '404 Error'
            ]);
        } /**
         * If the query is legit, but there
         * is no data in the table, then 404
         * will be shown.
         */ elseif (empty($q) === true) {

            $app->view->display('error/404', [
                'title' => '404 Error'
            ]);
        } /**
         * If data is zero, 404 not found.
         */ elseif (_escape($q[0]['stuID']) <= 0) {

            $app->view->display('error/404', [
                'title' => '404 Error'
            ]);
        } /**
         * If we get to this point, the all is well
         * and it is ok to process the query and print
         * the results in a html format.
         */ else {
            etsis_register_style('form');
            etsis_register_style('table');
            etsis_register_style('invoice');
            $app->view->display('financial/account-history', [
                'title' => 'Student Account History',
                'history' => $q,
                'plan' => $sql
            ]);
        }
    });

    /**
     * Before router check.
     */
    $app->before('GET|POST', '/ah-pdf/(\d+)/', function () {
        if (!hasPermission('access_student_accounts')) {
            _etsis_flash()->error(_t('403 - Error: Forbidden.'), get_base_url() . 'dashboard' . '/');
        }
    });

    $app->get('/ah-pdf/(\d+)/', function ($id) use($app) {
        /**
         * This scope is only being used by a client using
         * eduTrac SIS Cloud: Berea School of Ministry.
         */
        if ($app->req->isPost()) {
            
        }

        try {
            $history = $app->db->query('SELECT 
            id AS FeeID, billID AS billID, stuID AS stuID,
            termCode AS termCode,description AS description,
            amount AS FeeAmount, NULL AS PayAmount, NULL AS method, timestamp AS timestamp 
            FROM stu_acct_fee 
            WHERE stuID = ?
            UNION ALL 
            SELECT NULL as FeeID, NULL AS billID, stuID AS stuID, termCode AS termCode,
            "Payment" as description, NULL AS FeeAmount, amount AS PayAmount, 
            paymentTypeID as method, paymentDate AS timestamp 
            FROM payment 
            WHERE stuID = ? 
            ORDER BY timestamp ASC', [
                $id,
                $id
            ]);
            $q = $history->find(function ($data) {
                $array = [];
                foreach ($data as $d) {
                    $array[] = $d;
                }
                return $array;
            });
        } catch (NotFoundException $e) {
            Cascade::getLogger('error')->error($e->getMessage());
            _etsis_flash()->error(_etsis_flash()->notice(409));
        } catch (ORMException $e) {
            Cascade::getLogger('error')->error($e->getMessage());
            _etsis_flash()->error(_etsis_flash()->notice(409));
        } catch (Exception $e) {
            Cascade::getLogger('error')->error($e->getMessage());
            _etsis_flash()->error(_etsis_flash()->notice(409));
        }

        /**
         * If the database table doesn't exist, then it
         * is false and a 404 should be sent.
         */
        if ($q === false) {

            $app->view->display('error/404', [
                'title' => '404 Error'
            ]);
        } /**
         * If the query is legit, but there
         * is no data in the table, then 404
         * will be shown.
         */ elseif (empty($q) === true) {

            $app->view->display('error/404', [
                'title' => '404 Error'
            ]);
        } /**
         * If data is zero, 404 not found.
         */ elseif (_escape($q[0]['stuID']) <= 0) {

            $app->view->display('error/404', [
                'title' => '404 Error'
            ]);
        } /**
         * If we get to this point, the all is well
         * and it is ok to process the query and print
         * the results in a html format.
         */ else {
            $app->view->display('financial/ah-pdf', [
                'title' => 'Student Account History',
                'cssArray' => [
                    'components/library/bootstrap/css/bootstrap.min.css',
                    'css/admin/module.admin.page.layout.section.layout-fluid-menu-top-full.min.css',
                    'css/admin/custom.css',
                    'css/admin/module.admin.page.invoice.min.css',
                    'css/admin/module.admin.page.tables.min.css'
                ],
                'history' => $q
            ]);
        }
    });

    /**
     * Before router check.
     */
    $app->before('GET|POST', '/gl-accounts/', function () {
        if (!hasPermission('access_general_ledger')) {
            _etsis_flash()->error(_t('403 - Error: Forbidden.'), get_base_url() . 'dashboard' . '/');
        }
    });

    $app->match('GET|POST', '/gl-accounts/', function () use($app) {
        if ($app->req->isPost()) {
            try {
                if (isset($app->req->post['glacctID'])) {
                    $gl = $app->db->gl_account();
                    foreach ($app->req->post as $k => $v) {
                        $gl->$k = $v;
                    }
                    $gl->where('glacctID = ?', _filter_input_int(INPUT_POST, 'glacctID'));
                    if ($gl->update()) {
                        etsis_logger_activity_log_write('Update Record', 'General Ledger Account', _filter_input_string(INPUT_POST, 'gl_acct_name') . ' (' . $app->req->post['gl_acct_number'] . ')', get_persondata('uname'));
                        _etsis_flash()->success(_etsis_flash()->notice(200));
                    } else {
                        _etsis_flash()->error(_etsis_flash()->notice(409));
                    }
                } else {
                    $gl = $app->db->gl_account();
                    foreach ($app->req->post as $k => $v) {
                        $gl->$k = $v;
                    }
                    if ($gl->save()) {
                        etsis_logger_activity_log_write('New Record', 'General Ledger Account', _filter_input_string(INPUT_POST, 'gl_acct_name') . ' (' . $app->req->post['gl_acct_number'] . ')', get_persondata('uname'));
                        _etsis_flash()->success(_etsis_flash()->notice(200));
                    } else {
                        _etsis_flash()->error(_etsis_flash()->notice(409));
                    }
                }
            } catch (NotFoundException $e) {
                Cascade::getLogger('error')->error($e->getMessage());
                _etsis_flash()->error(_etsis_flash()->notice(409));
            } catch (ORMException $e) {
                Cascade::getLogger('error')->error($e->getMessage());
                _etsis_flash()->error(_etsis_flash()->notice(409));
            } catch (Exception $e) {
                Cascade::getLogger('error')->error($e->getMessage());
                _etsis_flash()->error(_etsis_flash()->notice(409));
            }
            etsis_redirect($app->req->server['HTTP_REFERER']);
        }

        try {
            $gl = $app->db->gl_account()
                ->setTableAlias('a')
                ->select('a.glacctID,a.gl_acct_number,a.gl_acct_name,a.gl_acct_type')
                ->select('a.gl_acct_memo,b.gl_trans_debit,b.gl_trans_credit')
                ->_join('gl_transaction', 'a.glacctID = b.accountID', 'b');
            $q = $gl->find(function ($data) {
                $array = [];
                foreach ($data as $d) {
                    $array[] = $d;
                }
                return $array;
            });
        } catch (NotFoundException $e) {
            Cascade::getLogger('error')->error($e->getMessage());
            _etsis_flash()->error(_etsis_flash()->notice(409));
        } catch (ORMException $e) {
            Cascade::getLogger('error')->error($e->getMessage());
            _etsis_flash()->error(_etsis_flash()->notice(409));
        } catch (Exception $e) {
            Cascade::getLogger('error')->error($e->getMessage());
            _etsis_flash()->error(_etsis_flash()->notice(409));
        }

        etsis_register_style('form');
        etsis_register_style('table');
        etsis_register_script('select');
        etsis_register_script('select2');
        etsis_register_script('datatables');

        $app->view->display('financial/gl-accounts', [
            'title' => 'General Ledger Accounts',
            'gl' => $q
        ]);
    });

    /**
     * Before router check.
     */
    $app->before('GET|POST', '/gl-filter.*', function () {
        if (!hasPermission('access_general_ledger')) {
            _etsis_flash()->error(_t('403 - Error: Forbidden.'), get_base_url() . 'dashboard' . '/');
        }
    });

    $app->match('GET|POST', '/gl-filter/', function () use($app) {

        if ($app->req->isPost()) {
            etsis_redirect(get_base_url() . 'financial/gl-filter' . '/' . $app->req->post['from'] . '/' . $app->req->post['to']);
        }

        etsis_register_style('form');
        etsis_register_script('datepicker');

        $app->view->display('financial/gl-filter', [
            'title' => 'GL Summary'
        ]);
    });

    $app->get('/gl-filter/([^/]+)/([^/]+)', function ($from, $to) use($app) {
        try {
            $filter = $app->db->gl_account()
                ->setTableAlias('a')
                ->select('a.gl_acct_number,a.gl_acct_name,a.gl_acct_type')
                ->select('SUM(b.gl_trans_debit) AS Debit,SUM(b.gl_trans_credit) AS Credit')
                ->_join('gl_transaction', 'a.glacctID = b.accountID', 'b')
                ->where('b.gl_trans_date BETWEEN ? AND ?', [
                    $from,
                    $to
                ])
                ->groupBy('b.accountID');
            $glfilter = $filter->find(function ($data) {
                $array = [];
                foreach ($data as $d) {
                    $array[] = $d;
                }
                return $array;
            });

            $summary = $app->db->gl_transaction()
                ->setTableAlias('gl')
                ->select('SUM(gl.gl_trans_debit) AS Debit,SUM(gl.gl_trans_credit) AS Credit')
                ->where('gl.gl_trans_date < ?', $from);
            $glSum = $summary->find(function ($data) {
                $array = [];
                foreach ($data as $d) {
                    $array[] = $d;
                }
                return $array;
            });
        } catch (NotFoundException $e) {
            Cascade::getLogger('error')->error($e->getMessage());
            _etsis_flash()->error(_etsis_flash()->notice(409));
        } catch (ORMException $e) {
            Cascade::getLogger('error')->error($e->getMessage());
            _etsis_flash()->error(_etsis_flash()->notice(409));
        } catch (Exception $e) {
            Cascade::getLogger('error')->error($e->getMessage());
            _etsis_flash()->error(_etsis_flash()->notice(409));
        }

        $app->view->display('financial/gl-summary', [
            'title' => 'GL Summary',
            'to' => $to,
            'from' => $from,
            'glfilter' => $glfilter,
            'glSum' => $glSum
        ]);
    });

    /**
     * Before router check.
     */
    $app->before('GET|POST', '/jentry-filter.*', function () {
        if (!hasPermission('access_general_ledger')) {
            _etsis_flash()->error(_t('403 - Error: Forbidden.'), get_base_url() . 'dashboard' . '/');
        }
    });

    $app->match('GET|POST', '/jentry-filter/', function () use($app) {

        if ($app->req->isPost()) {
            etsis_redirect(get_base_url() . 'financial/jentry-filter' . '/' . $app->req->post['from'] . '/' . $app->req->post['to']);
        }

        etsis_register_style('form');
        etsis_register_script('datepicker');

        $app->view->display('financial/jentry-filter', [
            'title' => 'GL Summary'
        ]);
    });

    $app->get('/jentry-filter/([^/]+)/([^/]+)', function ($from, $to) use($app) {
        try {
            $filter = $app->db->gl_journal_entry()
                ->setTableAlias('a')
                ->select('a.*,b.gl_trans_debit')
                ->_join('gl_transaction', 'a.jeID = b.jeID', 'b')
                ->where('a.gl_jentry_date BETWEEN ? AND ?', [
                    $from,
                    $to
                ])
                ->groupBy('a.jeID')
                ->orderBy('a.jeID');
            $jfilter = $filter->find(function ($data) {
                $array = [];
                foreach ($data as $d) {
                    $array[] = $d;
                }
                return $array;
            });

            $summary = $app->db->gl_journal_entry()
                ->setTableAlias('a')
                ->select('a.*,SUM(b.gl_trans_debit) AS Debit')
                ->_join('gl_transaction', 'a.jeID = b.jeID', 'b')
                ->where('a.gl_jentry_date BETWEEN ? AND ?', [
                $from,
                $to
            ]);
            $jSum = $summary->find(function ($data) {
                $array = [];
                foreach ($data as $d) {
                    $array[] = $d;
                }
                return $array;
            });
        } catch (NotFoundException $e) {
            Cascade::getLogger('error')->error($e->getMessage());
            _etsis_flash()->error(_etsis_flash()->notice(409));
        } catch (ORMException $e) {
            Cascade::getLogger('error')->error($e->getMessage());
            _etsis_flash()->error(_etsis_flash()->notice(409));
        } catch (Exception $e) {
            Cascade::getLogger('error')->error($e->getMessage());
            _etsis_flash()->error(_etsis_flash()->notice(409));
        }

        $app->view->display('financial/jentries', [
            'title' => 'GL Summary',
            'to' => $to,
            'from' => $from,
            'jfilter' => $jfilter,
            'jSum' => $jSum
        ]);
    });

    /**
     * Before router check.
     */
    $app->before('GET|POST', '/journal-entries.*', function () {
        if (!hasPermission('access_general_ledger')) {
            _etsis_flash()->error(_t('403 - Error: Forbidden.'), get_base_url() . 'dashboard' . '/');
        }
    });

    $app->get('/journal-entries/', function () use($app) {
        try {
            $jentry = $app->db->gl_journal_entry()
                ->setTableAlias('a')
                ->select('a.jeID,a.gl_jentry_date,a.gl_jentry_manual_id')
                ->select('a.gl_jentry_title,a.gl_jentry_description,a.gl_jentry_personID')
                ->select('SUM(b.gl_trans_debit) as Debits')
                ->_join('gl_transaction', 'a.jeID = b.jeID', 'b')
                ->groupBy('b.jeID')
                ->orderBy('a.jeID', 'DESC');
            $q = $jentry->find(function ($data) {
                $array = [];
                foreach ($data as $d) {
                    $array[] = $d;
                }
                return $array;
            });
        } catch (NotFoundException $e) {
            Cascade::getLogger('error')->error($e->getMessage());
            _etsis_flash()->error(_etsis_flash()->notice(409));
        } catch (ORMException $e) {
            Cascade::getLogger('error')->error($e->getMessage());
            _etsis_flash()->error(_etsis_flash()->notice(409));
        } catch (Exception $e) {
            Cascade::getLogger('error')->error($e->getMessage());
            _etsis_flash()->error(_etsis_flash()->notice(409));
        }

        etsis_register_style('form');
        etsis_register_style('table');
        etsis_register_script('select');
        etsis_register_script('select2');
        etsis_register_script('datatables');

        $app->view->display('financial/journal-entries', [
            'title' => 'Journal Entries',
            'jentry' => $q
        ]);
    });

    $app->get('/journal-entries/(\d+)/', function ($id) use($app) {
        try {
            $jentry = $app->db->gl_transaction()
                ->setTableAlias('a')
                ->select('a.gl_trans_memo,a.gl_trans_debit,a.gl_trans_credit')
                ->select('b.gl_acct_name,c.*')
                ->_join('gl_account', 'a.accountID = b.glacctID', 'b')
                ->_join('gl_journal_entry', 'a.jeID = c.jeID', 'c')
                ->where('c.jeID = ?', $id)
                ->orderBy('c.jeID');
            $entry = $jentry->find(function ($data) {
                $array = [];
                foreach ($data as $d) {
                    $array[] = $d;
                }
                return $array;
            });

            $jtrans = $app->db->gl_transaction()
                ->setTableAlias('a')
                ->select('SUM(a.gl_trans_debit) AS Debit,SUM(a.gl_trans_credit) AS Credit,b.*')
                ->_join('gl_journal_entry', 'a.jeID = b.jeID', 'b')
                ->where('b.jeID = ?', $id)
                ->groupBy('a.jeID')
                ->orderBy('b.jeID');
            $trans = $jtrans->find(function ($data) {
                $array = [];
                foreach ($data as $d) {
                    $array[] = $d;
                }
                return $array;
            });
        } catch (NotFoundException $e) {
            Cascade::getLogger('error')->error($e->getMessage());
            _etsis_flash()->error(_etsis_flash()->notice(409));
        } catch (ORMException $e) {
            Cascade::getLogger('error')->error($e->getMessage());
            _etsis_flash()->error(_etsis_flash()->notice(409));
        } catch (Exception $e) {
            Cascade::getLogger('error')->error($e->getMessage());
            _etsis_flash()->error(_etsis_flash()->notice(409));
        }

        etsis_register_style('form');
        etsis_register_style('table');
        etsis_register_script('select');
        etsis_register_script('select2');
        etsis_register_script('datatables');

        $app->view->display('financial/view-jentry', [
            'title' => 'Journal Entry',
            'jentry' => $entry,
            'jtrans' => $trans
        ]);
    });

    /**
     * Before router check.
     */
    $app->before('GET|POST', '/add-jentry/', function () {
        if (!hasPermission('access_general_ledger')) {
            _etsis_flash()->error(_t('403 - Error: Forbidden.'), get_base_url() . 'dashboard' . '/');
        }
    });

    $app->match('GET|POST', '/add-jentry/', function () use($app, $css, $js) {
        if ($app->req->isPost()) {
            try {
                $jentry = $app->db->gl_journal_entry();
                $jentry->gl_jentry_date = $app->req->post['gl_jentry_date'];
                $jentry->gl_jentry_manual_id = $app->req->post['gl_jentry_manual_id'];
                $jentry->gl_jentry_title = $app->req->post['gl_jentry_title'];
                $jentry->gl_jentry_description = $app->req->post['gl_jentry_description'];
                $jentry->gl_jentry_personID = get_persondata('personID');
                if ($jentry->save()) {
                    $_id = $jentry->lastInsertId();
                    $i = 0;
                    $size = count($app->req->post['glacctID']);
                    while ($i < $size) {
                        if ($app->req->post['amount'][$i] < 0) {
                            $credit = $app->req->post['amount'][$i];
                            $debit = 0;
                        } else {
                            $debit = $app->req->post['amount'][$i];
                            $credit = 0;
                        }
                        $tran = $app->db->gl_transaction();
                        $tran->jeID = $_id;
                        $tran->acountID = $app->req->post['glacctID'][$i];
                        $tran->gl_trans_date = $app->req->post['gl_trans_date'];
                        $tran->gl_trans_memo = $app->req->post['gl_trans_memo'][$i];
                        $tran->gl_trans_debit = $debit;
                        $tran->gl_trans_credit = $credit;
                        if ($tran->save()) {
                            _etsis_flash()->success(_etsis_flash()->notice(200));
                            etsis_logger_activity_log_write('New Record', 'GL Journal Entry', $app->req->post['gl_jentry_manual_id'], get_persondata('uname'));
                        } else {
                            _etsis_flash()->error(_etsis_flash()->notice(409));
                        }
                        ++$i;
                    }
                }
            } catch (NotFoundException $e) {
                Cascade::getLogger('error')->error($e->getMessage());
                _etsis_flash()->error(_etsis_flash()->notice(409));
            } catch (ORMException $e) {
                Cascade::getLogger('error')->error($e->getMessage());
                _etsis_flash()->error(_etsis_flash()->notice(409));
            } catch (Exception $e) {
                Cascade::getLogger('error')->error($e->getMessage());
                _etsis_flash()->error(_etsis_flash()->notice(409));
            }
            etsis_redirect($app->req->server['HTTP_REFERER']);
        }

        etsis_register_style('form');
        etsis_register_style('table');
        etsis_register_script('select');
        etsis_register_script('select2');
        etsis_register_script('datepicker');

        $app->view->display('financial/add-jentry', [
            'title' => 'Add Journal Entry'
        ]);
    });

    /**
     * Before router check.
     */
    $app->before('GET|POST', '/billing-table/', function () {
        if (!hasPermission('access_student_accounts')) {
            _etsis_flash()->error(_t('403 - Error: Forbidden.'), get_base_url() . 'dashboard' . '/');
        }
    });

    $app->match('GET|POST', '/billing-table/', function () use($app) {
        if ($app->req->isPost()) {
            try {
                $btbl = $app->db->billing_table();
                $btbl->insert([
                    'name' => $app->req->post['name'],
                    'amount' => $app->req->post['amount'],
                    'type' => $app->req->post['type'],
                    'term' => $app->req->post['term'] != '' ? $app->req->post['term'] : NULL,
                    'rule' => $app->req->post['rule'] != '' ? $app->req->post['rule'] : NULL,
                    'status' => $app->req->post['status'],
                    'addDate' => Jenssegers\Date\Date::now()
                ]);
                $_id = $btbl->lastInsertId();

                etsis_logger_activity_log_write('New Record', 'Billing Table', $app->req->post['name'] . ' (' . $app->req->post['amount'] . ')', get_persondata('uname'));
                etsis_cache_flush_namespace('billtable');
                _etsis_flash()->success(_etsis_flash()->notice(200), get_base_url() . 'financial/billing-table/' . $_id . '/');
            } catch (NotFoundException $e) {
                Cascade::getLogger('error')->error($e->getMessage());
                _etsis_flash()->error(_etsis_flash()->notice(409));
            } catch (ORMException $e) {
                Cascade::getLogger('error')->error($e->getMessage());
                _etsis_flash()->error(_etsis_flash()->notice(409));
            } catch (Exception $e) {
                Cascade::getLogger('error')->error($e->getMessage());
                _etsis_flash()->error(_etsis_flash()->notice(409));
            }
        }

        try {
            $bill = $app->db->billing_table()
                ->setTableAlias('btbl')
                ->select('btbl.id,btbl.name,btbl.amount,btbl.term,btbl.status')
                ->select("CASE btbl.type WHEN 'F' THEN 'Fee' ELSE 'Tuition' END AS 'FeeType'")
                ->select("CASE btbl.status WHEN 'A' THEN 'Active' ELSE 'Inactive' END AS 'Stat'")
                ->orderBy('btbl.addDate', 'DESC');
            $q = etsis_cache_get('billtable', 'billtable');
            if (empty($q)) {
                $q = $bill->find(function ($data) {
                    $array = [];
                    foreach ($data as $d) {
                        $array[] = $d;
                    }
                    return $array;
                });
                etsis_cache_add('billtable', $q, 'billtable');
            }
        } catch (NotFoundException $e) {
            Cascade::getLogger('error')->error($e->getMessage());
            _etsis_flash()->error(_etsis_flash()->notice(409));
        } catch (ORMException $e) {
            Cascade::getLogger('error')->error($e->getMessage());
            _etsis_flash()->error(_etsis_flash()->notice(409));
        } catch (Exception $e) {
            Cascade::getLogger('error')->error($e->getMessage());
            _etsis_flash()->error(_etsis_flash()->notice(409));
        }

        etsis_register_style('form');
        etsis_register_style('selectize');
        etsis_register_style('table');
        etsis_register_script('select');
        etsis_register_script('select2');
        etsis_register_script('datatables');

        $app->view->display('financial/billing-table', [
            'title' => 'Billing Table',
            'billing' => $q
        ]);
    });

    /**
     * Before router check.
     */
    $app->before('GET|POST', '/billing-table/(\d+)/', function () {
        if (!hasPermission('access_student_accounts')) {
            _etsis_flash()->error(_t('403 - Error: Forbidden.'), get_base_url() . 'dashboard' . '/');
        }
    });

    $app->match('GET|POST', '/billing-table/(\d+)/', function ($id) use($app) {
        if ($app->req->isPost()) {
            try {
                $btbl = $app->db->billing_table();
                $btbl->set([
                        'name' => $app->req->post['name'],
                        'amount' => $app->req->post['amount'],
                        'type' => $app->req->post['type'],
                        'term' => $app->req->post['term'] != '' ? $app->req->post['term'] : NULL,
                        'rule' => $app->req->post['rule'] != '' ? $app->req->post['rule'] : NULL,
                        'status' => $app->req->post['status']
                    ])
                    ->where('id = ?', $id)
                    ->update();

                etsis_logger_activity_log_write('Update Record', 'Billing Table', $app->req->post['name'] . ' (' . $app->req->post['amount'] . ')', get_persondata('uname'));
                etsis_cache_flush_namespace('billtable');
                _etsis_flash()->success(_etsis_flash()->notice(200), $app->req->server['HTTP_REFERER']);
            } catch (NotFoundException $e) {
                Cascade::getLogger('error')->error($e->getMessage());
                _etsis_flash()->error(_etsis_flash()->notice(409));
            } catch (ORMException $e) {
                Cascade::getLogger('error')->error($e->getMessage());
                _etsis_flash()->error(_etsis_flash()->notice(409));
            } catch (Exception $e) {
                Cascade::getLogger('error')->error($e->getMessage());
                _etsis_flash()->error(_etsis_flash()->notice(409));
            }
        }

        try {
            $btbl = $app->db->billing_table()
                ->setTableAlias('btbl')
                ->select('btbl.id,btbl.name,btbl.amount,btbl.type,btbl.term,btbl.rule,btbl.status')
                ->select("CASE btbl.type WHEN 'F' THEN 'Fee' ELSE 'Tuition' END AS 'FeeType'")
                ->where('btbl.id = ?', $id)
                ->findOne();
        } catch (NotFoundException $e) {
            Cascade::getLogger('error')->error($e->getMessage());
            _etsis_flash()->error(_etsis_flash()->notice(409));
        } catch (ORMException $e) {
            Cascade::getLogger('error')->error($e->getMessage());
            _etsis_flash()->error(_etsis_flash()->notice(409));
        } catch (Exception $e) {
            Cascade::getLogger('error')->error($e->getMessage());
            _etsis_flash()->error(_etsis_flash()->notice(409));
        }

        etsis_register_style('form');
        etsis_register_style('selectize');
        etsis_register_style('table');
        etsis_register_script('select');
        etsis_register_script('select2');
        etsis_register_script('datepicker');

        $app->view->display('financial/view-btbl', [
            'title' => 'View/Edit',
            'btbl' => $btbl
        ]);
    });

    /**
     * Before router check.
     */
    $app->before('GET|POST', '/billing-table/(\d+)/btrl/', function () {
        if (!hasPermission('manage_business_rules')) {
            _etsis_flash()->error(_t("You don't have permission to manage business rules."), get_base_url() . 'dashboard' . '/');
        }
    });

    $app->match('GET|POST', '/billing-table/(\d+)/btrl/', function ($id) use($app) {

        if ($app->req->isPost()) {
            try {
                $rule = $app->db->billing_table();
                $rule->set([
                        'rule' => ($app->req->post['rule'] != '' ? $app->req->post['rule'] : NULL)
                    ])
                    ->where('id = ?', $id)
                    ->update();

                _etsis_flash()->success(_etsis_flash()->notice(200), $app->req->server['HTTP_REFERER']);
            } catch (NotFoundException $e) {
                Cascade::getLogger('error')->error($e->getMessage());
                _etsis_flash()->error(_etsis_flash()->notice(409), $app->req->server['HTTP_REFERER']);
            } catch (ORMException $e) {
                Cascade::getLogger('error')->error($e->getMessage());
                _etsis_flash()->error(_etsis_flash()->notice(409), $app->req->server['HTTP_REFERER']);
            } catch (Exception $e) {
                Cascade::getLogger('error')->error($e->getMessage());
                _etsis_flash()->error(_etsis_flash()->notice(409), $app->req->server['HTTP_REFERER']);
            }
        }

        try {
            $btbl = $app->db->billing_table()
                ->select('id,name,rule')
                ->where('id = ?', $id)
                ->findOne();

            $q = $app->db->student()
                ->select('stuID');
            $stu = $q->find(function ($data) {
                $array = [];
                foreach ($data as $d) {
                    $array[] = $d;
                }
                return $array;
            });
        } catch (NotFoundException $e) {
            Cascade::getLogger('error')->error($e->getMessage());
            _etsis_flash()->error(_etsis_flash()->notice(409));
        } catch (ORMException $e) {
            Cascade::getLogger('error')->error($e->getMessage());
            _etsis_flash()->error(_etsis_flash()->notice(409));
        } catch (Exception $e) {
            Cascade::getLogger('error')->error($e->getMessage());
            _etsis_flash()->error(_etsis_flash()->notice(409));
        }

        /**
         * If the database table doesn't exist, then it
         * is false and a 404 should be sent.
         */
        if ($btbl === false) {

            $app->view->display('error/404', [
                'title' => '404 Error'
            ]);
        } /**
         * If the query is legit, but there
         * is no data in the table, then 404
         * will be shown.
         */ elseif (empty($btbl) === true) {

            $app->view->display('error/404', [
                'title' => '404 Error'
            ]);
        } /**
         * If data is zero, 404 not found.
         */ elseif (_escape($btbl->id) <= 0) {

            $app->view->display('error/404', [
                'title' => '404 Error'
            ]);
        } /**
         * If we get to this point, the all is well
         * and it is ok to process the query and print
         * the results in a html format.
         */ else {

            etsis_register_style('form');
            etsis_register_style('querybuilder');
            etsis_register_script('select');
            etsis_register_script('select2');

            $app->view->display('financial/btrl', [
                'title' => 'Billing Table Rule',
                'btbl' => $btbl,
                'stu' => $stu
            ]);
        }
    });

    /**
     * Before router check.
     */
    $app->before('POST', '/billing-table/(\d+)/btrl/test/', function () {
        if (!hasPermission('manage_business_rules')) {
            _etsis_flash()->error(_t("You don't have permission to manage business rules."), get_base_url() . 'dashboard' . '/');
        }
    });

    $app->post('/billing-table/(\d+)/btrl/test/', function ($id) use($app) {

        try {
            $rule = $app->db->billing_table()
                ->select('rule')
                ->where('id = ?', (int) $id)
                ->findOne();

            $rlde = _escape($rule->rule);

            $btrl = $app->db->query(
                "SELECT v_sacp.stuID FROM v_sacp"
                . " INNER JOIN stal ON v_sacp.stuID = stal.stuID AND v_sacp.prog = stal.acadProgCode"
                . " WHERE $rlde"
            );

            $q = $btrl->find(function($data) {
                $array = [];
                foreach ($data as $d) {
                    $array[] = $d;
                }
                return $array;
            });
            $a = [];
            foreach ($q as $row) {
                $a[] = _escape($row['stuID']);
            }

            if (!in_array($app->req->post['stuID'], $a)) {
                _etsis_flash()->error(sprintf(_t('<strong>%s</strong> did not pass the rule.'), get_name($app->req->post['stuID'])), $app->req->server['HTTP_REFERER']);
            } else {
                _etsis_flash()->success(sprintf(_t('<strong>%s</strong> passed the rule.'), get_name($app->req->post['stuID'])), $app->req->server['HTTP_REFERER']);
            }

            _etsis_flash()->success(_etsis_flash()->notice(200), $app->req->server['HTTP_REFERER']);
        } catch (NotFoundException $e) {
            Cascade::getLogger('error')->error($e->getMessage());
            _etsis_flash()->error(_etsis_flash()->notice(409), $app->req->server['HTTP_REFERER']);
        } catch (ORMException $e) {
            Cascade::getLogger('error')->error($e->getMessage());
            _etsis_flash()->error(_etsis_flash()->notice(409), $app->req->server['HTTP_REFERER']);
        } catch (Exception $e) {
            Cascade::getLogger('error')->error($e->getMessage());
            _etsis_flash()->error(_etsis_flash()->notice(409), $app->req->server['HTTP_REFERER']);
        }
    });

    /**
     * Before router check.
     */
    $app->before('GET', '/billing-table/(\d+)/btrl/c/', function () {
        if (!hasPermission('manage_business_rules')) {
            _etsis_flash()->error(_t("You don't have permission to manage business rules."), get_base_url() . 'dashboard' . '/');
        }
    });

    $app->get('/billing-table/(\d+)/btrl/c/', function ($id) use($app) {

        try {
            $rule = $app->db->billing_table();
            $rule->set([
                    'rule' => NULL
                ])
                ->where('id = ?', $id)
                ->update();

            _etsis_flash()->success(_etsis_flash()->notice(200), $app->req->server['HTTP_REFERER']);
        } catch (NotFoundException $e) {
            Cascade::getLogger('error')->error($e->getMessage());
            _etsis_flash()->error(_etsis_flash()->notice(409), $app->req->server['HTTP_REFERER']);
        } catch (ORMException $e) {
            Cascade::getLogger('error')->error($e->getMessage());
            _etsis_flash()->error(_etsis_flash()->notice(409), $app->req->server['HTTP_REFERER']);
        } catch (Exception $e) {
            Cascade::getLogger('error')->error($e->getMessage());
            _etsis_flash()->error(_etsis_flash()->notice(409), $app->req->server['HTTP_REFERER']);
        }
    });

    /**
     * Before router check.
     */
    $app->before('GET|POST', '/deljEntry/(\d+)/', function () {
        if (!hasPermission('access_general_ledger')) {
            _etsis_flash()->error(_t('Permission denied to delete record.'), get_base_url() . 'dashboard' . '/');
            exit();
        }
    });

    $app->get('/deljEntry/(\d+)/', function ($id) use($app) {

        try {
            $jentry = $app->db->gl_journal_entry()
                ->where('jeID = ?', $id);
            $jentry->delete();

            _etsis_flash()->success(_etsis_flash()->notice(200), $app->req->server['HTTP_REFERER']);
        } catch (NotFoundException $e) {
            Cascade::getLogger('error')->error($e->getMessage());
            _etsis_flash()->error(_etsis_flash()->notice(409), $app->req->server['HTTP_REFERER']);
        } catch (ORMException $e) {
            Cascade::getLogger('error')->error($e->getMessage());
            _etsis_flash()->error(_etsis_flash()->notice(409), $app->req->server['HTTP_REFERER']);
        } catch (Exception $e) {
            Cascade::getLogger('error')->error($e->getMessage());
            _etsis_flash()->error(_etsis_flash()->notice(409), $app->req->server['HTTP_REFERER']);
        }
    });

    /**
     * Before router check.
     */
    $app->before('GET', '/deletePayment/(\d+)/', function () {
        if (!hasPermission('delete_student')) {
            _etsis_flash()->error(_t('Permission denied to delete record.'), get_base_url() . 'dashboard' . '/');
            exit();
        }
    });

    $app->get('/deletePayment/(\d+)/', function ($id) use($app) {

        try {
            $payment = $app->db->payment()
                ->where('id = ?', $id);
            $payment->delete();

            etsis_cache_flush_namespace('student_account');
            _etsis_flash()->success(_etsis_flash()->notice(200), $app->req->server['HTTP_REFERER']);
        } catch (NotFoundException $e) {
            Cascade::getLogger('error')->error($e->getMessage());
            _etsis_flash()->error(_etsis_flash()->notice(409), $app->req->server['HTTP_REFERER']);
        } catch (ORMException $e) {
            Cascade::getLogger('error')->error($e->getMessage());
            _etsis_flash()->error(_etsis_flash()->notice(409), $app->req->server['HTTP_REFERER']);
        } catch (Exception $e) {
            Cascade::getLogger('error')->error($e->getMessage());
            _etsis_flash()->error(_etsis_flash()->notice(409), $app->req->server['HTTP_REFERER']);
        }
    });

    /**
     * Before router check.
     */
    $app->before('GET', '/deleteRefund/(\d+)/', function () {
        if (!hasPermission('delete_student')) {
            _etsis_flash()->error(_t('Permission denied to delete record.'), get_base_url() . 'dashboard' . '/');
            exit();
        }
    });

    $app->get('/deleteRefund/(\d+)/', function ($id) use($app) {

        try {
            $refund = $app->db->refund()
                ->where('id = ?', $id);
            $refund->delete();

            etsis_cache_flush_namespace('student_account');
            _etsis_flash()->success(_etsis_flash()->notice(200), $app->req->server['HTTP_REFERER']);
        } catch (NotFoundException $e) {
            Cascade::getLogger('error')->error($e->getMessage());
            _etsis_flash()->error(_etsis_flash()->notice(409), $app->req->server['HTTP_REFERER']);
        } catch (ORMException $e) {
            Cascade::getLogger('error')->error($e->getMessage());
            _etsis_flash()->error(_etsis_flash()->notice(409), $app->req->server['HTTP_REFERER']);
        } catch (Exception $e) {
            Cascade::getLogger('error')->error($e->getMessage());
            _etsis_flash()->error(_etsis_flash()->notice(409), $app->req->server['HTTP_REFERER']);
        }
    });

    /**
     * Before router check.
     */
    $app->before('GET', '/deleteFee/(\d+)/', function () {
        if (!hasPermission('delete_student')) {
            _etsis_flash()->error(_t('Permission denied to delete record.'), get_base_url() . 'dashboard' . '/');
            exit();
        }
    });

    $app->get('/deleteFee/(\d+)/', function ($id) use($app) {

        try {
            $fee = $app->db->stu_acct_fee()
                ->where('id = ?', $id);
            $fee->delete();

            etsis_cache_flush_namespace('student_account');
            _etsis_flash()->success(_etsis_flash()->notice(200), $app->req->server['HTTP_REFERER']);
        } catch (NotFoundException $e) {
            Cascade::getLogger('error')->error($e->getMessage());
            _etsis_flash()->error(_etsis_flash()->notice(409), $app->req->server['HTTP_REFERER']);
        } catch (ORMException $e) {
            Cascade::getLogger('error')->error($e->getMessage());
            _etsis_flash()->error(_etsis_flash()->notice(409), $app->req->server['HTTP_REFERER']);
        } catch (Exception $e) {
            Cascade::getLogger('error')->error($e->getMessage());
            _etsis_flash()->error(_etsis_flash()->notice(409), $app->req->server['HTTP_REFERER']);
        }
    });

    $app->match('GET|POST', '/([^/]+)/', function ($id) use($app) {
        if ($app->req->isPost()) {
            try {
                $stuBill = $app->db->stu_acct_bill()
                    ->select('termCode')
                    ->where('billID = ?', $id)
                    ->findOne();
                if ($stuBill->termCode !== $app->req->post['invTerm']) {
                    $p = $app->db->payment();
                    $p->termCode = $app->req->post['invTerm'];
                    $p->where('stuID = ?', $app->req->post['stuID'])->_and_()
                        ->where('termCode = ?', $stuBill->termCode);
                    $p->update();

                    $r = $app->db->refund();
                    $r->termCode = $app->req->post['invTerm'];
                    $r->where('stuID = ?', $app->req->post['stuID'])->_and_()
                        ->where('termCode = ?', $stuBill->termCode);
                    $r->update();
                }
                $size = count($app->req->post['feeID']);
                $i = 0;
                while ($i < $size) {
                    $q = $app->db->stu_acct_fee();
                    $q->amount = $app->req->post['amount'][$i];
                    $q->feeDate = $app->req->post['feeDate'][$i];
                    $q->termCode = $app->req->post['feeTerm'][$i];
                    $q->where('stu_acct_fee.id = ?', $app->req->post['feeID'][$i]);
                    $q->update();
                    ++$i;
                }

                $q = $app->db->stu_acct_tuition();
                $q->total = $app->req->post['total'];
                $q->termCode = $app->req->post['invTerm'];
                $q->where('stu_acct_tuition.id = ?', $app->req->post['tuitionID']);
                $q->update();

                $bill = $app->db->stu_acct_bill();
                $bill->billingDate = $app->req->post['billDate'];
                $bill->termCode = $app->req->post['invTerm'];
                $bill->where('billID = ?', $id);
                $bill->update();

                etsis_logger_activity_log_write('Update Record', 'Student Bill', get_name($app->req->post['stuID']) . ' (' . $id . ')', get_persondata('uname'));
                etsis_cache_flush_namespace('student_account');
                _etsis_flash()->success(_etsis_flash()->notice(200), $app->req->server['HTTP_REFERER']);
            } catch (NotFoundException $e) {
                Cascade::getLogger('error')->error($e->getMessage());
                _etsis_flash()->error(_etsis_flash()->notice(409));
            } catch (ORMException $e) {
                Cascade::getLogger('error')->error($e->getMessage());
                _etsis_flash()->error(_etsis_flash()->notice(409));
            } catch (Exception $e) {
                Cascade::getLogger('error')->error($e->getMessage());
                _etsis_flash()->error(_etsis_flash()->notice(409));
            }
        }

        try {
            $bill = $app->db->stu_acct_bill()
                ->setTableAlias('a')
                ->select('a.*, b.termName')
                ->_join('term', 'a.termCode = b.termCode', 'b')
                ->where('a.billID = ?', $id);
            $q1 = etsis_cache_get('bill' . $id, 'student_account');
            if (empty($q1)) {
                $q1 = $bill->find(function ($data) {
                    $array = [];
                    foreach ($data as $d) {
                        $array[] = $d;
                    }
                    return $array;
                });
                etsis_cache_add('bill' . $id, $q1, 'student_account');
            }
            $stuTuition = $app->db->stu_acct_tuition()
                ->where('stuID = ?', _escape($q1[0]['stuID']))->_and_()
                ->where('termCode = ?', _escape($q1[0]['termCode']));
            $query = etsis_cache_get('stuTuition' . $id, 'student_account');
            if (empty($query)) {
                $query = $stuTuition->find(function ($data) {
                    $array = [];
                    foreach ($data as $d) {
                        $array[] = $d;
                    }
                    return $array;
                });
                etsis_cache_add('stuTuition' . $id, $query, 'student_account');
            }
            $tuition = $app->db->query('SELECT SUM(amount) as sum FROM stu_acct_fee WHERE billID = ? AND type = "Tuition" GROUP BY stuID, termCode', [
                $id
            ]);
            $q2 = etsis_cache_get('tuition' . $id, 'student_account');
            if (empty($q2)) {
                $q2 = $tuition->find(function ($data) {
                    $array = [];
                    foreach ($data as $d) {
                        $array[] = $d;
                    }
                    return $array;
                });
                etsis_cache_add('tuition' . $id, $q2, 'student_account');
            }
            $fee = $app->db->query('SELECT id, description, amount as Fee, termCode, feeDate FROM stu_acct_fee WHERE billID = ? AND type = "Fee"', [
                $id
            ]);
            $q3 = etsis_cache_get('fee' . $id, 'student_account');
            if (empty($q3)) {
                $q3 = $fee->find(function ($data) {
                    $array = [];
                    foreach ($data as $d) {
                        $array[] = $d;
                    }
                    return $array;
                });
                etsis_cache_add('fee' . $id, $q3, 'student_account');
            }
            $sumFee = $app->db->query("SELECT 
                        SUM(amount) as sum 
                    FROM stu_acct_fee 
                    WHERE billID = ? 
                    AND type = 'Fee' 
                    GROUP BY stuID,termCode", [
                $id
            ]);
            $q4 = etsis_cache_get('sumFee' . $id, 'student_account');
            if (empty($q4)) {
                $q4 = $sumFee->find(function ($data) {
                    $array = [];
                    foreach ($data as $d) {
                        $array[] = $d;
                    }
                    return $array;
                });
                etsis_cache_add('sumFee' . $id, $q4, 'student_account');
            }
            $sumPay = $app->db->query("SELECT 
                        SUM(amount) as sum 
                    FROM payment 
                    WHERE stuID = ? 
                    AND termCode = ? 
                    GROUP BY stuID,termCode", [
                _escape($q1[0]['stuID']),
                _escape($q1[0]['termCode'])
            ]);
            $q5 = etsis_cache_get('sumPay' . $id, 'student_account');
            if (empty($q5)) {
                $q5 = $sumPay->find(function ($data) {
                    $array = [];
                    foreach ($data as $d) {
                        $array[] = $d;
                    }
                    return $array;
                });
                etsis_cache_add('sumPay' . $id, $q5, 'student_account');
            }
            $sumRefund = $app->db->query("SELECT 
                        SUM(amount) as sum 
                    FROM refund 
                    WHERE stuID = ? 
                    AND termCode = ? 
                    GROUP BY stuID,termCode", [
                _escape($q1[0]['stuID']),
                _escape($q1[0]['termCode'])
            ]);
            $q6 = etsis_cache_get('sumRefund' . $id, 'student_account');
            if (empty($q6)) {
                $q6 = $sumRefund->find(function ($data) {
                    $array = [];
                    foreach ($data as $d) {
                        $array[] = $d;
                    }
                    return $array;
                });
                etsis_cache_add('sumRefund' . $id, $q6, 'student_account');
            }
        } catch (NotFoundException $e) {
            Cascade::getLogger('error')->error($e->getMessage());
            _etsis_flash()->error(_etsis_flash()->notice(409));
        } catch (ORMException $e) {
            Cascade::getLogger('error')->error($e->getMessage());
            _etsis_flash()->error(_etsis_flash()->notice(409));
        } catch (Exception $e) {
            Cascade::getLogger('error')->error($e->getMessage());
            _etsis_flash()->error(_etsis_flash()->notice(409));
        }

        /**
         * If the database table doesn't exist, then it
         * is false and a 404 should be sent.
         */
        if ($q1 === false) {

            $app->view->display('error/404', [
                'title' => '404 Error'
            ]);
        } /**
         * If the query is legit, but there
         * is no data in the table, then 404
         * will be shown.
         */ elseif (empty($q1) === true) {

            $app->view->display('error/404', [
                'title' => '404 Error'
            ]);
        } /**
         * If data is zero, 404 not found.
         */ elseif (count(_escape($q1[0]['billID'])) <= 0) {

            $app->view->display('error/404', [
                'title' => '404 Error'
            ]);
        } /**
         * If we get to this point, the all is well
         * and it is ok to process the query and print
         * the results in a html format.
         */ else {
            etsis_register_style('form');
            etsis_register_style('table');
            etsis_register_script('select');
            etsis_register_script('select2');
            etsis_register_script('datepicker');

            $app->view->display('financial/view-bill', [
                'title' => get_name(_escape($q1[0]['stuID'])),
                'bill' => $q1,
                'tuition1' => $query,
                'tuition2' => money_format('%n', (double) _escape($q2[0]['sum'])),
                'fee' => $q3,
                'begin' => money_format('-%n', (double) bcadd(_escape($query[0]['total']), _escape($q4[0]['sum']))),
                'sumFee' => _escape($q4[0]['sum']),
                'sumPayments' => _escape($q5[0]['sum']),
                'sumRefund' => _escape($q6[0]['sum'])
            ]);
        }
    });

    $app->match('GET|POST', '/([^/]+)/payments/', function ($id) use($app) {
        if ($app->req->isPost()) {
            try {
                $size = count($app->req->post['amount']);
                $i = 0;
                while ($i < $size) {
                    $q = $app->db->payment();
                    $q->termCode = $app->req->post['termCode'][$i];
                    $q->amount = $app->req->post['amount'][$i];
                    if (empty($app->req->post['checkNum'][$i])) {
                        $q->checkNum = NULL;
                    } else {
                        $q->checkNum = $app->req->post['checkNum'][$i];
                    }
                    $q->paymentTypeID = $app->req->post['paymentType'][$i];
                    $q->comment = $app->req->post['comment'][$i];
                    $q->paymentDate = $app->req->post['paymentDate'][$i];
                    $q->where('payment.id = ?', $app->req->post['id'][$i])->_and_()
                        ->where('payment.stuID = ?', $app->req->post['stuID']);
                    $q->update();
                    ++$i;
                }
                etsis_cache_delete('payments' . $id, 'student_account');
            } catch (NotFoundException $e) {
                Cascade::getLogger('error')->error($e->getMessage());
                _etsis_flash()->error(_etsis_flash()->notice(409));
            } catch (ORMException $e) {
                Cascade::getLogger('error')->error($e->getMessage());
                _etsis_flash()->error(_etsis_flash()->notice(409));
            } catch (Exception $e) {
                Cascade::getLogger('error')->error($e->getMessage());
                _etsis_flash()->error(_etsis_flash()->notice(409));
            }
        }

        try {
            $stuBill = $app->db->stu_acct_bill()
                ->setTableAlias('a')
                ->select('a.*,b.termName')
                ->select('c.id as paymentID,c.amount,c.checkNum,c.comment,c.paymentDate,d.type,d.id AS ptID')
                ->_join('term', 'a.termCode = b.termCode', 'b')
                ->_join('payment', 'a.stuID = c.stuID AND a.termCode = c.termCode', 'c')
                ->_join('payment_type', 'c.paymentTypeID = d.id', 'd')
                ->where('a.billID = ?', $id)
                ->orderBy('c.paymentDate', 'DESC');
            $q = etsis_cache_get('payments' . $id, 'student_account');
            if (empty($q)) {
                $q = $stuBill->find(function ($data) {
                    $array = [];
                    foreach ($data as $d) {
                        $array[] = $d;
                    }
                    return $array;
                });
                etsis_cache_add('payments' . $id, $q, 'student_account');
            }
        } catch (NotFoundException $e) {
            Cascade::getLogger('error')->error($e->getMessage());
            _etsis_flash()->error(_etsis_flash()->notice(409));
        } catch (ORMException $e) {
            Cascade::getLogger('error')->error($e->getMessage());
            _etsis_flash()->error(_etsis_flash()->notice(409));
        } catch (Exception $e) {
            Cascade::getLogger('error')->error($e->getMessage());
            _etsis_flash()->error(_etsis_flash()->notice(409));
        }

        /**
         * If the database table doesn't exist, then it
         * is false and a 404 should be sent.
         */
        if ($q == false) {

            $app->view->display('error/404', [
                'title' => '404 Error'
            ]);
        } /**
         * If the query is legit, but there
         * is no data in the table, then 404
         * will be shown.
         */ elseif (empty($q) == true) {

            $app->view->display('error/404', [
                'title' => '404 Error'
            ]);
        } /**
         * If data is zero, 404 not found.
         */ elseif (count(_escape($q[0]['stuID'])) <= 0) {

            $app->view->display('error/404', [
                'title' => '404 Error'
            ]);
        } /**
         * If we get to this point, the all is well
         * and it is ok to process the query and print
         * the results in a html format.
         */ else {

            etsis_register_style('form');
            etsis_register_style('table');
            etsis_register_script('select');
            etsis_register_script('select2');
            etsis_register_script('datepicker');

            $app->view->display('financial/view-payments', [
                'title' => get_name(_escape($q[0]['stuID'])),
                'bill' => $q
            ]);
        }
    });

    $app->match('GET|POST', '/([^/]+)/refunds/', function ($id) use($app) {
        if ($app->req->isPost()) {
            try {
                $size = count($app->req->post['amount']);
                $i = 0;
                while ($i < $size) {
                    $q = $app->db->refund();
                    $q->termCode = $app->req->post['termCode'][$i];
                    $q->amount = $app->req->post['amount'][$i];
                    $q->comment = $app->req->post['comment'][$i];
                    $q->refundDate = $app->req->post['refundDate'][$i];
                    $q->where('id = ?', $app->req->post['id'][$i])->_and_()
                        ->where('stuID = ?', $app->req->post['stuID']);
                    $q->update();
                    ++$i;
                }
                etsis_cache_delete('refunds' . $id, 'student_account');
            } catch (NotFoundException $e) {
                Cascade::getLogger('error')->error($e->getMessage());
                _etsis_flash()->error(_etsis_flash()->notice(409));
            } catch (ORMException $e) {
                Cascade::getLogger('error')->error($e->getMessage());
                _etsis_flash()->error(_etsis_flash()->notice(409));
            } catch (Exception $e) {
                Cascade::getLogger('error')->error($e->getMessage());
                _etsis_flash()->error(_etsis_flash()->notice(409));
            }
        }

        try {
            $stuBill = $app->db->stu_acct_bill()
                ->setTableAlias('a')
                ->select('a.*,b.termName')
                ->select('c.id as refundID,c.amount,c.comment,c.refundDate')
                ->_join('term', 'a.termCode = b.termCode', 'b')
                ->_join('refund', 'a.stuID = c.stuID AND a.termCode = c.termCode', 'c')
                ->where('a.billID = ?', $id)
                ->orderBy('c.refundDate', 'DESC');
            $q = etsis_cache_get('refunds' . $id, 'student_account');
            if (empty($q)) {
                $q = $stuBill->find(function ($data) {
                    $array = [];
                    foreach ($data as $d) {
                        $array[] = $d;
                    }
                    return $array;
                });
                etsis_cache_add('refunds' . $id, $q, 'student_account');
            }
        } catch (NotFoundException $e) {
            Cascade::getLogger('error')->error($e->getMessage());
            _etsis_flash()->error(_etsis_flash()->notice(409));
        } catch (ORMException $e) {
            Cascade::getLogger('error')->error($e->getMessage());
            _etsis_flash()->error(_etsis_flash()->notice(409));
        } catch (Exception $e) {
            Cascade::getLogger('error')->error($e->getMessage());
            _etsis_flash()->error(_etsis_flash()->notice(409));
        }

        /**
         * If the database table doesn't exist, then it
         * is false and a 404 should be sent.
         */
        if ($q == false) {

            $app->view->display('error/404', [
                'title' => '404 Error'
            ]);
        } /**
         * If the query is legit, but there
         * is no data in the table, then 404
         * will be shown.
         */ elseif (empty($q) == true) {

            $app->view->display('error/404', [
                'title' => '404 Error'
            ]);
        } /**
         * If data is zero, 404 not found.
         */ elseif (count(_escape($q[0]['stuID'])) <= 0) {

            $app->view->display('error/404', [
                'title' => '404 Error'
            ]);
        } /**
         * If we get to this point, the all is well
         * and it is ok to process the query and print
         * the results in a html format.
         */ else {

            etsis_register_style('form');
            etsis_register_style('table');
            etsis_register_script('select');
            etsis_register_script('select2');
            etsis_register_script('datepicker');

            $app->view->display('financial/view-refunds', [
                'title' => get_name(_escape($q[0]['stuID'])),
                'bill' => $q
            ]);
        }
    });

    $app->match('GET|POST', '/([^/]+)/comments/', function ($id) use($app) {
        try {
            /**
             * Do not move this below isPost because isPost is using
             * data from this query.
             */
            $stuBill = $app->db->stu_acct_bill()
                ->setTableAlias('a')
                ->select('a.*,b.termName')
                ->_join('term', 'a.termCode = b.termCode', 'b')
                ->where('a.billID = ?', $id);
            $q = etsis_cache_get('comments' . $id, 'student_account');
            if (empty($q)) {
                $q = $stuBill->find(function ($data) {
                    $array = [];
                    foreach ($data as $d) {
                        $array[] = $d;
                    }
                    return $array;
                });
                etsis_cache_add('comments' . $id, $q, 'student_account');
            }
        } catch (NotFoundException $e) {
            Cascade::getLogger('error')->error($e->getMessage());
            _etsis_flash()->error(_etsis_flash()->notice(409));
        } catch (ORMException $e) {
            Cascade::getLogger('error')->error($e->getMessage());
            _etsis_flash()->error(_etsis_flash()->notice(409));
        } catch (Exception $e) {
            Cascade::getLogger('error')->error($e->getMessage());
            _etsis_flash()->error(_etsis_flash()->notice(409));
        }

        if ($app->req->isPost()) {
            try {
                $bill = $app->db->stu_acct_bill();
                foreach ($app->req->post as $k => $v) {
                    $bill->$k = $v;
                }
                $bill->where('billID = ?', $id);
                $bill->update();

                etsis_cache_delete('comments' . $id, 'student_account');
                etsis_logger_activity_log_write('Update Record', 'Student Bill Comments', get_name(_escape($q[0]['stuID'])) . ' (' . $id . ')', get_persondata('uname'));
                _etsis_flash()->success(_etsis_flash()->notice(200), $app->req->server['HTTP_REFERER']);
            } catch (NotFoundException $e) {
                Cascade::getLogger('error')->error($e->getMessage());
                _etsis_flash()->error(_etsis_flash()->notice(409), $app->req->server['HTTP_REFERER']);
            } catch (ORMException $e) {
                Cascade::getLogger('error')->error($e->getMessage());
                _etsis_flash()->error(_etsis_flash()->notice(409), $app->req->server['HTTP_REFERER']);
            } catch (Exception $e) {
                Cascade::getLogger('error')->error($e->getMessage());
                _etsis_flash()->error(_etsis_flash()->notice(409), $app->req->server['HTTP_REFERER']);
            }
        }

        /**
         * If the database table doesn't exist, then it
         * is false and a 404 should be sent.
         */
        if ($q == false) {

            $app->view->display('error/404', [
                'title' => '404 Error'
            ]);
        } /**
         * If the query is legit, but there
         * is no data in the table, then 404
         * will be shown.
         */ elseif (empty($q) == true) {

            $app->view->display('error/404', [
                'title' => '404 Error'
            ]);
        } /**
         * If data is zero, 404 not found.
         */ elseif (count(_escape($q[0]['stuID'])) <= 0) {

            $app->view->display('error/404', [
                'title' => '404 Error'
            ]);
        } /**
         * If we get to this point, the all is well
         * and it is ok to process the query and print
         * the results in a html format.
         */ else {

            etsis_register_style('form');
            etsis_register_script('select');
            etsis_register_script('select2');

            $app->view->display('financial/view-comments', [
                'title' => get_name(_escape($q[0]['stuID'])),
                'bill' => $q
            ]);
        }
    });

    /**
     * Before router check.
     */
    $app->before('GET', '/([^/]+)/delete/', function () {
        if (!hasPermission('delete_student')) {
            _etsis_flash()->error(_t('Permission denied to delete record.'), get_base_url() . 'dashboard' . '/');
            exit();
        }
    });

    $app->get('/([^/]+)/delete/', function ($id) use($app) {
        try {
            $q = $app->db->stu_acct_bill()
                ->select('stuID,termCode')
                ->where('billID = ?', $id)
                ->findOne();
            $app->db->stu_acct_fee()
                ->where('stuID = ?', $q->stuID)->_and_()
                ->where('termCode = ?', $q->termCode)
                ->delete();
            $app->db->stu_acct_tuition()
                ->where('stuID = ?', $q->stuID)->_and_()
                ->where('termCode = ?', $q->termCode)
                ->delete();
            $app->db->refund()
                ->where('stuID = ?', $q->stuID)->_and_()
                ->where('termCode = ?', $q->termCode)
                ->delete();
            $app->db->payment()
                ->where('stuID = ?', $q->stuID)->_and_()
                ->where('termCode = ?', $q->termCode)
                ->delete();
            $bill = $app->db->stu_acct_bill()
                ->where('billID = ?', $id);
            $bill->delete();

            etsis_cache_flush_namespace('student_account');
            etsis_logger_activity_log_write('Delete Record', 'Student Bill/Invoice', 'Bill/Invoice ID (#' . $id . ')', get_persondata('uname'));
            _etsis_flash()->success(_etsis_flash()->notice(200), get_base_url() . 'financial' . '/');
        } catch (NotFoundException $e) {
            Cascade::getLogger('error')->error($e->getMessage());
            _etsis_flash()->error(_etsis_flash()->notice(409), $app->req->server['HTTP_REFERER']);
        } catch (ORMException $e) {
            Cascade::getLogger('error')->error($e->getMessage());
            _etsis_flash()->error(_etsis_flash()->notice(409), $app->req->server['HTTP_REFERER']);
        } catch (Exception $e) {
            Cascade::getLogger('error')->error($e->getMessage());
            _etsis_flash()->error(_etsis_flash()->notice(409), $app->req->server['HTTP_REFERER']);
        }
    });
});

/**
 * Before route check.
 */
$app->before('GET|POST', '/sect/sbill/(\d+)/', function () {
    if (!hasPermission('access_course_sec_screen')) {
        _etsis_flash()->error(_t('403 - Error: Forbidden.'), get_base_url() . 'dashboard' . '/');
    }
});

$app->match('GET|POST', '/sect/sbill/(\d+)/', function ($id) use($app) {
    $section = get_course_sec($id);

    if ($app->req->isPost()) {
        try {
            $sect = $app->db->course_sec();
            foreach ($app->req->post as $k => $v) {
                $sect->$k = $v;
            }
            $sect->where('courseSecID = ?', $id);
            $sect->update();

            etsis_cache_delete($id, 'sect');
            etsis_logger_activity_log_write('Update Record', 'Course Section Billing', _escape($section->courseSection), get_persondata('uname'));
            _etsis_flash()->success(_etsis_flash()->notice(200), $app->req->server['HTTP_REFERER']);
        } catch (NotFoundException $e) {
            Cascade::getLogger('error')->error($e->getMessage());
            _etsis_flash()->error(_etsis_flash()->notice(409), $app->req->server['HTTP_REFERER']);
        } catch (ORMException $e) {
            Cascade::getLogger('error')->error($e->getMessage());
            _etsis_flash()->error(_etsis_flash()->notice(409), $app->req->server['HTTP_REFERER']);
        } catch (Exception $e) {
            Cascade::getLogger('error')->error($e->getMessage());
            _etsis_flash()->error(_etsis_flash()->notice(409), $app->req->server['HTTP_REFERER']);
        }
    }

    /**
     * If the database table doesn't exist, then it
     * is false and a 404 should be sent.
     */
    if ($section == false) {

        $app->view->display('error/404', [
            'title' => '404 Error'
        ]);
    } /**
     * If the query is legit, but there
     * is no data in the table, then 404
     * will be shown.
     */ elseif (empty($section) == true) {

        $app->view->display('error/404', [
            'title' => '404 Error'
        ]);
    } /**
     * If data is zero, 404 not found.
     */ elseif (_escape($section->courseSecID) <= 0) {

        $app->view->display('error/404', [
            'title' => '404 Error'
        ]);
    } /**
     * If we get to this point, the all is well
     * and it is ok to process the query and print
     * the results in a html format.
     */ else {

        etsis_register_style('form');
        etsis_register_script('select');
        etsis_register_script('select2');

        $app->view->display('section/billing-info', [
            'title' => _escape($section->secShortTitle) . ' :: Course Section',
            'sect' => $section
        ]);
    }
});

$app->group('/stu', function () use($app, $css, $js) {
    $app->match('GET|POST', '/paypal-ipn/', function () use($app) {

        $app->view->display('student/paypal-ipn', [
            'title' => 'Paypal IPN'
        ]);
    });

    /**
     * Before router check.
     */
    $app->before('GET|POST', '/payment-cancel/', function () {
        if (!is_user_logged_in()) {
            _etsis_flash()->error(_t('401 - Error: Unauthorized.'), get_base_url() . 'login' . '/');
        }
    });

    $app->match('GET|POST', '/payment-cancel/', function () use($app) {

        $app->view->display('student/payment-cancel', [
            'title' => 'Payment Cancelled'
        ]);
    });

    /**
     * Before router check.
     */
    $app->before('GET|POST', '/payment-success/', function () {
        if (!is_user_logged_in()) {
            _etsis_flash()->error(_t('401 - Error: Unauthorized.'), get_base_url() . 'login' . '/');
        }
    });

    $app->match('GET|POST', '/payment-success/', function () use($app) {

        $app->view->display('student/payment-success', [
            'title' => 'Payment Successful'
        ]);
    });

    /**
     * Before route check.
     */
    $app->before('POST', '/redirectPaypal/', function () {
        if (!is_user_logged_in()) {
            _etsis_flash()->error(_t('401 - Error: Unauthorized.'), get_base_url() . 'login' . '/');
            exit();
        }

        if (!checkStuAccess(get_persondata('personID'))) {
            _etsis_flash()->error(_t('You must be a student in order to access student screens.'), get_base_url());
            exit();
        }
    });

    $app->post('/redirectPaypal/', function () use($app) {

        $vars = [];
        $vars['amount'] = $app->req->_post('amount');
        $vars['cmd'] = $app->req->_post('cmd');
        $vars['business'] = $app->req->_post('business');
        $vars['currency_code'] = $app->req->_post('currency_code');
        $vars['item_name'] = $app->req->_post('item_name');
        $vars['return'] = $app->req->_post('return');
        $vars['notify_url'] = $app->req->_post('notify_url');
        $vars['cancel_return'] = $app->req->_post('cancel_return');
        $vars['custom'] = $app->req->_post('custom');
        $vars['item_number'] = $app->req->_post('item_number');
        $vars['shipping'] = $app->req->_post('shipping');
        etsis_cache_flush_namespace('student_account');
        etsis_redirect($app->req->_post('ppurl') . '?' . http_build_query($vars));
    });

    /**
     * Before route check.
     */
    $app->before('GET|POST', '/bill/', function () {
        if (!is_user_logged_in()) {
            _etsis_flash()->error(_t('401 - Error: Unauthorized.'), get_base_url() . 'login' . '/');
        }

        if (!checkStuAccess(get_persondata('personID'))) {
            _etsis_flash()->error(_t('You must be a student in order to access student screens.'), get_base_url());
        }

        if (_h(get_option('enable_myetsis_portal')) == 0 && !hasPermission('edit_myetsis_css')) {
            etsis_redirect(get_base_url() . 'offline' . '/');
        }
    });

    $app->get('/bill/', function () use($app) {

        $css = [
            'css/admin/module.admin.page.alt.tables.min.css'
        ];
        $js = [
            'components/modules/admin/tables/datatables/assets/lib/js/jquery.dataTables.min.js?v=v2.1.0',
            'components/modules/admin/tables/datatables/assets/lib/extras/TableTools/media/js/TableTools.min.js?v=v2.1.0',
            'components/modules/admin/tables/datatables/assets/custom/js/DT_bootstrap.js?v=v2.1.0',
            'components/modules/admin/tables/datatables/assets/custom/js/datatables.init.js?v=v2.1.0'
        ];

        $bill = $app->db->stu_acct_bill()
            ->select('billID,stuID,termCode')
            ->where('stuID = ?', get_persondata('personID'))
            ->groupBy('stuID,termCode');
        $q = etsis_cache_get('students_bill' . get_persondata('personID'), 'student_account');
        if (empty($q)) {
            $q = $bill->find(function ($data) {
                $array = [];
                foreach ($data as $d) {
                    $array[] = $d;
                }
                return $array;
            });
            etsis_cache_add('students_bill' . get_persondata('personID'), $q, 'student_account');
        }

        $app->view->display('student/bill', [
            'title' => 'My Bills',
            'cssArray' => $css,
            'jsArray' => $js,
            'bill' => $q
        ]);
    });

    /**
     * Before route check.
     */
    $app->before('GET|POST', '/bill/([^/]+)/', function () {
        if (!is_user_logged_in()) {
            _etsis_flash()->error(_t('401 - Error: Unauthorized.'), get_base_url() . 'login' . '/');
        }

        if (!checkStuAccess(get_persondata('personID'))) {
            _etsis_flash()->error(_t('You must be a student in order to access student screens.'), get_base_url());
        }

        if (_h(get_option('enable_myetsis_portal')) == 0 && !hasPermission('edit_myetsis_css')) {
            etsis_redirect(get_base_url() . 'offline' . '/');
        }
    });

    $app->match('GET|POST', '/bill/([^/]+)/', function ($id) use($app) {

        $bill = $app->db->stu_acct_bill()
            ->setTableAlias('a')
            ->select('a.*, b.termName')
            ->_join('term', 'a.termCode = b.termCode', 'b')
            ->where('a.billID = ?', $id)->_and_()
            ->where('a.stuID = ?', get_persondata('personID'));
        $q1 = etsis_cache_get('bill' . $id, 'student_account');
        if (empty($q1)) {
            $q1 = $bill->find(function ($data) {
                $array = [];
                foreach ($data as $d) {
                    $array[] = $d;
                }
                return $array;
            });
            etsis_cache_add('bill' . $id, $q1, 'student_account');
        }
        $stuTuition = $app->db->stu_acct_tuition()
            ->where('stuID = ?', $q1[0]['stuID'])->_and_()
            ->where('termCode = ?', $q1[0]['termCode']);
        $query = etsis_cache_get('stuTuition' . $id, 'student_account');
        if (empty($query)) {
            $query = $stuTuition->find(function ($data) {
                $array = [];
                foreach ($data as $d) {
                    $array[] = $d;
                }
                return $array;
            });
            etsis_cache_add('stuTuition' . $id, $query, 'student_account');
        }
        $tuition = $app->db->query('SELECT ' . 'SUM(amount) as sum ' . 'FROM stu_acct_fee ' . 'WHERE billID = ? ' . 'AND type = "Tuition" ' . 'GROUP BY stuID, termCode', [
            $id
        ]);
        $q2 = etsis_cache_get('tuition' . $id, 'student_account');
        if (empty($q2)) {
            $q2 = $tuition->find(function ($data) {
                $array = [];
                foreach ($data as $d) {
                    $array[] = $d;
                }
                return $array;
            });
            etsis_cache_add('tuition' . $id, $q2, 'student_account');
        }
        $fee = $app->db->query('SELECT ' . 'id, description, amount as Fee ' . 'FROM stu_acct_fee ' . 'WHERE billID = ? ' . 'AND type = "Fee"', [
            $id
        ]);
        $q3 = etsis_cache_get('fee' . $id, 'student_account');
        if (empty($q3)) {
            $q3 = $fee->find(function ($data) {
                $array = [];
                foreach ($data as $d) {
                    $array[] = $d;
                }
                return $array;
            });
            etsis_cache_add('fee' . $id, $q3, 'student_account');
        }
        $sumFee = $app->db->query("SELECT 
                        SUM(amount) as sum 
                    FROM stu_acct_fee 
                    WHERE billID = ? 
                    AND type = 'Fee' 
                    GROUP BY stuID,termCode", [
            $id
        ]);
        $q4 = etsis_cache_get('sumFee' . $id, 'student_account');
        if (empty($q4)) {
            $q4 = $sumFee->find(function ($data) {
                $array = [];
                foreach ($data as $d) {
                    $array[] = $d;
                }
                return $array;
            });
            etsis_cache_add('sumFee' . $id, $q4, 'student_account');
        }
        $sumPay = $app->db->query("SELECT 
                        SUM(amount) as sum 
                    FROM payment 
                    WHERE stuID = ? 
                    AND termCode = ? 
                    GROUP BY stuID,termCode", [
            $q1[0]['stuID'],
            $q1[0]['termCode']
        ]);
        $q5 = etsis_cache_get('sumPay' . $id, 'student_account');
        if (empty($q5)) {
            $q5 = $sumPay->find(function ($data) {
                $array = [];
                foreach ($data as $d) {
                    $array[] = $d;
                }
                return $array;
            });
            etsis_cache_add('sumPay' . $id, $q5, 'student_account');
        }
        $sumRefund = $app->db->query("SELECT 
                        SUM(amount) as sum 
                    FROM refund 
                    WHERE stuID = ? 
                    AND termCode = ? 
                    GROUP BY stuID,termCode", [
            $q1[0]['stuID'],
            $q1[0]['termCode']
        ]);
        $q6 = etsis_cache_get('sumRefund' . $id, 'student_account');
        if (empty($q6)) {
            $q6 = $sumRefund->find(function ($data) {
                $array = [];
                foreach ($data as $d) {
                    $array[] = $d;
                }
                return $array;
            });
            etsis_cache_add('sumRefund' . $id, $q6, 'student_account');
        }

        /**
         * If the database table doesn't exist, then it
         * is false and a 404 should be sent.
         */
        if ($q1 == false) {

            $app->view->display('error/404', [
                'title' => '404 Error'
            ]);
        } /**
         * If the query is legit, but there
         * is no data in the table, then 404
         * will be shown.
         */ elseif (empty($q1) == true) {

            $app->view->display('error/404', [
                'title' => '404 Error'
            ]);
        } /**
         * If data is zero, 404 not found.
         */ elseif (count($q1) <= 0) {

            $app->view->display('error/404', [
                'title' => '404 Error'
            ]);
        } /**
         * If we get to this point, the all is well
         * and it is ok to process the query and print
         * the results in a html format.
         */ else {

            $app->view->display('student/vbill', [
                'title' => $q1[0]['termCode'] . ' Bill',
                'bill' => $q1,
                'tuition1' => $query,
                'tuition2' => money_format('%n', (double) $q2[0]['sum']),
                'fee' => $q3,
                'begin' => money_format('-%n', (double) bcadd($query[0]['total'], $q4[0]['sum'])),
                'sumFee' => $q4[0]['sum'],
                'sumPayments' => $q5[0]['sum'],
                'sumRefund' => $q6[0]['sum']
            ]);
        }
    });

    /**
     * Before route check.
     */
    $app->before('GET|POST', '/account-history/', function () {
        if (!is_user_logged_in()) {
            _etsis_flash()->error(_t('401 - Error: Unauthorized.'), get_base_url() . 'login' . '/');
        }

        if (!checkStuAccess(get_persondata('personID'))) {
            _etsis_flash()->error(_t('You must be a student in order to access student screens.'), get_base_url());
        }

        if (_h(get_option('enable_myetsis_portal')) == 0 && !hasPermission('edit_myetsis_css')) {
            etsis_redirect(get_base_url() . 'offline' . '/');
        }
    });

    $app->get('/account-history/', function () use($app, $js) {
        $css = [
            'css/admin/module.admin.page.alt.tables.min.css'
        ];
        $js = [
            'components/modules/admin/tables/datatables/assets/lib/js/jquery.dataTables.min.js?v=v2.1.0',
            'components/modules/admin/tables/datatables/assets/lib/extras/TableTools/media/js/TableTools.min.js?v=v2.1.0',
            'components/modules/admin/tables/datatables/assets/custom/js/DT_bootstrap.js?v=v2.1.0',
            'components/modules/admin/tables/datatables/assets/custom/js/datatables.init.js?v=v2.1.0'
        ];
        $plan = $app->db->query('SELECT *,' . ' CASE payFrequency' . ' WHEN "1" THEN "Daily"' . ' WHEN "7" THEN "Weekly"' . ' WHEN "14" THEN "Bi-Weekly"' . ' WHEN "30" THEN "Monthly"' . ' ELSE "Yearly"' . ' END AS Frequency' . ' FROM stu_acct_pp' . ' WHERE stuID = ?', [
            get_persondata('personID')
        ]);
        $sql = etsis_cache_get('payment_plan' . get_persondata('personID'), 'student_account');
        if (empty($sql)) {
            $sql = $plan->find(function ($data) {
                $array = [];
                foreach ($data as $d) {
                    $array[] = $d;
                }
                return $array;
            });
            etsis_cache_add('payment_plan' . get_persondata('personID'), $sql, 'student_account');
        }
        $history = $app->db->query('SELECT 
            id AS FeeID, billID AS billID, stuID AS stuID,
            termCode AS termCode, type as type, description AS description,
            amount AS FeeAmount, NULL AS PayAmount, NULL AS method, feeDate AS dateStamp 
            FROM stu_acct_fee 
            WHERE stuID = ? 
            AND type = "Fee"
            UNION ALL 
                SELECT NULL as FeeID, NULL AS billID, stuID AS stuID, termCode AS termCode,
                NULL as type, "Tuition" as description, total AS FeeAmount, NULL AS PayAmount,
                NULL as method, tuitionTimeStamp AS dateStamp 
                FROM stu_acct_tuition 
                WHERE stuID = ? 
            UNION ALL 
            SELECT NULL as FeeID, NULL AS billID, stuID AS stuID, termCode AS termCode,
            NULL as type, "Payment" as description, NULL AS FeeAmount, amount AS PayAmount, 
            paymentTypeID as method, paymentDate AS dateStamp 
            FROM payment 
            WHERE stuID = ? 
            ORDER BY dateStamp ASC', [
            get_persondata('personID'),
            get_persondata('personID'),
            get_persondata('personID')
        ]);
        $q = etsis_cache_get('history' . get_persondata('personID'), 'student_account');
        if (empty($q)) {
            $q = $history->find(function ($data) {
                $array = [];
                foreach ($data as $d) {
                    $array[] = $d;
                }
                return $array;
            });
            etsis_cache_add('history' . get_persondata('personID'), $q, 'student_account');
        }
        /**
         * If the database table doesn't exist, then it
         * is false and a 404 should be sent.
         */
        if ($q === false) {

            $app->view->display('error/404', [
                'title' => '404 Error'
            ]);
        } /**
         * If the query is legit, but there
         * is no data in the table, then 404
         * will be shown.
         */ elseif (empty($q) === true) {

            $app->view->display('error/404', [
                'title' => '404 Error'
            ]);
        } /**
         * If data is zero, 404 not found.
         */ elseif (count($q[0]['stuID']) <= 0) {

            $app->view->display('error/404', [
                'title' => '404 Error'
            ]);
        } /**
         * If we get to this point, the all is well
         * and it is ok to process the query and print
         * the results in a html format.
         */ else {
            $app->view->display('student/account-history', [
                'title' => 'Student Account History',
                'cssArray' => $css,
                'jsArray' => $js,
                'history' => $q,
                'plan' => $sql
            ]);
        }
    });
});

$app->setError(function () use($app) {

    $app->view->display('error/404', [
        'title' => '404 Error'
    ]);
});
