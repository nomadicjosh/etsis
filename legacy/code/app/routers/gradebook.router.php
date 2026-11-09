<?php
if (!defined('BASE_PATH'))
    exit('No direct script access allowed');
use app\src\Core\NodeQ\etsis_NodeQ as Node;
use app\src\Core\NodeQ\NodeQException;
use app\src\Core\Exception\NotFoundException;
use app\src\Core\Exception\Exception;
use PDOException as ORMException;
use Cascade\Cascade;

/**
 * Gradebook Router
 *
 * @license GPLv3
 *         
 * @since 5.0.0
 * @package eduTrac SIS
 * @author Joshua Parker <joshmac3@icloud.com>
 */
/**
 * Before route check.
 */
$app->before('GET|POST', '/sect/courses/', function () {
    if (!is_user_logged_in()) {
        _etsis_flash()->error(_t('401 - Error: Unauthorized.'), get_base_url() . 'login' . '/');
        exit();
    }

    if (!hasPermission('access_grading_screen')) {
        _etsis_flash()->error(_t('403 - Error: Forbidden.'), get_base_url() . 'dashboard' . '/');
        exit();
    }
});

$app->get('/sect/courses/', function () use($app) {

    try {
        $sect = $app->db->course_sec()
            ->where('facID = ?', get_persondata('personID'))->_and_()
            ->where('currStatus = "A"')
            ->groupBy('termCode,courseSecCode')
            ->orderBy('termCode', 'DESC');
        $q = $sect->find(function ($data) {
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

    $app->view->display('section/courses', [
        'title' => 'My Course Sections',
        'sect' => $q
    ]);
});

/**
 * Before route check.
 */
$app->before('GET|POST', '/sect/(\d+)/attendance/', function () {
    if (!hasPermission('access_grading_screen')) {
        _etsis_flash()->error(_t('403 - Error: Forbidden.'), get_base_url() . 'sect/courses' . '/');
    }
});

$app->match('GET|POST', '/sect/(\d+)/attendance/', function ($id) use($app) {

    if ($app->req->isPost()) {
        try {
            $size = count($app->req->post['stuID']);
            $i = 0;
            while ($i < $size) {
                $record = $app->db->attendance()
                    ->where('courseSecID = ?', $app->req->post['courseSecID'])->_and_()
                    ->where('stuID = ?', $app->req->post['stuID'][$i])->_and_()
                    ->where('date = ?', $app->req->post['today'])
                    ->findOne();

                $data = [];
                $data['status'] = $app->req->post['status'];

                if ($record) {
                    $attend = $app->db->attendance();
                    $attend->set([
                            'date' => $app->req->post['today'],
                            'status' => $data['status'][$app->req->post['stuID'][$i]],
                            'comment' => $app->req->post['comment'][$i] != '' ? $app->req->post['comment'][$i] : NULL
                        ])
                        ->where('courseSecID = ?', $app->req->post['courseSecID'])->_and_()
                        ->where('facID = ?', get_persondata('personID'))->_and_()
                        ->where('stuID = ?', $app->req->post['stuID'][$i]);
                    $attend->update();
                } else {
                    $attend = $app->db->attendance();
                    $attend->insert([
                        'courseSecID' => $app->req->post['courseSecID'],
                        'stuID' => $app->req->post['stuID'][$i],
                        'facID' => get_persondata('personID'),
                        'date' => $app->req->post['today'],
                        'status' => $data['status'][$app->req->post['stuID'][$i]],
                        'comment' => $app->req->post['comment'][$i] != '' ? $app->req->post['comment'][$i] : NULL
                    ]);
                }
                ++$i;
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
        $stcs = $app->db->stcs()
            ->select('stcs.*,course_sec.secShortTitle,course_sec.courseSection')
            ->_join('course_sec', 'stcs.courseSecID = course_sec.courseSecID')
            ->_join('person', 'stcs.stuID = person.personID')
            ->where('stcs.courseSecID = ?', $id)->_and_()
            ->where('course_sec.facID = ?', get_persondata('personID'))
            ->orderBy('person.lname')
            ->find();
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
    
    $sect = get_course_sec($id);

    /**
     * If the database table doesn't exist, then it
     * is false and a 404 should be sent.
     */
    if ($stcs == false) {

        $app->view->display('error/404', [
            'title' => '404 Error'
        ]);
    } /**
     * If the query is legit, but there
     * is no data in the table, then 404
     * will be shown.
     */ elseif (empty($stcs) == true) {

        $app->view->display('error/404', [
            'title' => '404 Error'
        ]);
    } /**
     * If data is zero, 404 not found.
     */ elseif ($stcs->count() <= 0) {

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
        etsis_register_script('checked');

        $app->view->display('section/attendance', [
            'title' => 'View/Edit Course Section',
            'stcs' => $stcs,
            'sect' => $sect
        ]);
    }
});

/**
 * Before route check.
 */
$app->before('GET|POST', '/sect/(\d+)/attendance-report/', function () {
    if (!hasPermission('access_grading_screen')) {
        _etsis_flash()->error(_t('403 - Error: Forbidden.'), get_base_url() . 'sect/courses' . '/');
    }
});

$app->match('GET', '/sect/(\d+)/attendance-report/', function ($id) use($app) {
$sect = get_course_sec($id);
    try {
        $attend = $app->db->attendance()
            ->select('attendance.stuID,attendance.courseSecID,course_sec.courseSection,course_sec.secShortTitle')
            ->_join('course_sec', 'attendance.courseSecID = course_sec.courseSecID')
            ->_join('person', 'attendance.stuID = person.personID')
            ->where('attendance.courseSecID = ?', $id)->_and_()
            ->where('attendance.facID = ?', get_persondata('personID'))
            ->groupBy('attendance.courseSecID,attendance.stuID')
            ->orderBy('person.lname');
        $q = $attend->find(function ($data) {
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
     */ elseif (_escape($q[0]['courseSecID']) <= 0) {

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
        etsis_register_script('datatables');
        etsis_register_script('highcharts');
        etsis_register_script('attendance');

        $app->view->display('section/attendance-report', [
            'title' => _escape($sect->secShortTitle) . _t(' Attendance Report'),
            'sect' => $q,
            'section' => $sect
        ]);
    }
});

/**
 * Before route check.
 */
$app->before('GET|POST', '/sect/(\d+)/assignments/', function () {
    if (!hasPermission('access_gradebook')) {
        _etsis_flash()->error(_t('403 - Error: Forbidden.'), get_base_url() . 'sect/courses' . '/');
    }
});

$app->get('/sect/(\d+)/assignments/', function ($id) use($app) {

    try {
        $assign = $app->db->assignment()
            ->setTableAlias('a')
            ->select('a.*,b.secShortTitle,b.courseSection')
            ->_join('course_sec', 'a.courseSecID = b.courseSecID', 'b')
            ->where('a.courseSecID = ?', $id)->_and_()
            ->where('b.facID = ?', get_persondata('personID'))
            ->groupBy('a.title');
        $q = $assign->find(function ($data) {
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
    
    $sect = get_course_sec($id);

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
     */ elseif (count(_escape($q[0]['courseSecID'])) <= 0) {

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

        $app->view->display('section/assignments', [
            'title' => _escape($sect->secShortTitle) . _t(' Assignments'),
            'assign' => $q,
            'sect' => $sect
        ]);
    }
});

/**
 * Before route check.
 */
$app->before('GET|POST', '/sect/(\d+)/add-assignment/', function () {
    if (!hasPermission('access_gradebook')) {
        _etsis_flash()->error(_t('403 - Error: Forbidden.'), get_base_url() . 'sect/courses' . '/');
        exit();
    }
});

$app->match('GET|POST', '/sect/(\d+)/add-assignment/', function ($id) use($app) {

    if ($app->req->isPost()) {
        try {
            $assign = $app->db->assignment();
            $assign->courseSecID = $id;
            $assign->facID = get_persondata('personID');
            $assign->shortName = $app->req->post['shortName'];
            $assign->title = $app->req->post['title'];
            $assign->dueDate = $app->req->post['dueDate'];
            $assign->addDate = \Jenssegers\Date\Date::now();
            $assign->addedBy = get_persondata('personID');
            $assign->save();

            _etsis_flash()->success(_etsis_flash()->notice(200), get_base_url() . 'sect' . '/' . $id . '/assignments/');
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
        $sect = $app->db->course_sec()
            ->select('courseSecID,secShortTitle,courseSection')
            ->where('courseSecID = ?', $id)->_and_()
            ->where('facID = ?', get_persondata('personID'))
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

    /**
     * If the database table doesn't exist, then it
     * is false and a 404 should be sent.
     */
    if ($sect == false) {

        $app->view->display('error/404', [
            'title' => '404 Error'
        ]);
    } /**
     * If the query is legit, but there
     * is no data in the table, then 404
     * will be shown.
     */ elseif (empty($sect) == true) {

        $app->view->display('error/404', [
            'title' => '404 Error'
        ]);
    } /**
     * If data is zero, 404 not found.
     */ elseif (_escape($sect->courseSecID) <= 0) {

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

        $app->view->display('section/add-assignment', [
            'title' => _escape($sect->courseSection),
            'sect' => $sect
        ]);
    }
});

/**
 * Before route check.
 */
$app->before('GET|POST', '/sect/(\d+)/(\d+)/assignment/', function () {
    if (!hasPermission('access_gradebook')) {
        _etsis_flash()->error(_t('403 - Error: Forbidden.'), get_base_url() . 'sect/courses' . '/');
        exit();
    }
});

$app->match('GET|POST', '/sect/(\d+)/(\d+)/assignment/', function ($sectID, $id) use($app) {
    if ($app->req->isPost()) {
        try {
            $assign = $app->db->assignment();
            foreach ($app->req->post as $k => $v) {
                $assign->$k = $v;
            }
            $assign->where('id = ?', $id)->_and_()
                ->where('courseSecID = ?', $sectID);
            $assign->update();

            etsis_logger_activity_log_write('Update Record', 'Course Section Assignment', _filter_input_string(INPUT_POST, 'title'), get_persondata('uname'));
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
        $assign = $app->db->course_sec()
            ->setTableAlias('a')
            ->select('a.courseSecID,a.courseSection,a.secShortTitle')
            ->select('b.id,b.shortName,b.title,b.dueDate')
            ->_join('assignment', 'a.courseSecID = b.courseSecID', 'b')
            ->where('a.courseSecID = ?', $sectID)->_and_()
            ->where('b.id = ?', $id)->_and_()
            ->where('a.facID = ?', get_persondata('personID'))
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

    /**
     * If the database table doesn't exist, then it
     * is false and a 404 should be sent.
     */
    if ($assign == false) {

        $app->view->display('error/404', [
            'title' => '404 Error'
        ]);
    } /**
     * If the query is legit, but there
     * is no data in the table, then 404
     * will be shown.
     */ elseif (empty($assign) == true) {

        $app->view->display('error/404', [
            'title' => '404 Error'
        ]);
    } /**
     * If data is zero, 404 not found.
     */ elseif (_escape($assign->courseSecID) <= 0) {

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

        $app->view->display('section/view-assignment', [
            'title' => _escape($assign->secShortTitle) , _t(' Edit Assignment'),
            'sect' => $assign
        ]);
    }
});

/**
 * Before route check.
 */
$app->before('GET|POST', '/sect/(\d+)/gradebook/', function () {
    if (!hasPermission('access_gradebook')) {
        _etsis_flash()->error(_t('403 - Error: Forbidden.'), get_base_url() . 'sect/courses' . '/');
    }
});

$app->match('GET|POST', '/sect/(\d+)/gradebook/', function ($id) use($app) {

    try {
        $assign = $app->db->assignment()
            ->setTableAlias('a')
            ->select('a.*,b.courseSecID,b.secShortTitle,b.courseSection')
            ->_join('course_sec', 'a.courseSecID = b.courseSecID', 'b')
            ->where('a.courseSecID = ?', $id)->_and_()
            ->where('a.facID = ?', get_persondata('personID'))
            ->orderBy('a.title')
            ->find();
        $stu = $app->db->gradebook()
            ->setTableAlias('a')
            ->_join('person', 'a.stuID = b.personID', 'b')
            ->where('a.courseSecID = ?', $id)->_and_()
            ->where('a.facID = ?', get_persondata('personID'))
            ->groupBy('a.stuID')
            ->orderBy('b.lname');
        $q2 = $stu->find(function ($data) {
            $array = [];
            foreach ($data as $d) {
                $array[] = $d;
            }
            return $array;
        });
        
        $section = get_course_sec($id);
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
    if ($assign == false) {

        $app->view->display('error/404', [
            'title' => '404 Error'
        ]);
    } /**
     * If the query is legit, but there
     * is no data in the table, then 404
     * will be shown.
     */ elseif (empty($assign) == true) {

        $app->view->display('error/404', [
            'title' => '404 Error'
        ]);
    } /**
     * If data is zero, 404 not found.
     */ elseif (count($assign) <= 0) {

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

        $app->view->display('section/gradebook', [
            'title' => _escape($section->courseSection),
            'sect' => $assign,
            'gradeStu' => $q2,
            'section' => $section
        ]);
    }
});

/**
 * Before route check.
 */
$app->before('GET|POST', '/sect/(\d+)/(\d+)/grading/', function () {
    if (!hasPermission('access_gradebook')) {
        _etsis_flash()->error(_t('403 - Error: Forbidden.'), get_base_url() . 'sect/courses' . '/');
        exit();
    }
});

$app->match('GET|POST', '/sect/(\d+)/(\d+)/grading/', function ($sectID, $id) use($app) {

    if ($app->req->isPost()) {
        try {
            $size = count($app->req->post['stuID']);
            $i = 0;
            while ($i < $size) {
                $record = $app->db->gradebook()
                    ->where('courseSecID = ?', $sectID)->_and_()
                    ->where('assignID = ?', $id)->_and_()
                    ->where('facID = ?', get_persondata('personID'))->_and_()
                    ->where('stuID = ?', $app->req->post['stuID'][$i])
                    ->findOne();

                if ($record) {
                    $grade = $app->db->gradebook();
                    $grade->grade = if_null($app->req->post['grade'][$i]);
                    $grade->where('courseSecID = ?', $sectID)->_and_()
                        ->where('assignID = ?', $id)->_and_()
                        ->where('facID = ?', get_persondata('personID'))->_and_()
                        ->where('stuID = ?', $app->req->post['stuID'][$i]);
                    $grade->update();
                } else {
                    $grade = $app->db->gradebook();
                    $grade->insert([
                        'assignID' => $id,
                        'courseSecID' => $app->req->post['courseSecID'],
                        'facID' => get_persondata('personID'),
                        'stuID' => $app->req->post['stuID'][$i],
                        'grade' => if_null($app->req->post['grade'][$i]),
                        'addDate' => \Jenssegers\Date\Date::now(),
                        'addedBy' => get_persondata('personID')
                    ]);
                }
                ++$i;
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
        $grades = $app->db->stac()
            ->select('stac.stuID,stac.courseSecCode,stac.shortTitle,stac.courseSecID')
            ->select('stac.courseSection,b.id,b.facID,b.title,c.grade')
            ->_join('assignment', 'stac.courseSecID = b.courseSecID', 'b')
            ->_join('gradebook', 'b.id = c.assignID', 'c')
            ->_join('person', 'stac.stuID = d.personID', 'd')
            ->where('b.id = ?', $id)->_and_()
            ->where('b.facID = ?', get_persondata('personID'))
            ->groupBy('stac.stuID')
            ->orderBy('d.lname');
        $q = $grades->find(function ($data) {
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
    
    $section = get_course_sec($sectID);

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
     */ elseif (_escape($q[0]['courseSecID']) <= 0) {

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

        $app->view->display('section/grading', [
            'title' => _escape($section->secShortTitle) . ' Grading',
            'sect' => $q,
            'section' => $section
        ]);
    }
});

/**
 * Before route check.
 */
$app->before('GET|POST', '/sect/(\d+)/(\d+)/export/', function () {
    if (!hasPermission('access_gradebook')) {
        _etsis_flash()->error(_t('403 - Error: Forbidden.'), get_base_url() . 'sect/courses' . '/');
    }
});

$app->match('GET|POST', '/sect/(\d+)/(\d+)/export/', function ($sectID, $id) use($app) {

    try {
        $grades = $app->db->stac()
            ->select('stac.stuID,stac.courseSecCode,stac.shortTitle,stac.courseSecID')
            ->select('stac.courseSection,b.id,b.facID,b.title,c.grade')
            ->_join('assignment', 'stac.courseSecID = b.courseSecID', 'b')
            ->_join('gradebook', 'b.id = c.assignID', 'c')
            ->_join('person', 'stac.stuID = d.personID', 'd')
            ->where('b.courseSecID = ?', $sectID)->_and_()
            ->where('b.id = ?', $id)->_and_()
            ->where('b.facID = ?', get_persondata('personID'))
            ->groupBy('stac.stuID')
            ->orderBy('d.lname');
        $q = $grades->find(function ($data) {
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

    $section = get_course_sec($sectID);
    
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
     */ elseif (_escape($q[0]['courseSecID']) <= 0) {

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
        etsis_register_script('datatables');

        $app->view->display('section/export-grades', [
            'title' => _escape($q[0]['courseSection']),
            'sect' => $q,
            'section' => $section
        ]);
    }
});

/**
 * Before route check.
 */
$app->before('GET|POST', '/sect/(\d+)/final-grade/', function () {
    if (!is_user_logged_in()) {
        _etsis_flash()->error(_t('401 - Error: Unauthorized.'), get_base_url() . 'login' . '/');
        exit();
    }

    if (!hasPermission('access_grading_screen')) {
        _etsis_flash()->error(_t('403 - Error: Forbidden.'), get_base_url() . 'sect/courses' . '/');
        exit();
    }
});

$app->match('GET|POST', '/sect/(\d+)/final-grade/', function ($id) use($app) {

    if ($app->req->isPost()) {
        try {
            $size = count($app->req->post['stuID']);
            $i = 0;
            while ($i < $size) {
                if (calculate_grade_points($app->req->post['grade'][$i], $app->req->post['attCredit']) > 0) {
                    $compCred = $app->req->post['attCredit'];
                } else {
                    $compCred = '0';
                }

                $gr = $app->db->stac();
                $gr->grade = if_null($app->req->post['grade'][$i]);
                $gr->gradePoints = calculate_grade_points($app->req->post['grade'][$i], $app->req->post['attCredit']);
                $gr->compCred = $compCred;
                $gr->where('courseSecID = ?', $id)->_and_()
                    ->where('stuID = ?', $app->req->post['stuID'][$i]);
                $gr->update();
                etsis_logger_activity_log_write('Update Record', 'Final Grade', get_name($app->req->post['stuID'][$i]) . ' (' . $app->req->post['courseSection'] . ')', get_persondata('uname'));
                ++$i;
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
        $grade = $app->db->stac()
            ->select('stac.stuID,stac.courseSecCode,stac.termCode,stac.courseSection')
            ->select('stac.grade,b.secShortTitle,b.minCredit,b.courseSecID')
            ->_join('course_sec', 'stac.courseSecID = b.courseSecID', 'b')
            ->where('stac.courseSecID = ?', $id)->_and_()
            ->where('b.facID = ?', get_persondata('personID'));
        $q = $grade->find(function ($data) {
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
    
    $section = get_course_sec($id);

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
     */ elseif (_escape($q[0]['courseSecID']) <= 0) {

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
        etsis_register_script('datatables');

        $app->view->display('section/final-grade', [
            'title' => _escape($section->secShortTitle) . _t(':: Final Grade'),
            'sect' => $q,
            'section' => $section
        ]);
    }
});

/**
 * Before route check.
 */
$app->before('GET|POST', '/sect/(\d+)/students/', function () {
    if (!hasPermission('access_grading_screen')) {
        _etsis_flash()->error(_t('403 - Error: Forbidden.'), get_base_url() . 'sect/courses' . '/');
    }
});

$app->match('GET|POST', '/sect/(\d+)/students/', function ($id) use($app) {

    try {
        $student = $app->db->stac()
            ->select('stac.courseSecCode,stac.stuID,stac.courseSecID')
            ->select('stac.courseSection,b.email')
            ->_join('person', 'stac.stuID = b.personID', 'b')
            ->_join('course_sec', 'stac.courseSecID = c.courseSecID', 'c')
            ->where('stac.courseSecID = ?', $id)->_and_()
            ->where('c.facID = ?', get_persondata('personID'))->_and_()
            ->whereIn('stac.status', [
            'A',
            'N'
        ]);
        $q = $student->find(function ($data) {
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
    
    $section = get_course_sec($id);

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
     */ elseif (_escape($q[0]['courseSecID']) <= 0) {

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
        etsis_register_script('upload');

        $app->view->display('section/students', [
            'title' => _escape($section->secShortTitle) . _t(':: Email Students'),
            'sect' => $q,
            'section' => $section
        ]);
    }
});

/**
 * Before route check.
 */
$app->before('GET|POST', '/stu/grades/(\d+)/', function () {
    if (!checkStuAccess(get_persondata('personID'))) {
        etsis_redirect(get_base_url() . 'profile' . '/');
    }

    if (_escape(get_option('enable_myetsis_portal')) == 0 && !hasPermission('edit_myetsis_css')) {
        etsis_redirect(get_base_url() . 'offline' . '/');
    }
});

$app->get('/stu/grades/(\d+)/', function ($id) use($app) {

    $css = [
        'css/admin/module.admin.page.alt.tables.min.css'
    ];
    $js = [
        'components/modules/admin/tables/datatables/assets/lib/js/jquery.dataTables.min.js?v=v2.1.0',
        'components/modules/admin/tables/datatables/assets/lib/extras/TableTools/media/js/TableTools.min.js?v=v2.1.0',
        'components/modules/admin/tables/datatables/assets/custom/js/DT_bootstrap.js?v=v2.1.0',
        'components/modules/admin/tables/datatables/assets/custom/js/datatables.init.js?v=v2.1.0'
    ];

    try {
        $ga = $app->db->assignment()
            ->setTableAlias('a')
            ->select('a.*,b.secShortTitle,b.termCode,b.courseSection')
            ->_join('course_sec', 'a.courseSecID = b.courseSecID', 'b')
            ->where('a.courseSecID = ?', $id)
            ->groupBy('a.title');
        $q1 = $ga->find(function ($data) {
            $array = [];
            foreach ($data as $d) {
                $array[] = $d;
            }
            return $array;
        });
        $gs = $app->db->gradebook()
            ->setTableAlias('a')
            ->select('a.*')
            ->_join('stac', 'a.courseSecID = stac.courseSecID AND a.stuID = stac.stuID')
            ->where('a.courseSecID = ?', $id)->_and_()
            ->where('a.stuID = ?', get_persondata('personID'))
            ->groupBy('a.stuID');
        $q2 = $gs->find(function ($data) {
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
     */ elseif (count(_escape($q1[0]['courseSecID'])) <= 0) {

        $app->view->display('error/404', [
            'title' => '404 Error'
        ]);
    } /**
     * If we get to this point, the all is well
     * and it is ok to process the query and print
     * the results in a html format.
     */ else {

        $app->view->display('student/grades', [
            'title' => _escape($q1[0]['courseSection']) . ' Assignment Grades',
            'cssArray' => $css,
            'jsArray' => $js,
            'assign' => $q1,
            'stac' => $q2
        ]);
    }
});

/**
 * Before route check.
 */
$app->before('POST', '/sect/emailStudent/', function () {
    if (!is_user_logged_in()) {
        _etsis_flash()->error(_t('401 - Error: Unauthorized.'), get_base_url() . 'login' . '/');
        exit();
    }

    if (!hasPermission('access_grading_screen')) {
        _etsis_flash()->error(_t('403 - Error: Forbidden.'), get_base_url() . 'sect/courses' . '/');
        exit();
    }
});

$app->post('/sect/emailStudent/', function () use($app) {

    if (strlen($_FILES['attachment']['name']) > 0) {
        $filepath = APP_PATH . 'tmp/uploads/';
        $name = $_FILES['attachment']['name'];
        $size = $_FILES['attachment']['size'];
        $tmp = $_FILES['attachment']['tmp_name'];

        $mb = $app->hook->apply_filter('attachment_file_size', (20480 * 1024));
        if ($size > $mb) {
            _etsis_flash()->error(_t('Your file size is bigger than 20MB. Try to shrink the size of your file and try again.'), $app->req->server['HTTP_REFERER']);
            exit();
        }

        $target = $filepath . $name;

        move_uploaded_file($tmp, $target);
    }

    try {
        Node::dispense('student_email');
        $node = Node::table('student_email');
        $node->from = (string) get_persondata('email');
        $node->to = (int) $app->req->post['stuID'];
        $node->cc = (string) $app->req->post['cc'];
        $node->bcc = (string) $app->req->post['bcc'];
        $node->subject = (string) $app->req->post['subject'];
        $node->message = (string) $app->req->post['message'];
        $node->attachment = (string) $target;
        $node->sent = (int) 0;
        $node->save();
        _etsis_flash()->success(_t('Email queued for sending.'), $app->req->server['HTTP_REFERER']);
    } catch (NodeQException $e) {
        Cascade::getLogger('error')->error(sprintf('NODEQSTATE[%s]: %s', $e->getCode(), $e->getMessage()));
        _etsis_flash()->error(_etsis_flash()->notice(409), $app->req->server['HTTP_REFERER']);
    } catch (Exception $e) {
        Cascade::getLogger('error')->error(sprintf('NODEQSTATE[%s]: %s', $e->getCode(), $e->getMessage()));
        _etsis_flash()->error(_etsis_flash()->notice(409), $app->req->server['HTTP_REFERER']);
    }
});

/**
 * Before route check.
 */
$app->before('POST', '/sect/stuEmailGroup/', function () {
    if (!is_user_logged_in()) {
        _etsis_flash()->error(_t('401 - Error: Unauthorized.'), get_base_url() . 'login' . '/');
        exit();
    }

    if (!hasPermission('access_grading_screen')) {
        _etsis_flash()->error(_t('403 - Error: Forbidden.'), get_base_url() . 'sect/courses' . '/');
        exit();
    }
});

$app->post('/sect/stuEmailGroup/', function () use($app) {

    try {
        $group = $app->db->stac()
            ->select('stac.*,b.personID,b.email')
            ->_join('person', 'stac.stuID = b.personID', 'b')
            ->_join('course_sec', 'stac.courseSecID = c.courseSecID', 'c')
            ->where('stac.courseSecID = ?', $app->req->post['courseSecID'])->_and_()
            ->where('c.facID = ?', get_persondata('personID'))->_and_()
            ->whereIn('stac.status', ['A', 'N']);
        $q = $group->find(function ($data) {
            $array = [];
            foreach ($data as $d) {
                $array[] = $d;
            }
            return $array;
        });

        if (count(_escape($q[0]['stuID'])) <= 0) {
            _etsis_flash()->error(_t('No students.'), $app->req->server['HTTP_REFERER']);
            exit();
        }
    } catch (NotFoundException $e) {
        Cascade::getLogger('error')->error($e->getMessage());
        _etsis_flash()->error(_etsis_flash()->notice(409), $app->req->server['HTTP_REFERER']);
        exit();
    } catch (ORMException $e) {
        Cascade::getLogger('error')->error($e->getMessage());
        _etsis_flash()->error(_etsis_flash()->notice(409), $app->req->server['HTTP_REFERER']);
        exit();
    } catch (Exception $e) {
        Cascade::getLogger('error')->error($e->getMessage());
        _etsis_flash()->error(_etsis_flash()->notice(409), $app->req->server['HTTP_REFERER']);
        exit();
    }

    if (strlen($_FILES['attachment']['name']) > 0) {
        $filepath = APP_PATH . 'tmp/uploads/';
        $name = $_FILES['attachment']['name'];
        $size = $_FILES['attachment']['size'];
        $tmp = $_FILES['attachment']['tmp_name'];

        $mb = $app->hook->apply_filter('attachment_file_size', (20480 * 1024));
        if ($size > $mb) {
            _etsis_flash()->error(_t('Your file size is bigger than 20MB. Try to shrink the size of your file and try again.'), $app->req->server['HTTP_REFERER']);
            exit();
        }

        $target = $filepath . $name;

        move_uploaded_file($tmp, $target);
    }

    try {
        Node::dispense('student_email');
    } catch (NodeQException $e) {
        Cascade::getLogger('error')->error(sprintf('NODEQSTATE[%s]: %s', $e->getCode(), $e->getMessage()));
        _etsis_flash()->error(_etsis_flash()->notice(409), $app->req->server['HTTP_REFERER']);
        exit();
    } catch (Exception $e) {
        Cascade::getLogger('error')->error(sprintf('NODEQSTATE[%s]: %s', $e->getCode(), $e->getMessage()));
        _etsis_flash()->error(_etsis_flash()->notice(409), $app->req->server['HTTP_REFERER']);
        exit();
    }

    try {
        foreach ($q as $r) {
            $node = Node::table('student_email');
            $node->from = (string) get_persondata('email');
            $node->to = (int) _escape($r['personID']);
            $node->cc = (string) $app->req->post['cc'];
            $node->bcc = (string) $app->req->post['bcc'];
            $node->subject = (string) $app->req->post['subject'];
            $node->message = (string) $app->req->post['message'];
            $node->attachment = (string) $target;
            $node->sent = (int) 0;
            $node->save();
        }
        _etsis_flash()->success(_t('Emails queued for sending.'), $app->req->server['HTTP_REFERER']);
    } catch (NodeQException $e) {
        Cascade::getLogger('error')->error(sprintf('NODEQSTATE[%s]: %s', $e->getCode(), $e->getMessage()));
        _etsis_flash()->error(_etsis_flash()->notice(409), $app->req->server['HTTP_REFERER']);
    } catch (Exception $e) {
        Cascade::getLogger('error')->error(sprintf('NODEQSTATE[%s]: %s', $e->getCode(), $e->getMessage()));
        _etsis_flash()->error(_etsis_flash()->notice(409), $app->req->server['HTTP_REFERER']);
    }
});

$app->get('/sect/courses/getAttendanceReport/(\d+)/', function ($id) use($app) {

    try {
        $q = $app->db->query(
            "SELECT "
            . "CASE attendance.status "
            . "WHEN 'P' THEN 'Present' "
            . "WHEN 'A' THEN 'Absent' "
            . "WHEN 'T' THEN 'Tardy' "
            . "END AS Attend, COUNT(attendance.id) as Status "
            . "FROM attendance "
            . "WHERE attendance.courseSecID = ? "
            . "GROUP BY attendance.status", [$id]
        );
        // Use closure as callback
        $results = $q->find(function($data) {
            $array = [];
            foreach ($data as $d) {
                $array[] = $d;
            }
            return $array;
        });
        // Retrieve data passed from query to closure
        $rows = [];
        foreach ($results as $r) {
            $row[0] = _escape($r['Attend']);
            $row[1] = _escape($r['Status']);
            array_push($rows, $row);
        }
        print json_encode($rows, JSON_NUMERIC_CHECK);
    } catch (NotFoundException $e) {
        _etsis_flash()->error($e->getMessage());
    } catch (ORMException $e) {
        _etsis_flash()->error($e->getMessage());
    } catch (Exception $e) {
        _etsis_flash()->error($e->getMessage());
    }
});

$app->setError(function () use($app) {

    $app->view->display('error/404', [
        'title' => '404 Error'
    ]);
});
