<?php
if (!defined('BASE_PATH'))
    exit('No direct script access allowed');
use app\src\Core\Exception\NotFoundException;
use app\src\Core\Exception\Exception;
use PDOException as ORMException;
use Cascade\Cascade;

/**
 * Before route checks to make sure the logged in user
 * us allowed to manage options/settings.
 */
$app->before('GET|POST', '/crse/extr(.*)', function() {
    if (!is_user_logged_in()) {
        _etsis_flash()->error(_t('401 - Error: Unauthorized.'), get_base_url() . 'login' . '/');
        exit();
    }
    if (!hasPermission('register_student')) {
        _etsis_flash()->error(_t('403 - Error: Forbidden.'), get_base_url() . 'dashboard' . '/');
        exit();
    }
});

$app->match('GET|POST', '/crse/extr/', function () use($app) {

    if ($app->req->isPost() && isset($app->req->post['extr'])) {
        try {
            $post = $app->req->post['extr'];
            $search = $app->db->query("SELECT 
        				a.id,a.courseName,a.courseTitle,a.startDate,
        				a.endDate,CASE a.currStatus 
        				WHEN 'A' THEN 'Active' 
        				WHEN 'I' THEN 'Inactive' 
        				WHEN 'P' THEN 'Pending' 
        				ELSE 'Obsolete' END AS 'Status',
        				b.instName 
    				FROM external_course a 
    				LEFT JOIN institution b ON a.instCode = b.fice_ceeb 
    				WHERE a.courseName LIKE ?", [ "%$post%"]);
            $q = $search->find(function($data) {
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

    if ($app->req->isPost() && isset($app->req->post['instCode'])) {
        try {
            $extr = $app->db->external_course();
            $extr->courseTitle = $app->req->post['courseTitle'];
            $extr->instCode = $app->req->post['instCode'];
            $extr->courseName = $app->req->post['courseName'];
            $extr->term = $app->req->post['term'];
            $extr->credits = $app->req->post['credits'];
            $extr->currStatus = $app->req->post['currStatus'];
            $extr->statusDate = \Jenssegers\Date\Date::now();
            $extr->minGrade = $app->req->post['minGrade'];
            $extr->comments = $app->req->post['comments'];
            $extr->startDate = $app->req->post['startDate'];
            $extr->endDate = ($app->req->post['endDate'] != '' ? $app->req->post['endDate'] : NULL);
            $extr->addDate = \Jenssegers\Date\Date::now();
            $extr->addedBy = get_persondata('personID');
            $extr->save();

            $_id = $extr->lastInsertId();
            etsis_logger_activity_log_write('New Record', 'External Course', $app->req->post['courseName'], get_persondata('uname'));
            _etsis_flash()->success(_etsis_flash()->notice(200), get_base_url() . 'crse/extr' . '/' . (int) $_id . '/');
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
    etsis_register_script('datepicker');

    $app->view->display('course/extr', [
        'title' => 'Add External Course',
        'search' => $q
        ]
    );
});

$app->match('GET|POST', '/crse/extr/(\d+)/', function ($id) use($app) {

    try {
        $ecrse = $app->db->external_course()
            ->where('id = ?', (int) $id)
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

    if ($app->req->isPost()) {
        try {
            $extr = $app->db->external_course();
            $extr->courseTitle = $app->req->post['courseTitle'];
            $extr->instCode = $app->req->post['instCode'];
            $extr->courseName = $app->req->post['courseName'];
            $extr->term = $app->req->post['term'];
            $extr->credits = $app->req->post['credits'];
            $extr->currStatus = $app->req->post['currStatus'];
            if ($ecrse->currStatus != $app->req->post['currStatus']) {
                $extr->statusDate = \Jenssegers\Date\Date::now();
            }
            $extr->minGrade = $app->req->post['minGrade'];
            $extr->comments = $app->req->post['comments'];
            $extr->startDate = $app->req->post['startDate'];
            $extr->endDate = ($app->req->post['endDate'] != '' ? $app->req->post['endDate'] : NULL);
            $extr->where('id = ?', $id);
            $extr->update();

            etsis_logger_activity_log_write('Update Record', 'External Course', $app->req->post['courseName'], get_persondata('uname'));
            _etsis_flash()->success(_etsis_flash()->notice(200), get_base_url() . 'crse/extr' . '/' . (int) $id . '/');
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

    /**
     * If the database table doesn't exist, then it
     * is false and a 404 should be sent.
     */
    if ($ecrse == false) {

        $app->view->display('error/404', ['title' => '404 Error']);
    }
    /**
     * If the query is legit, but there
     * is no data in the table, then 404
     * will be shown.
     */ elseif (empty($ecrse) == true) {

        $app->view->display('error/404', ['title' => '404 Error']);
    }
    /**
     * If data is zero, 404 not found.
     */ elseif (_escape($ecrse->id) <= 0) {

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
        etsis_register_script('datepicker');
        etsis_register_script('datatables');

        $app->view->display('course/vextr', [
            'title' => _escape($ecrse->courseTitle) . ' :: External Course',
            'extr' => $ecrse
            ]
        );
    }
});

/**
 * Before route checks to make sure the logged in user
 * us allowed to manage options/settings.
 */
$app->before('GET|POST', '/crse/tceq(.*)', function() {
    if (!is_user_logged_in()) {
        _etsis_flash()->error(_t('401 - Error: Unauthorized.'), get_base_url() . 'login' . '/');
        exit();
    }
    if (!hasPermission('register_student')) {
        _etsis_flash()->error(_t('403 - Error: Forbidden.'), get_base_url() . 'dashboard' . '/');
        exit();
    }
});

$app->match('GET|POST', '/crse/tceq/', function () use($app) {

    if ($app->req->isPost()) {
        try {
            $post = $app->req->post['tceq'];
            $search = $app->db->query("SELECT 
                        a.id,a.startDate,a.endDate,a.grade,
                        b.courseCode AS internal,c.courseName AS external 
                    FROM transfer_equivalent a 
                    LEFT JOIN course b ON a.courseID = b.courseID 
                    LEFT JOIN external_course c ON a.extrID = c.id 
                    WHERE c.courseName LIKE ?", [ "%$post%"]);
            $q = $search->find(function($data) {
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

    etsis_register_style('table');
    etsis_register_script('select');
    etsis_register_script('select2');
    etsis_register_script('datatables');
    etsis_register_script('datepicker');

    $app->view->display('course/tceq', [
        'title' => 'Search Transfer Course Equivalency',
        'tceq' => $q
        ]
    );
});

$app->match('GET|POST', '/crse/tceq/(\d+)/', function ($id) use($app) {
    if ($app->req->isPost()) {
        try {
            $tceq = $app->db->transfer_equivalent();
            foreach (_filter_input_array(INPUT_POST) as $k => $v) {
                $tceq->$k = $v;
            }
            $tceq->where('id = ?', $id);
            $tceq->update();

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
        $tran = $app->db->transfer_equivalent()
            ->select('transfer_equivalent.id,transfer_equivalent.startDate')
            ->select('transfer_equivalent.endDate,transfer_equivalent.grade,transfer_equivalent.comment,transfer_equivalent.addedBy')
            ->select('transfer_equivalent.addDate,transfer_equivalent.LastUpdate,b.courseCode')
            ->select('b.courseShortTitle,b.minCredit,b.courseLevelCode')
            ->select('c.courseName,c.courseTitle,c.credits,c.minGrade')
            ->_join('course', 'transfer_equivalent.courseID = b.courseID', 'b')
            ->_join('external_course', 'transfer_equivalent.extrID = c.id', 'c')
            ->where('transfer_equivalent.id = ?', $id);

        $q = $tran->find(function($data) {
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
     */ elseif (_escape($q[0]['id']) <= 0) {

        $app->view->display('error/404', ['title' => '404 Error']);
    }
    /**
     * If we get to this point, the all is well
     * and it is ok to process the query and print
     * the results in a html format.
     */ else {

        etsis_register_style('table');
        etsis_register_style('form');
        etsis_register_script('select');
        etsis_register_script('select2');
        etsis_register_script('datatables');
        etsis_register_script('datepicker');

        $app->view->display('course/vtceq', [
            'title' => 'Transfer Course Equivalency',
            'tceq' => $q
            ]
        );
    }
});

$app->match('GET|POST', '/crse/tceq/add/', function () use($app) {

    if ($app->req->isPost()) {
        try {
            $tran = $app->db->transfer_equivalent();
            $tran->extrID = $app->req->post['id'];
            $tran->courseID = $app->req->post['courseID'];
            $tran->startDate = $app->req->post['startDate'];
            $tran->endDate = ($app->req->post['endDate'] != '' ? $app->req->post['endDate'] : NULL);
            $tran->grade = $app->req->post['grade'];
            $tran->comment = $app->req->post['comment'];
            $tran->addedBy = get_persondata('personID');
            $tran->addDate = \Jenssegers\Date\Date::now();
            $tran->save();

            $_id = $tran->lastInsertId();
            etsis_logger_activity_log_write('New Record', 'Transfer Course Equivalency', 'Equivalency ID: ' . $_id, get_persondata('uname'));
            _etsis_flash()->success(_etsis_flash()->notice(200), get_base_url() . 'crse/tceq' . '/' . (int) $_id . '/');
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

    etsis_register_style('table');
    etsis_register_style('form');
    etsis_register_script('select');
    etsis_register_script('select2');
    etsis_register_script('datatables');
    etsis_register_script('datepicker');

    $app->view->display('course/atceq', [
        'title' => 'Add Transfer Course Equivalency'
        ]
    );
});

/**
 * Before route check.
 */
$app->before('GET|POST', '/crse/tcre(.*)', function() {
    if (!is_user_logged_in()) {
        _etsis_flash()->error(_t('401 - Error: Unauthorized.'), get_base_url() . 'login' . '/');
        exit();
    }
    if (!hasPermission('register_student')) {
        _etsis_flash()->error(_t('403 - Error: Forbidden.'), get_base_url() . 'dashboard' . '/');
        exit();
    }
});

$app->match('GET|POST', '/crse/tcre/', function () use($app) {
    if ($app->req->isPost()) {
        try {
            $subj = explode(" ", $app->req->post['subjectCode']);
            $dept = explode(" ", $app->req->post['deptCode']);
            $cType = explode(" ", $app->req->post['creditType']);

            $tran = $app->db->stac();
            $tran->stuID = $app->req->post['stuID'];
            $tran->courseID = $app->req->post['courseID'];
            $tran->courseCode = $app->req->post['courseCode'];
            $tran->termCode = $app->req->post['termCode'];
            $tran->reportingTerm = $app->req->post['reportingTerm'];
            $tran->subjectCode = $subj[0];
            $tran->deptCode = $dept[0];
            $tran->shortTitle = $app->req->post['shortTitle'];
            $tran->longTitle = $app->req->post['longTitle'];
            $tran->compCred = $app->req->post['compCred'];
            $tran->gradePoints = $app->req->post['gradePoints'];
            $tran->attCred = $app->req->post['attCred'];
            $tran->status = 'A';
            $tran->statusDate = $app->req->post['statusDate'];
            $tran->statusTime = $app->req->post['statusTime'];
            $tran->acadLevelCode = $app->req->post['acadLevelCode'];
            $tran->courseLevelCode = $app->req->post['courseLevelCode'];
            $tran->grade = $app->req->post['grade'];
            $tran->creditType = $cType[0];
            $tran->addedBy = get_persondata('personID');
            $tran->addDate = \Jenssegers\Date\Date::now();
            if ($tran->save()) {
                $_id = $tran->lastInsertId();
                $cre = $app->db->transfer_credit();
                $cre->equivID = $app->req->post['equivID'];
                $cre->stacID = $_id;
                $cre->addedBy = get_persondata('personID');
                $cre->addDate = \Jenssegers\Date\Date::now();
                if ($cre->save()) {
                    etsis_logger_activity_log_write('New Record', 'Transfer Credit', $app->req->post['courseCode'], get_persondata('uname'));
                    _etsis_flash()->success(_etsis_flash()->notice(200), get_base_url() . 'stu/sacd' . '/' . $_id . '/');
                } else {
                    etsis_redirect(get_base_url() . 'crse/tcre' . '/');
                    _etsis_flash()->success(_etsis_flash()->notice(200), get_base_url() . 'crse/tcre' . '/');
                }
            } else {
                _etsis_flash()->success(_etsis_flash()->notice(200), get_base_url() . 'crse/tcre' . '/');
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
        $tcre = $app->db->transfer_equivalent()
            ->setTableAlias('a')
            ->select('a.id,b.instCode,b.courseName')
            ->_join('external_course', 'a.extrID = b.id', 'b')
            ->where('b.currStatus = "A"');
        $q = $tcre->find(function($data) {
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
    etsis_register_style('jquery-ui');
    etsis_register_script('select');
    etsis_register_script('select2');
    etsis_register_script('jquery-ui');
    etsis_register_script('datatables');

    $app->view->display('course/tcre', [
        'title' => 'Transfer Credit',
        'tcre' => $q
        ]
    );
});

$app->post('/crse/extrLookup/', function() use($app) {

    try {
        $extr = $app->db->external_course()
            ->select('courseTitle,credits,minGrade')
            ->where('id = ?', $app->req->post['id']);
        $q = $extr->find(function($data) {
            $array = [];
            foreach ($data as $d) {
                $array[] = $d;
            }
            return $array;
        });
        foreach ($q as $v) {
            $json = [
                'input#title' => _escape($v['courseTitle']), 'input#credits' => _escape($v['credits']),
                'input#grade' => _escape($v['minGrade'])
            ];
        }
        echo json_encode($json);
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

$app->post('/crse/equivLookup/', function() use($app) {
    try {
        $equiv = $app->db->transfer_equivalent()
            ->setTableAlias('a')
            ->select('a.id,a.extrID,a.courseID,a.grade')
            ->select('b.courseTitle,b.instCode,b.courseName,b.minGrade')
            ->select('b.credits,c.courseCode,c.subjectCode,c.deptCode')
            ->select('c.courseShortTitle,c.courseLongTitle,c.minCredit,c.acadLevelCode')
            ->select('c.courseLevelCode,d.subjectName,e.deptName,f.points')
            ->_join('external_course', 'a.extrID = b.id', 'b')
            ->_join('course', 'a.courseID = c.courseID', 'c')
            ->_join('subject', 'c.subjectCode = d.subjectCode', 'd')
            ->_join('department', 'c.deptCode = e.deptCode', 'e')
            ->_join('grade_scale', 'a.grade = f.grade', 'f')
            ->where('a.id = ?', $app->req->post['equivID'])->_and_()
            ->where('b.currStatus = "A"');
        $q = $equiv->find(function($data) {
            $array = [];
            foreach ($data as $d) {
                $array[] = $d;
            }
            return $array;
        });

        foreach ($q as $v) {
            $json = [
                'input#title' => _escape($v['courseTitle']), 'input#credits' => _escape($v['credits']),
                'input#minGrade' => _escape($v['minGrade']), 'input#name' => _escape($v['courseCode']),
                'input#shortTitle' => _escape($v['courseShortTitle']), 'input#longTitle' => _escape($v['courseLongTitle']),
                'input#subject' => _escape($v['subjectCode']) . ' ' . _escape($v['subjectName']),
                'input#dept' => _escape($v['deptCode']) . ' ' . _escape($v['deptName']), 'input#attCred' => number_format(_escape($v['minCredit']), 6),
                'input#grade' => _escape($v['grade']), 'input#acadLevel' => _escape($v['acadLevelCode']),
                'input#courseLevel' => _escape($v['courseLevelCode']), 'input#creditType' => "TR Transfer",
                'input#cmplCred' => "0.000000", 'input#courseID' => _escape($v['courseID']),
                'input#gradePoints' => number_format(_escape($v['points']) * _escape($v['minCredit']), 6)
            ];
        }
        echo json_encode($json);
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

$app->setError(function() use($app) {

    $app->view->display('error/404', ['title' => '404 Error']);
});
