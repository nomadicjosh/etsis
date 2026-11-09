<?php

if (!defined('BASE_PATH'))
    exit('No direct script access allowed');

use app\src\Core\Exception\NotFoundException;
use app\src\Core\Exception\Exception;
use PDOException as ORMException;
use Cascade\Cascade;

/**
 * Timesheet Router
 *  
 * @license GPLv3
 * 
 * @since       5.0.0
 * @package     eduTrac SIS
 * @author      Joshua Parker <joshmac3@icloud.com>
 */
/**
 * Before router middleware checks to see
 * if the user is logged in.
 */
$app->before('GET|POST', '/hr/timesheets/', function() {
    if (!is_user_logged_in()) {
        _etsis_flash()->error(_t('401 - Error: Unauthorized.'), get_base_url() . 'login' . '/');
        exit();
    }
    if (!hasPermission('access_human_resources')) {
        _etsis_flash()->error(_t('403 - Error: Forbidden.'), get_base_url() . 'dashboard' . '/');
        exit();
    }
});

$app->get('/hr/timesheets/', function () use($app) {

    try {
        $time = $app->db->timesheet()
                ->select('timesheet.employeeID,timesheet.workWeek')
                ->select('SEC_TO_TIME(SUM(TIME_TO_SEC(TIMEDIFF(timesheet.endDateTime,timesheet.startDateTime)))) AS WorkHours')
                ->select('b.title,b.hourly_wage')
                ->_join('job', 'timesheet.jobID = b.id', 'b')
                ->groupBy('timesheet.employeeID,timesheet.workWeek')
                ->orderBy('timesheet.workWeek', 'DESC');
        $q = $time->find(function($data) {
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

    etsis_register_style('table');
    etsis_register_script('select');
    etsis_register_script('select2');
    etsis_register_script('datatables');
    etsis_register_script('datepicker');
    etsis_register_script('timepicker');

    $app->view->display('hr/timesheets', [
        'title' => 'Timesheets',
        'time' => $q
            ]
    );
});

/**
 * Before router middleware checks to see
 * if the user is logged in.
 */
$app->before('GET|POST', '/hr/view-timesheet/([^/]+)/(\d+)/', function() {
    if (!is_user_logged_in()) {
        _etsis_flash()->error(_t('401 - Error: Unauthorized.'), get_base_url() . 'login' . '/');
        exit();
    }
    if (!hasPermission('access_human_resources')) {
        _etsis_flash()->error(_t('403 - Error: Forbidden.'), get_base_url() . 'dashboard' . '/');
        exit();
    }
});

$app->match('GET|POST', '/hr/view-timesheet/([^/]+)/(\d+)/', function ($week, $id) use($app) {

    if ($app->req->isPost()) {
        try {
            if (isset($app->req->post['employeeID'])) {
                $size = count($app->req->post['id']);
                $i = 0;
                while ($i < $size) {
                    $time = $app->db->timesheet()->where('id = ?', $app->req->post['id'][$i]);
                    $q = $time->find(function($data) {
                        $array = [];
                        foreach ($data as $d) {
                            $array[] = $d;
                        }
                        return $array;
                    });

                    $a = [];
                    foreach ($q as $r) {
                        $a[] = $r;
                    }

                    if ($r['approvedBy'][$i] == '0' && $app->req->post['status'][$i] == 'A') {
                        $q = $app->db->timesheet();
                        $q->status = $app->req->post['status'][$i];
                        $q->approvedBy = get_persondata('personID');
                        $q->where('timesheet.id = ?', $app->req->post['id'][$i])->_and_()
                                ->where('timesheet.employeeID = ?', $app->req->post['employeeID'])
                                ->update();
                    } else {
                        $q = $app->db->timesheet();
                        $q->status = $app->req->post['status'][$i];
                        $q->where('timesheet.id = ?', $app->req->post['id'][$i])->_and_()
                                ->where('timesheet.employeeID = ?', $app->req->post['employeeID'])
                                ->update();
                    }
                    ++$i;
                }
            } else {
                $ts = $app->db->timesheet();
                $ts->workWeek = $app->req->post['workWeek'];
                $ts->startDateTime = $app->req->post['startDate'] . ' ' . $app->req->post['startTime'];
                $ts->endDateTime = $app->req->post['startDate'] . ' ' . $app->req->post['endTime'];
                $ts->note = $app->req->post['note'];
                $ts->where('id = ?', _filter_input_int(INPUT_POST, 'id'));
                $ts->update();
            }
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
        $time = $app->db->timesheet()
                ->select('timesheet.id,timesheet.employeeID,timesheet.jobID,timesheet.workWeek')
                ->select('timesheet.startDateTime,timesheet.endDateTime,timesheet.note')
                ->select('TIMEDIFF(timesheet.endDateTime,timesheet.startDateTime) AS WorkHours')
                ->select('timesheet.status,b.title')
                ->_join('job', 'timesheet.jobID = b.id', 'b')
                ->where('timesheet.workWeek = ?', $week)->_and_()
                ->where('timesheet.employeeID = ?', $id)
                ->orderBy('timesheet.startDateTime');

        $q = $time->find(function($data) {
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
    if ($q == false) {

        $app->view->display('error/404', ['title' => '404 Error']);
    }
    /**
     * If the query is legit, but there
     * is no data in the table, then 404
     * will be shown.
     */ elseif (empty($q) == true) {

        $app->view->display('error/404', ['title' => '404 Error']);
    }
    /**
     * If data is zero, 404 not found.
     */ elseif (_h($q[0]['employeeID']) <= 0) {

        $app->view->display('error/404', ['title' => '404 Error']);
    }
    /**
     * If we get to this point, the all is well
     * and it is ok to process the query and print
     * the results in a html format.
     */ else {

        etsis_register_style('table');
        etsis_register_script('select');
        etsis_register_script('select2');
        etsis_register_script('datatables');
        etsis_register_script('datepicker');
        etsis_register_script('timepicker');

        $app->view->display('hr/view-timesheet', [
            'title' => 'Timesheets',
            'time' => $q
                ]
        );
    }
});

/**
 * Before route middleware check.
 */
$app->before('GET|POST', '/staff/timesheets/', function() {
    if (!is_user_logged_in()) {
        _etsis_flash()->error(_t('401 - Error: Unauthorized.'), get_base_url() . 'login' . '/');
        exit();
    }
    if (!hasPermission('submit_timesheets')) {
        _etsis_flash()->error(_t('403 - Error: Forbidden.'), get_base_url() . 'dashboard' . '/');
        exit();
    }
});

$app->match('GET|POST', '/staff/timesheets/', function() use($app) {

    if ($app->req->isPost()) {
        try {
            $time = $app->db->timesheet();
            $time->employeeID = $app->req->post['employeeID'];
            $time->jobID = $app->req->post['jobID'];
            $time->startDateTime = $app->req->post['workDay'] . ' ' . $app->req->post['startTime'];
            $time->endDateTime = $app->req->post['workDay'] . ' ' . $app->req->post['endTime'];
            $time->note = $app->req->post['note'];
            $time->addDate = $app->req->post['addDate'];
            $time->workWeek = $app->req->post['workWeek'];
            $time->addedBy = $app->req->post['addedBy'];
            $time->save();

            etsis_logger_activity_log_write('New Record', 'Timesheet', get_name(get_persondata('personID')), get_persondata('uname'));
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
        $time = $app->db->timesheet()
                ->select('timesheet.workWeek')
                ->select('SEC_TO_TIME(SUM(TIME_TO_SEC(TIMEDIFF(timesheet.endDateTime,timesheet.startDateTime)))) AS WorkHours')
                ->select('b.title')
                ->_join('job', 'timesheet.jobID = b.id', 'b')
                ->where('timesheet.employeeID = ?', get_persondata('personID'))
                ->groupBy('timesheet.workWeek')
                ->orderBy('timesheet.workWeek', 'DESC');
        $q = $time->find(function($data) {
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
    etsis_register_script('datepicker');
    etsis_register_script('timepicker');

    $app->view->display('staff/timesheets', [
        'title' => 'Timesheets',
        'timesheets' => $q
            ]
    );
});

/**
 * Before route middleware check.
 */
$app->before('GET|POST', '/staff/view-timesheet/([^/]+)/', function() {
    if (!is_user_logged_in()) {
        _etsis_flash()->error(_t('401 - Error: Unauthorized.'), get_base_url() . 'login' . '/');
        exit();
    }
    if (!hasPermission('submit_timesheets')) {
        _etsis_flash()->error(_t('403 - Error: Forbidden.'), get_base_url() . 'dashboard' . '/');
        exit();
    }
});

$app->get('/staff/view-timesheet/([^/]+)/', function($week) use($app) {

    try {
        $time = $app->db->query("SELECT CASE status WHEN 'P' THEN 'Pending'
            WHEN 'R' THEN 'Rejected' ELSE 'Approved' END AS 'Status',
            id, employeeID, jobID, workWeek, startDateTime, endDateTime,
            note, TIMEDIFF(endDateTime,startDateTime) AS WorkHours
            FROM timesheet
            WHERE workWeek = ?
            AND employeeID = ?
            GROUP BY id", [$week, get_persondata('personID')]
        );
        $q = $time->find(function($data) {
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
    if ($q == false) {

        $app->view->display('error/404', ['title' => '404 Error']);
    }
    /**
     * If the query is legit, but there
     * is no data in the table, then 404
     * will be shown.
     */ elseif (empty($q) == true) {

        $app->view->display('error/404', ['title' => '404 Error']);
    }
    /**
     * If data is zero, 404 not found.
     */ elseif (_h($q[0]['employeeID']) <= 0) {

        $app->view->display('error/404', ['title' => '404 Error']);
    }
    /**
     * If we get to this point, the all is well
     * and it is ok to process the query and print
     * the results in a html format.
     */ else {

        etsis_register_style('form');
        etsis_register_style('table');
        etsis_register_script('select');
        etsis_register_script('select2');
        etsis_register_script('datatables');
        etsis_register_script('datepicker');
        etsis_register_script('timepicker');

        $app->view->display('staff/view-timesheet', [
            'title' => 'View Timesheet',
            'timesheet' => $q
                ]
        );
    }
});

$app->setError(function() use($app) {

    $app->view->display('error/404', ['title' => '404 Error']);
});
