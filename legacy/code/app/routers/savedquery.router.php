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
 * Saved Query Router
 *  
 * @license GPLv3
 * 
 * @since       5.0.0
 * @package     eduTrac SIS
 * @author      Joshua Parker <joshmac3@icloud.com>
 */
$app->before('GET|POST|DELETE', '/sql/saved-queries(.*)', function() use ($app) {
    if(!is_user_logged_in()) {
        _etsis_flash()->error(_t('401 - Error: Unauthorized.'), get_base_url() . 'login' . '/');
        exit();
    }
    if (!hasPermission('access_save_query_screens')) {
        _etsis_flash()->error(_t('403 - Error: Forbidden.'), get_base_url() . 'dashboard' . '/');
        exit();
    }

    if (strstra(strtolower($app->req->post['savedQuery']), forbidden_keyword())) {
        _etsis_flash()->error(_t('Your query contained a forbidden keyword(s). Please try again.'), $app->req->server['HTTP_REFERER']);
        exit();
    }
});

$app->get('/sql/saved-queries/', function() use($app) {
    try {
        $query = $app->db->query("SELECT "
            . "id, savedQueryName, createdDate, "
            . "IF((shared IS NULL or shared = ''), 'No','Yes') AS ShareStatus "
            . "FROM saved_query "
            . "WHERE personID = ?", [get_persondata('personID')]
        );
        $q = $query->find(function($data) {
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

    $app->view->display('sql/saved-queries', [
        'title' => 'Saved Queries',
        'query' => $q
        ]
    );
});

$app->match('GET|POST', '/sql/saved-queries/(\d+)/', function($id) use($app) {
    if ($app->req->isPost()) {
        try {
            $sq = $app->db->saved_query();
            $sq->set([
                    'personID' => get_persondata('personID'),
                    'savedQueryName' => $app->req->post['savedQueryName'],
                    'savedQuery' => $app->req->post['savedQuery'],
                    'shared' => $app->req->post['shared'],
                    'purgeQuery' => $app->req->post['purgeQuery']
                ])
                ->where('id = ?', $id)
                ->update();

            etsis_logger_activity_log_write('Update Record', 'Saved Query', $app->req->post['savedQueryName'], get_persondata('uname'));
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
        $query = $app->db->saved_query()
            ->where('id = ?', $id)->_and_()
            ->where('personID = ?', get_persondata('personID'));
        $q = $query->find(function($data) {
            $array = [];
            foreach ($data as $d) {
                $array[] = $d;
            }
            return $array;
        });

        $staff = $app->db->query(" SELECT "
            . "CONCAT(nae.fname,' ',nae.lname) AS name "
            . "FROM person nae "
            . "LEFT JOIN staff ON nae.personID = staff.staffID "
            . "WHERE staff.status = 'A' "
            . "AND staff.staffID <> ?", [get_persondata('personID')]
        );
        $s = $staff->find(function($data) {
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
     */ elseif (_escape($q[0]['personID']) <= 0) {

        $app->view->display('error/404', ['title' => '404 Error']);
    }
    /**
     * If we get to this point, the all is well
     * and it is ok to process the query and print
     * the results in a html format.
     */ else {

        etsis_register_style('form');
        etsis_register_script('select');
        etsis_register_script('select2');

        $app->view->display('sql/view-query', [
            'title' => 'View Saved Query',
            'query' => $q,
            'staff' => $s
            ]
        );
    }
});

$app->match('GET|POST', '/sql/saved-queries/add/', function() use($app) {

    if ($app->req->isPost()) {
        try {
            $sq = $app->db->saved_query();
            $sq->insert([
                'personID' => get_persondata('personID'),
                'savedQueryName' => $app->req->post['savedQueryName'],
                'savedQuery' => $app->req->post['savedQuery'],
                'shared' => $app->req->post['shared'],
                'purgeQuery' => $app->req->post['purgeQuery'],
                'createdDate' => \Jenssegers\Date\Date::now()
            ]);

            $_id = $sq->lastInsertId();
            etsis_logger_activity_log_write('New Record', 'Saved Query', $app->req->post['savedQueryName'], get_persondata('uname'));
            _etsis_flash()->success(_etsis_flash()->notice(200), get_base_url() . 'sql/saved-queries' . '/' . $_id . '/');
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
        $staff = $app->db->query(" SELECT "
            . "CONCAT(nae.fname,' ',nae.lname) AS name "
            . "FROM person nae "
            . "LEFT JOIN staff ON nae.personID = staff.staffID "
            . "WHERE staff.status = 'A' "
            . "AND staff.staffID <> ?", [get_persondata('personID')]
        );
        $q = $staff->find(function($data) {
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
    etsis_register_script('select');
    etsis_register_script('select2');

    $app->view->display('sql/add-query', [
        'title' => 'Add Saved Query',
        'staff' => $q
        ]
    );
});

$app->get('/sql/saved-queries/delete/(\d+)/', function($id) use($app) {

    try {
        $app->db->saved_query()
            ->where('id = ?', $id)->_and_()
            ->where('personID = ?', get_persondata('personID'))
            ->delete();

        _etsis_flash()->success(_etsis_flash()->notice(200), get_base_url().'sql/saved-queries/');
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
    etsis_redirect(get_base_url().'sql/saved-queries/');
});

/**
 * Before route check.
 */
$app->before('GET|POST', '/sql/saved-queries/csv-email/', function() {
    if (!hasPermission('execute_saved_query')) {
        _etsis_flash()->error(_t('403 - Error: Forbidden.'), get_base_url() . 'dashboard' . '/');
        exit();
    }
});

$app->match('GET|POST', '/sql/saved-queries/csv-email/', function() use($app) {
    if ($app->req->isPost()) {
        $filename = strtoupper(\app\src\ID::string(4)) . ".csv";
        $csv = new \app\src\CSVEmail(DB_HOST, DB_NAME, DB_USER, DB_PASS);
        $csv->setCSVname($filename);
        $csv->setQuery(getQuery($app->req->post['qID']));
        $csv->setCSVinfo('"', ",", "\n");

        if (empty($app->req->post['recipient'])) {
            $email = $app->req->post['email'];
        } else {
            $email = $app->req->post['recipient'];
        }

        try {
            Node::dispense('csv_email');
            $node = Node::table('csv_email');
            $node->recipient = (string) $email;
            $node->message = (string) "<h3>" . _t('CSV to Email Attachment') . "</h3><p>" . _t('The attached report can be opened with OpenOffice.org Calc, Google Docs, Microsoft Excel, or Apple Numbers.') . "</p>";
            $node->subject = (string) getQueryName($app->req->post['qID']);
            $node->filename = (string) $filename;
            $node->sent = (int) 0;
            $node->save();

            $csv->saveCSV();

            etsis_desktop_notify('CSV to Email', 'Message queued for sending.');
            etsis_redirect($app->req->server['HTTP_REFERER']);
        } catch (NodeQException $e) {
            Cascade::getLogger('error')->error(sprintf('NODEQSTATE[%s]: %s', $e->getCode(), $e->getMessage()));
        } catch (Exception $e) {
            Cascade::getLogger('error')->error(sprintf('NODEQSTATE[%s]: %s', $e->getCode(), $e->getMessage()));
        }
    }

    etsis_register_style('form');
    etsis_register_script('select');
    etsis_register_script('select2');

    $app->view->display('sql/csv-email', [
        'title' => 'CSV to Email Report'
        ]
    );
});

/**
 * Before route check.
 */
$app->before('GET|POST', '/sect/brgn/', function() {
    if (!hasPermission('register_students')) {
        _etsis_flash()->error(_t('403 - Error: Forbidden.'), get_base_url() . 'dashboard' . '/');
        exit();
    }
});

$app->match('GET|POST', '/sect/brgn/', function () use($app) {

    if ($app->req->isPost()) {
        try {
            $saved_query = $app->db->saved_query()
                ->where('id = ?', _filter_input_int(INPUT_POST, 'id'))
                ->findOne();
            $query = $saved_query->savedQuery;

            $time = \Jenssegers\Date\Date::now()->format('h:i A');

            $sq = $app->db->query("$query");
            $q = $sq->find(function($data) {
                $array = [];
                foreach ($data as $d) {
                    $array[] = $d;
                }
                return $array;
            });

            $sect = get_course_sec(_filter_input_int(INPUT_POST, 'courseSecID'));
            $crse = get_course(_escape($sect->courseSecID));
            $term = $app->db->term()
                ->where('termCode = ?', _escape($sect->termCode))
                ->findOne();

            foreach ($q as $r) {
                $stcs = $app->db->stcs();
                $stcs->stuID = _escape($r['stuID']);
                $stcs->courseSecID = _escape($sect->courseSecID);
                $stcs->courseSecCode = _escape($sect->courseSecCode);
                $stcs->courseSection = _escape($sect->courseSection);
                $stcs->termCode = _escape($sect->termCode);
                $stcs->courseCredits = _escape($sect->minCredit);
                $stcs->ceu = _escape($sect->ceu);
                $stcs->status = 'A';
                $stcs->regDate = \Jenssegers\Date\Date::now();
                $stcs->regTime = \Jenssegers\Date\Date::now()->format('h:i A');
                $stcs->statusDate = \Jenssegers\Date\Date::now();
                $stcs->statusTime = $time;
                $stcs->addedBy = get_persondata('personID');
                $stcs->save();

                $stac = $app->db->stac();
                $stac->stuID = _escape($r['stuID']);
                $stac->courseID = _escape($sect->courseID);
                $stac->courseSecID = _escape($sect->courseSecID);
                $stac->courseCode = _escape($sect->courseCode);
                $stac->courseSecCode = _escape($sect->courseSecCode);
                $stac->sectionNumber = _escape($sect->sectionNumber);
                $stac->courseSection = _escape($sect->courseSection);
                $stac->termCode = _escape($sect->termCode);
                $stac->reportingTerm = _escape($term->reportingTerm);
                $stac->subjectCode = _escape($crse->subjectCode);
                $stac->deptCode = _escape($sect->deptCode);
                $stac->shortTitle = _escape($crse->courseShortTitle);
                $stac->longTitle = _escape($crse->courseLongTitle);
                $stac->attCred = _escape($sect->minCredit);
                $stac->status = 'A';
                $stac->statusDate = \Jenssegers\Date\Date::now();
                $stac->statusTime = $time;
                $stac->acadLevelCode = _escape($sect->acadLevelCode);
                $stac->courseLevelCode = _escape($sect->courseLevelCode);
                $stac->startDate = _escape($sect->startDate);
                $stac->endDate = if_null(_escape($sect->endDate));
                $stac->addedBy = get_persondata('personID');
                $stac->addDate = \Jenssegers\Date\Date::now();
                $stac->save();

                /**
                 * Fires before the batch registration process is complete.
                 *
                 * @since 6.1.07
                 * @since 6.2.0 Changed to use $app->hook->do_action_array and added $stcs and $stac objects.
                 * @param object $stcs Student course section object.
                 * @param object $stac Student academic credit object.
                 */
                $app->hook->do_action_array('pre_brgn_stu_crse_reg', [ $stcs, $stac]);

                /**
                 * @since 6.1.07
                 */
                $_id = $stac->lastInsertId();
                $sacd = $app->db->stac()
                    ->select('stac.*,nae.uname,nae.fname,nae.lname,nae.email')
                    ->_join('person', 'stac.stuID = nae.personID', 'nae')
                    ->where('stac.id = ?', $_id)
                    ->findOne();
                /**
                 * Fires after a student has been registered into
                 * a course via the batch registration process.
                 * 
                 * @since 6.1.07
                 * @param array $sacd Student Academic Credit detail data object.
                 */
                $app->hook->do_action('post_brgn_stu_crse_reg', $sacd);

                if (function_exists('financial_module')) {
                    /**
                     * Generate bill and/or add fees.
                     */
                    generate_stu_bill(_escape($sect->termCode), _escape($r['stuID']), _escape($sect->courseSecID));
                }
            }

            etsis_logger_activity_log_write('New Record', 'Course Registration Via Staff', get_name(_escape($r['stuID'])) . ' - ' . _escape($sect->courseSection), get_persondata('uname'));
            _etsis_flash()->success(_etsis_flash()->notice(200), get_base_url() . 'sect/brgn' . '/');
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
    etsis_register_script('select');
    etsis_register_script('select2');
    etsis_register_script('jCombo');

    $app->view->display('section/batch-register', [
        'title' => 'Batch Course Registration',
        ]
    );
});

/**
 * Before route check.
 */
$app->before('GET|POST', '/sql/saved-queries/getSavedQuery/', function() {
    if (!hasPermission('execute_saved_query')) {
        _etsis_flash()->error(_t('403 - Error: Forbidden.'), get_base_url() . 'dashboard' . '/');
        exit();
    }
});

$app->post('/sql/saved-queries/getSavedQuery/', function() use($app) {
    $json = [ 'textarea#mustHaveId' => getQuery($app->req->post['qID'])];
    echo json_encode($json);
});

$app->setError(function() use($app) {

    $app->view->display('error/404', ['title' => '404 Error']);
});
