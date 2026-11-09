<?php
if (!defined('BASE_PATH'))
    exit('No direct script access allowed');
use app\src\Core\Exception\NotFoundException;
use app\src\Core\Exception\Exception;
use PDOException as ORMException;
use Cascade\Cascade;

/**
 * Before router check.
 */
$app->before('GET|POST', '/calendar(.*)', function () {
    if (!is_user_logged_in()) {
        _etsis_flash()->error(_t('401 - Error: Unauthorized.'), get_base_url() . 'login' . '/');
        exit();
    }
});

$app->get('/calendar', function () use($app) {

    etsis_register_style('calendar');
    etsis_register_script('calendar');

    $app->view->display('calendar/index', [
        'title' => 'Events Calendar'
    ]);
});

/**
 * Before route check.
 */
$app->before('GET|POST', '/calendar/booking-form/', function () {
    if (!hasPermission('room_request')) {
        _etsis_flash()->error(_t('403 - Error: Forbidden.'), get_base_url() . 'dashboard' . '/');
    }
});

$app->match('GET|POST', '/calendar/booking-form/', function () use($app) {

    etsis_register_style('form');
    etsis_register_script('select');
    etsis_register_script('select2');
    etsis_register_script('datepicker');
    etsis_register_script('timepicker');

    $app->view->display('calendar/booking-form', [
        'title' => 'Room/Events Booking Form'
    ]);
});

/**
 * Before route check.
 */
$app->before('GET|POST', '/calendar/events/', function () {
    if (!hasPermission('room_request')) {
        _etsis_flash()->error(_t('403 - Error: Forbidden.'), get_base_url() . 'dashboard' . '/');
    }
});

$app->get('/calendar/events/', function () use($app) {
    try {
        $events = $app->db->event()
            ->setTableAlias('a')
            ->select('a.eventID,a.eventType,a.requestor,a.roomCode,a.title')
            ->select('a.startDate,a.startTime,b.cat_name')
            ->_join('event_category', 'a.catID = b.catID', 'b')
            ->where('a.eventType <> "Course"')
            ->orderBy('a.startDate', 'ASC');
        $q = etsis_cache_get('event', 'event');
        if (empty($q)) {
            $q = $events->find(function ($data) {
                $array = [];
                foreach ($data as $d) {
                    $array[] = $d;
                }
                return $array;
            });
            etsis_cache_add('event', $q, 'event');
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

    etsis_register_style('table');
    etsis_register_script('select');
    etsis_register_script('select2');
    etsis_register_script('datatables');

    $app->view->display('calendar/events', [
        'title' => 'Manage Events',
        'event' => $q
    ]);
});

/**
 * Before route check.
 */
$app->before('GET|POST', '/calendar/eventID/(\d+)/', function () {
    if (!hasPermission('room_request')) {
        _etsis_flash()->error(_t('403 - Error: Forbidden.'), get_base_url() . 'dashboard' . '/');
    }
});

$app->match('GET|POST', '/calendar/eventID/(\d+)/', function ($id) use($app) {
    try {
        $series = $app->db->event()
            ->where('eventID = ?', $id)
            ->orderBy('startDate');
        $q1 = etsis_cache_get($id, 'event');
        if (empty($q1)) {
            $q1 = $series->find(function ($data) {
                $array = [];
                foreach ($data as $d) {
                    $array[] = $d;
                }
                return $array;
            });
            etsis_cache_add($id, $q1, 'event');
        }
        $meta = $app->db->event_meta()
            ->setTableAlias('a')
            ->select('a.eventMetaID,a.eventID,a.roomCode,a.requestor,a.start,a.end,a.title')
            ->select('a.description,b.weekday')
            ->_join('event', 'a.eventID = b.eventID', 'b')
            ->where('a.eventID = ?', $id)
            ->orderBy('a.start');
        $q2 = etsis_cache_get($id, 'event_meta');
        if (empty($q2)) {
            $q2 = $meta->find(function ($data) {
                $array = [];
                foreach ($data as $d) {
                    $array[] = $d;
                }
                return $array;
            });
            etsis_cache_add($id, $q2, 'event_meta');
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

        etsis_register_style('form');
        etsis_register_script('select');
        etsis_register_script('select2');
        etsis_register_script('datepicker');
        etsis_register_script('timepicker');

        $app->view->display('calendar/view-event', [
            'title' => _h($q1[0]['eventName']),
            'series' => $q1,
            'meta' => $q2
        ]);
    }
});

/**
 * Before route check.
 */
$app->before('GET|POST', '/calendar/setting/', function () {
    if (!hasPermission('edit_settings')) {
        _etsis_flash()->error(_t('403 - Error: Forbidden.'), get_base_url() . 'dashboard' . '/');
        exit();
    }
});

$app->match('GET|POST', '/calendar/setting/', function () use($app) {

    if ($app->req->isPost()) {
        try {
            $sql = $app->db->event_category();
            $sql->cat_name = $app->req->post['cat_name'];
            $sql->bgcolor = $app->req->post['bgcolor'];
            $sql->where('catID = ?', $app->req->post['catID']);
            $sql->update();
            etsis_logger_activity_log_write('Update Record', 'Events Calendar', 'Calendar Settings', get_persondata('uname'));
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
        $cat = $app->db->event_category();
        $q = $cat->find(function ($data) {
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

    $app->view->display('calendar/setting', [
        'title' => 'Events Calendar Setting',
        'category' => $q
    ]);
});

/**
 * Before route check.
 */
$app->before('GET|POST', '/calendar/requests/', function () {
    if (!hasPermission('room_request')) {
        _etsis_flash()->error(_t('403 - Error: Forbidden.'), get_base_url() . 'dashboard' . '/');
    }
});

$app->get('/calendar/requests/', function () use($app) {
    try {
        $request = $app->db->event_request()
            ->setTableAlias('a')
            ->select('a.requestID,a.eventType,a.catID,a.requestor,a.roomCode,a.termCode,a.title')
            ->select('a.description,a.weekday,a.startDate,a.endDate,a.startTime')
            ->select('a.endTime,a.repeats,a.repeatFreq,b.cat_name')
            ->_join('event_category', 'a.catID = b.catID', 'b')
            ->orderBy('a.addDate');
        $q = etsis_cache_get('request', 'request');
        if (empty($q)) {
            $q = $request->find(function ($data) {
                $array = [];
                foreach ($data as $d) {
                    $array[] = $d;
                }
                return $array;
            });
            etsis_cache_add('request', $q, 'request');
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

    etsis_register_style('table');
    etsis_register_script('select');
    etsis_register_script('select2');
    etsis_register_script('datatables');

    $app->view->display('calendar/requests', [
        'title' => 'Events Calendar',
        'request' => $q
    ]);
});

/**
 * Before route check.
 */
$app->before('GET|POST', '/calendar/requestID/(\d+)/', function () {
    if (!hasPermission('room_request')) {
        _etsis_flash()->error(_t('403 - Error: Forbidden.'), get_base_url() . 'dashboard' . '/');
    }
});

$app->match('GET|POST', '/calendar/requestID/(\d+)/', function ($id) use($app) {
    try {
        $request = $app->db->event_request()
            ->setTableAlias('a')
            ->select('a.requestID,a.eventType,a.catID,a.requestor,a.roomCode,a.termCode,a.title')
            ->select('a.description,a.weekday,a.startDate,a.endDate,a.startTime')
            ->select('a.endTime,a.repeats,a.repeatFreq,b.cat_name')
            ->_join('event_category', 'a.catID = b.catID', 'b')
            ->where('a.requestID = ?', $id);
        $q = etsis_cache_get($id, 'request');
        if (empty($q)) {
            $q = $request->find(function ($data) {
                $array = [];
                foreach ($data as $d) {
                    $array[] = $d;
                }
                return $array;
            });
            etsis_cache_add($id, $q, 'request');
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
     */ elseif (count($q) <= 0) {

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
        etsis_register_script('datepicker');
        etsis_register_script('timepicker');

        $app->view->display('calendar/view-request', [
            'title' => 'Room/Booking Request',
            'req' => $q
        ]);
    }
});

$app->get('/calendar/getEvents/', function () use($app) {
    try {
        $meta = $app->db->event_meta()
            ->setTableAlias('a')
            ->select('a.eventMetaID,a.eventID,a.roomCode,a.requestor,a.start,a.end,a.title')
            ->select('a.description,b.roomCode,c.buildingCode,e.bgcolor')
            ->_join('room', 'a.roomCode = b.roomCode', 'b')
            ->_join('building', 'b.buildingCode = c.buildingCode', 'c')
            ->_join('event', 'a.eventID = d.eventID', 'd')
            ->_join('event_category', 'd.catID = e.catID', 'e');
        $q = etsis_cache_get('event_meta', 'event_meta');
        if (empty($q)) {
            $q = $meta->find(function ($data) {
                $array = [];
                foreach ($data as $d) {
                    $array[] = $d;
                }
                return $array;
            });
            etsis_cache_add('event_meta', $q, 'event_meta');
        }
        $events = [];
        foreach ($q as $r) {
            $eventArray['eMID'] = _h($r['eventMetaID']);
            $eventArray['eID'] = _h($r['eventID']);
            $eventArray['buildingCode'] = _h($r['buildingCode']);
            $eventArray['roomCode'] = _h($r['roomCode']);
            $eventArray['title'] = _h($r['title']);
            $eventArray['description'] = _h($r['description']);
            $eventArray['start'] = _h($r['start']);
            $eventArray['end'] = _h($r['end']);
            $eventArray['color'] = _h($r['bgcolor']);
            $events[] = $eventArray;
        }
        echo json_encode($events);
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
});

/**
 * Before route check.
 */
$app->before('POST', '/calendar/editMeta/', function () {
    if (!hasPermission('room_request')) {
        _etsis_flash()->error(_t('403 - Error: Forbidden.'), get_base_url() . 'dashboard' . '/');
        exit();
    }
});

$app->post('/calendar/editMeta/', function () use($app) {
    $sTime = \Jenssegers\Date\Date::parse($app->req->post['startTime'])->format('H:i:s');
    $start = $app->req->post['startDate'] . ' ' . $sTime;
    $eTime = \Jenssegers\Date\Date::parse($app->req->post['endTime'])->format('H:i:s');
    $end = $app->req->post['endDate'] . ' ' . $eTime;

    try {
        $q = $app->db->event_meta();
        $q->roomCode = $app->req->post['roomCode'];
        $q->start = $start;
        $q->end = $end;
        $q->title = $app->req->post['title'];
        $q->description = $app->req->post['description'];
        $q->where('eventMetaID = ?', $app->req->post['eventMetaID']);
        $q->update();
        etsis_cache_flush_namespace('event_meta');
        etsis_cache_flush_namespace('sbook');
        etsis_logger_activity_log_write('Update Record', 'Events Calendar Meta', $app->req->post['title'] . ' ' . $start . ' - ' . $end, get_persondata('uname'));
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
 * Before route check.
 */
$app->before('GET|POST', '/sect/sbook/(\d+)/', function () {
    if (!hasPermission('access_course_sec_screen')) {
        _etsis_flash()->error(_t('403 - Error: Forbidden.'), get_base_url() . 'dashboard' . '/');
    }
});

$app->match('GET|POST', '/sect/sbook/(\d+)/', function ($id) use($app) {
    $sect = get_course_sec($id);

    try {
        $sbook = $app->db->course_sec()
            ->setTableAlias('a')
            ->select('a.courseSecID,a.courseSecCode,a.termCode,a.roomCode,a.courseSection')
            ->select('a.dotw,a.startTime,a.endTime,b.termStartDate,b.termEndDate')
            ->_join('term', 'a.termCode = b.termCode', 'b')
            ->where('a.courseSecID = ?', $id);
        $q1 = etsis_cache_get('sbook' . $id, 'sbook');
        if (empty($q1)) {
            $q1 = $sbook->find(function ($data) {
                $array = [];
                foreach ($data as $d) {
                    $array[] = $d;
                }
                return $array;
            });
            etsis_cache_add('sbook' . $id, $q1, 'sbook');
        }
        $booking = $app->db->event_meta()
            ->setTableAlias('a')
            ->select('a.eventMetaID,a.eventID,a.roomCode,a.requestor,a.start,a.end,a.title')
            ->select('a.description,b.weekDay')
            ->_join('event', 'a.eventID = b.eventID', 'b')
            ->_join('course_sec', 'b.title = c.courseSecCode', 'c')
            ->where('b.termCode = c.termCode')->_and_()
            ->where('c.courseSecID = ?', $id)
            ->orderBy('a.start');
        $q2 = etsis_cache_get('booking' . $id, 'sbook');
        if (empty($q2)) {
            $q2 = $booking->find(function ($data) {
                $array = [];
                foreach ($data as $d) {
                    $array[] = $d;
                }
                return $array;
            });
            etsis_cache_add('booking' . $id, $q2, 'sbook');
        }
        $series = $app->db->event()
            ->setTableAlias('a')
            ->select('a.*,b.courseSecID')
            ->_join('course_sec', 'a.title = b.courseSecCode', 'b')
            ->where('a.termCode = b.termCode')->_and_()
            ->where('b.courseSecID = ?', $id)
            ->orderBy('a.startDate');
        $q3 = etsis_cache_get('series' . $id, 'sbook');
        if (empty($q3)) {
            $q3 = $series->find(function ($data) {
                $array = [];
                foreach ($data as $d) {
                    $array[] = $d;
                }
                return $array;
            });
            etsis_cache_add('series' . $id, $q3, 'sbook');
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
     */ elseif (_h($sect->courseSecID) <= 0) {

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
        etsis_register_script('timepicker');

        $app->view->display('section/booking-info', [
            'title' => _h($sect->secShortTitle) . ' :: Classroom Booking',
            'sbook' => $q1,
            'booking' => $q2,
            'series' => $q3,
            'sect' => $sect
        ]);
    }
});

/**
 * Before route check.
 */
$app->before('GET|POST', '/sect/timetable/', function () {
    if (!hasPermission('access_dashboard')) {
        _etsis_flash()->error(_t('403 - Error: Forbidden.'), get_base_url() . 'dashboard' . '/');
    }
});

$app->match('GET|POST', '/sect/timetable/', function () use($app) {

    etsis_register_style('calendar');
    etsis_register_script('calendar');

    $app->view->display('section/timetable', [
        'title' => 'Timetable'
    ]);
});

/**
 * Before route check.
 */
$app->before('GET|POST', '/form/request-form/', function () {
    if (!is_user_logged_in()) {
        _etsis_flash()->error(_t('401 - Error: Unauthorized.'), get_base_url() . 'login' . '/');
    }

    if (_h(get_option('enable_myetsis_portal')) == 0 && !hasPermission('edit_myetsis_css')) {
        etsis_redirect(get_base_url() . 'offline' . '/');
    }
});

$app->match('GET|POST', '/form/request-form/', function () use($app) {

    $css = [
        'css/admin/module.admin.page.alt.form_elements.min.css',
        'css/admin/module.admin.page.alt.tables.min.css'
    ];
    $js = [
        'components/modules/admin/forms/elements/bootstrap-select/assets/lib/js/bootstrap-select.js?v=v2.1.0',
        'components/modules/admin/forms/elements/bootstrap-select/assets/custom/js/bootstrap-select.init.js?v=v2.1.0',
        'components/modules/admin/forms/elements/select2/assets/lib/js/select2.js?v=v2.1.0',
        'components/modules/admin/forms/elements/select2/assets/custom/js/select2.init.js?v=v2.1.0',
        'components/modules/admin/forms/elements/bootstrap-datepicker/assets/lib/js/bootstrap-datepicker.js?v=v2.1.0',
        'components/modules/admin/forms/elements/bootstrap-datepicker/assets/custom/js/bootstrap-datepicker.init.js?v=v2.1.0',
        'components/modules/admin/forms/elements/bootstrap-timepicker/assets/lib/js/bootstrap-timepicker.js?v=v2.1.0',
        'components/modules/admin/forms/elements/bootstrap-timepicker/assets/custom/js/bootstrap-timepicker.init.js?v=v2.1.0'
    ];

    $app->view->display('form/request-form', [
        'title' => 'Room/Event Request Form',
        'cssArray' => $css,
        'jsArray' => $js
    ]);
});

$app->get('/sect/getEvents/', function () use($app) {
    try {
        $event = $app->db->query("SELECT 
                        a.eventMetaID,a.eventID,a.roomCode,a.requestor,a.start,a.end,a.title,
                        a.description,b.roomCode,c.buildingCode,e.bgcolor 
                    FROM event_meta a 
                    LEFT JOIN room b ON a.roomCode = b.roomCode 
                    LEFT JOIN building c ON b.buildingCode = c.buildingCode 
                    LEFT JOIN event d ON a.eventID = d.eventID 
            		LEFT JOIN event_category e ON d.catID = e.catID 
    				LEFT JOIN course_sec f ON d.termCode = f.termCode AND d.title = f.courseSecCode 
					WHERE f.facID = ?", [
            get_persondata('personID')
        ]);
        $q = etsis_cache_get('sbook', 'sbook');
        if (empty($q)) {
            $q = $event->find(function ($data) {
                $array = [];
                foreach ($data as $d) {
                    $array[] = $d;
                }
                return $array;
            });
            etsis_cache_add('sbook', $q, 'sbook');
        }
        $events = [];
        foreach ($q as $r) {
            $eventArray['eMID'] = _h($r['eventMetaID']);
            $eventArray['eID'] = _h($r['eventID']);
            $eventArray['buildingCode'] = _h($r['buildingCode']);
            $eventArray['roomCode'] = _h($r['roomCode']);
            $eventArray['title'] = _h($r['title']);
            $eventArray['description'] = _h($r['description']);
            $eventArray['start'] = _h($r['start']);
            $eventArray['end'] = _h($r['end']);
            $eventArray['color'] = _h($r['bgcolor']);
            $events[] = $eventArray;
        }
        echo json_encode($events);
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
});

/**
 * Before route check.
 */
$app->before('GET|POST', '/calendar/deleteBookingDay/(\d+)/', function () {
    if (!hasPermission('room_request')) {
        _etsis_flash()->error(_t('Permission denied to delete record.'), get_base_url() . 'dashboard' . '/');
        exit();
    }
});

$app->get('/calendar/deleteBookingDay/(\d+)/', function ($id) use($app) {
    try {
        $q = $app->db->event_meta()
            ->where('eventMetaID', $id);
        $q->delete();

        etsis_cache_flush_namespace('sbook');
        etsis_cache_flush_namespace('event_meta');
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
 * Before route check.
 */
$app->before('GET|POST', '/calendar/deleteBookingSeries/(\d+)/', function () {
    if (!hasPermission('room_request')) {
        _etsis_flash()->error(_t('Permission denied to delete record.'), get_base_url() . 'dashboard' . '/');
        exit();
    }
});

$app->get('/calendar/deleteBookingSeries/(\d+)/', function ($id) use($app) {
    try {
        $q = $app->db->event()
            ->where('eventID', $id);
        $q->delete();

        etsis_cache_flush_namespace('event');
        etsis_cache_flush_namespace('event_meta');
        _etsis_flash()->success(_etsis_flash()->notice(200), get_base_url() . 'calendar/events' . '/');
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
 * Before route check.
 */
$app->before('GET|POST', '/calendar/sect/deleteBookingSeries/(\d+)/', function () {
    if (!hasPermission('room_request')) {
        _etsis_flash()->error(_t('Permission denied to delete record.'), get_base_url() . 'dashboard' . '/');
        exit();
    }
});

$app->get('/calendar/sect/deleteBookingSeries/(\d+)/', function ($id) use($app) {
    try {
        $q = $app->db->event()
            ->where('eventID', $id);
        $q->delete();

        etsis_cache_flush_namespace('sbook');
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
 * Before route check.
 */
$app->before('GET|POST', '/calendar/deleteBookingRequest/(\d+)/', function () {
    if (!hasPermission('room_request')) {
        _etsis_flash()->error(_t('Permission denied to delete record.'), get_base_url() . 'dashboard' . '/');
        exit();
    }
});

$app->get('/calendar/deleteBookingRequest/(\d+)/', function ($id) use($app) {
    try {
        $q = $app->db->event_request()
            ->where('requestID', $id);
        $q->delete();

        etsis_cache_flush_namespace('request');
        _etsis_flash()->success(_etsis_flash()->notice(200), get_base_url() . 'calendar/requests' . '/');
    } catch (NotFoundException $e) {
        Cascade::getLogger('error')->error($e->getMessage());
        _etsis_flash()->error(_etsis_flash()->notice(409), get_base_url() . 'calendar/requests' . '/');
    } catch (ORMException $e) {
        Cascade::getLogger('error')->error($e->getMessage());
        _etsis_flash()->error(_etsis_flash()->notice(409), get_base_url() . 'calendar/requests' . '/');
    } catch (Exception $e) {
        Cascade::getLogger('error')->error($e->getMessage());
        _etsis_flash()->error(_etsis_flash()->notice(409), get_base_url() . 'calendar/requests' . '/');
    }
});

$app->setError(function () use($app) {

    $app->view->display('error/404', [
        'title' => '404 Error'
    ]);
});
