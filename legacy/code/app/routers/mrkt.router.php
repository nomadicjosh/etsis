<?php
if (!defined('BASE_PATH'))
    exit('No direct script access allowed');
use \app\src\elFinder\elFinderConnector;
use \app\src\elFinder\elFinder;
use \app\src\elFinder\elFinderVolumeDriver;
use \app\src\elFinder\elFinderVolumeLocalFileSystem;
use \app\src\elFinder\elFinderVolumeS3;
use app\src\Core\NodeQ\etsis_NodeQ as Node;
use app\src\Core\NodeQ\NodeQException;
use app\src\Core\Exception\NotFoundException;
use app\src\Core\Exception\Exception;
use PDOException as ORMException;
use Defuse\Crypto\Crypto;
use Defuse\Crypto\Key;
use Cascade\Cascade;

/**
 * Marketing Router
 *
 * @license GPLv3
 *         
 * @since 6.3.0
 * @package eduTrac SIS
 * @author Joshua Parker <joshmac3@icloud.com>
 */
/**
 * Before router check.
 */
$app->before('GET|POST', '/mrkt(.*)', function () {
    if (!is_user_logged_in()) {
        _etsis_flash()->error(_t('401 - Error: Unauthorized.'), get_base_url() . 'login' . '/');
        exit();
    }
    if (!hasPermission('access_communication_mgmt')) {
        _etsis_flash()->error(_t('403 - Error: Forbidden.'), get_base_url() . 'dashboard' . '/');
        exit();
    }
});

$app->group('/mrkt', function () use($app) {

    $app->get('/', function () use($app) {

        try {
            $msgs = $app->db->campaign()
                ->where('deptCode = ?', get_persondata('deptCode'))
                ->orderBy('sendstart', 'ASC')
                ->find();
            $count = $app->db->campaign()
                ->where('status = "processing"')
                ->count('id');
        } catch (NotFoundException $e) {
            _etsis_flash()->error($e->getMessage());
        } catch (ORMException $e) {
            _etsis_flash()->error($e->getMessage());
        } catch (Exception $e) {
            _etsis_flash()->error($e->getMessage());
        }

        etsis_register_style('form');
        etsis_register_style('table');
        etsis_register_script('select');
        etsis_register_script('select2');
        etsis_register_script('datatables');

        $app->view->display('mrkt/index', [
            'title' => _t('Campaigns'),
            'msgs' => $msgs,
            'count' => $count
            ]
        );
    });

    $app->get('/list/', function () use($app) {
        try {
            $lists = $app->db->list()
                ->where('deptCode = ?', get_persondata('deptCode'))
                ->find();
        } catch (NotFoundException $e) {
            _etsis_flash()->error($e->getMessage());
        } catch (ORMException $e) {
            _etsis_flash()->error($e->getMessage());
        } catch (Exception $e) {
            _etsis_flash()->error($e->getMessage());
        }

        etsis_register_style('form');
        etsis_register_style('table');
        etsis_register_script('select');
        etsis_register_script('select2');
        etsis_register_script('datatables');

        $app->view->display('mrkt/list/index', [
            'title' => _t('My Email Lists'),
            'lists' => $lists
            ]
        );
    });

    $app->match('GET|POST', '/list/create/', function () use($app) {

        if ($app->req->isPost()) {
            try {
                $list = $app->db->list();
                $list->code = $app->req->post['code'];
                $list->name = $app->req->post['name'];
                $list->description = $app->req->post['description'] != '' ? $app->req->post['description'] : NULL;
                $list->rule = $app->req->post['rule'] != '' ? $app->req->post['rule'] : NULL;
                $list->created = Jenssegers\Date\Date::now();
                $list->owner = get_persondata('personID');
                $list->deptCode = get_persondata('deptCode');
                $list->status = $app->req->post['status'];
                $list->save();

                $_id = $list->lastInsertId();
                etsis_logger_activity_log_write('New Record', 'Email List', _filter_input_string(INPUT_POST, 'name'), get_persondata('uname'));
                _etsis_flash()->success(_etsis_flash()->notice(200), get_base_url() . 'mrkt/list' . '/' . $_id . '/');
            } catch (NotFoundException $e) {
                _etsis_flash()->error($e->getMessage());
            } catch (ORMException $e) {
                _etsis_flash()->error($e->getMessage());
            } catch (Exception $e) {
                _etsis_flash()->error($e->getMessage());
            }
        }

        etsis_register_style('form');
        etsis_register_style('bootstrap-datepicker');
        etsis_register_style('querybuilder');

        $app->view->display('mrkt/list/create', [
            'title' => _t('Create Email List')
            ]
        );
    });

    $app->match('GET|POST', '/template/', function () use($app) {

        try {
            $tpl = $app->db->template()
                ->where('deptCode = ?', get_persondata('deptCode'))
                ->find();
        } catch (NotFoundException $e) {
            _etsis_flash()->{'error'}($e->getMessage());
        } catch (ORMException $e) {
            _etsis_flash()->{'error'}($e->getMessage());
        } catch (Exception $e) {
            _etsis_flash()->{'error'}($e->getMessage());
        }

        etsis_register_style('form');
        etsis_register_style('table');
        etsis_register_script('select');
        etsis_register_script('select2');
        etsis_register_script('datatables');

        $app->view->display('mrkt/template/index', [
            'title' => _t('Templates'),
            'templates' => $tpl
            ]
        );
    });

    $app->match('GET|POST', '/template/(\d+)/', function ($id) use($app) {

        if ($app->req->isPost()) {
            try {
                $tpl = $app->db->template();
                $tpl->set([
                        'name' => $app->req->post['name'],
                        'description' => $app->req->post['description'],
                        'content' => $app->req->post['content']
                    ])
                    ->where('id = ?', $id)->_and_()
                    ->where('deptCode = ?', get_persondata('deptCode'))
                    ->update();
                etsis_logger_activity_log_write('Update Record', 'Template', $app->req->post['name'], get_persondata('uname'));
                _etsis_flash()->success(_etsis_flash()->notice(200), $app->req->server['HTTP_REFERER']);
            } catch (NotFoundException $e) {
                _etsis_flash()->{'error'}($e->getMessage());
            } catch (ORMException $e) {
                _etsis_flash()->{'error'}($e->getMessage());
            } catch (Exception $e) {
                _etsis_flash()->{'error'}($e->getMessage());
            }
        }

        try {
            $tpl = $app->db->template()
                ->where('id = ?', $id)->_and_()
                ->where('deptCode = ?', get_persondata('deptCode'))
                ->findOne();
        } catch (NotFoundException $e) {
            _etsis_flash()->{'error'}($e->getMessage());
        } catch (ORMException $e) {
            _etsis_flash()->{'error'}($e->getMessage());
        } catch (Exception $e) {
            _etsis_flash()->{'error'}($e->getMessage());
        }

        /**
         * If the database table doesn't exist, then it
         * is false and a 404 should be sent.
         */
        if ($tpl == false) {

            $app->view->display('error/404', ['title' => '404 Error']);
        }
        /**
         * If the query is legit, but there
         * is no data in the table, then 404
         * will be shown.
         */ elseif (empty($tpl) == true) {

            $app->view->display('error/404', ['title' => '404 Error']);
        }
        /**
         * If data is zero, 404 not found.
         */ elseif (count(_h($tpl->id)) <= 0) {

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

            $app->view->display('mrkt/template/view', [
                'title' => _t('Edit Template'),
                'tpl' => $tpl
                ]
            );
        }
    });

    $app->match('GET|POST', '/template/create/', function () use($app) {

        if ($app->req->isPost()) {
            try {
                $tpl = $app->db->template();
                $tpl->insert([
                    'name' => $app->req->post['name'],
                    'description' => $app->req->post['description'],
                    'content' => $app->req->post['content'],
                    'owner' => get_persondata('personID'),
                    'deptCode' => get_persondata('deptCode'),
                    'addDate' => Jenssegers\Date\Date::now()
                ]);
                $_id = $tpl->lastInsertId();

                etsis_logger_activity_log_write('New Record', 'Subscriber', $app->req->post['fname'] . ' ' . $app->req->post['lname'], get_persondata('uname'));
                _etsis_flash()->success(_etsis_flash()->notice(200), get_base_url() . 'mrkt/template' . '/' . $_id . '/');
            } catch (NotFoundException $e) {
                _etsis_flash()->{'error'}($e->getMessage());
            } catch (ORMException $e) {
                _etsis_flash()->{'error'}($e->getMessage());
            } catch (Exception $e) {
                _etsis_flash()->{'error'}($e->getMessage());
            }
        }

        etsis_register_style('form');
        etsis_register_script('select2');
        etsis_register_script('select');

        $app->view->display('mrkt/template/create', [
            'title' => _t('Create Template')
            ]
        );
    });

    $app->match('GET|POST', '/campaign/create/', function () use($app) {

        if ($app->req->isPost()) {
            try {
                $msg = $app->db->campaign();
                $msg->owner = get_persondata('personID');
                $msg->deptCode = get_persondata('deptCode');
                $msg->subject = $app->req->post['subject'];
                $msg->from_name = $app->req->post['from_name'];
                $msg->from_email = $app->req->post['from_email'];
                $msg->html = $app->req->post['html'];
                $msg->text = $app->req->post['text'] != '' ? $app->req->post['text'] : NULL;
                $msg->footer = $app->req->post['footer'];
                $msg->status = 'ready';
                $msg->sendstart = $app->req->post['sendstart'];
                $msg->archive = $app->req->post['archive'];
                $msg->addDate = Jenssegers\Date\Date::now();
                $msg->save();

                $_id = $msg->lastInsertId();

                $cpgn_list = $app->db->campaign_list();
                $cpgn_list->insert([
                    'cid' => $_id,
                    'lid' => $app->req->post['lid']
                ]);

                etsis_logger_activity_log_write('New Record', 'Campaign', _filter_input_string(INPUT_POST, 'subject'), get_persondata('uname'));
                _etsis_flash()->success(_etsis_flash()->notice(200), get_base_url() . 'mrkt/campaign' . '/' . $_id . '/');
            } catch (NotFoundException $e) {
                _etsis_flash()->error($e->getMessage());
            } catch (ORMException $e) {
                _etsis_flash()->error($e->getMessage());
            } catch (Exception $e) {
                _etsis_flash()->error($e->getMessage());
            }
        }

        etsis_register_style('form');
        etsis_register_style('bootstrap-datepicker');
        etsis_register_script('select2');
        etsis_register_script('select');
        etsis_register_script('bootstrap-datepicker');

        $app->view->display('mrkt/campaign/create', [
            'title' => _t('Create Campaign')
            ]
        );
    });

    $app->match('GET|POST', '/list/(\d+)/', function ($id) use($app) {

        if ($app->req->isPost()) {
            try {
                $list = $app->db->list();
                $list->name = $app->req->post['name'];
                $list->description = $app->req->post['description'];
                $list->rule = $app->req->post['rule'];
                $list->status = $app->req->post['status'];
                $list->where('id = ?', $id)->_and_()
                    ->where('deptCode = ?', get_persondata('deptCode'));
                $list->update();

                etsis_cache_delete($id, 'list');
                etsis_logger_activity_log_write('Update Record', 'Email List', _filter_input_string(INPUT_POST, 'name'), get_persondata('uname'));
                _etsis_flash()->success(_etsis_flash()->notice(200), $app->req->server['HTTP_REFERER']);
            } catch (NotFoundException $e) {
                _etsis_flash()->error($e->getMessage());
            } catch (ORMException $e) {
                _etsis_flash()->error($e->getMessage());
            } catch (Exception $e) {
                _etsis_flash()->error($e->getMessage());
            }
        }

        etsis_register_style('form');
        etsis_register_style('bootstrap-datepicker');
        etsis_register_style('querybuilder');

        $list = $app->db->list()->findOne($id);

        /**
         * If the database table doesn't exist, then it
         * is false and a 404 should be sent.
         */
        if ($list == false) {

            $app->view->display('error/404', ['title' => '404 Error']);
        }
        /**
         * If the query is legit, but there
         * is no data in the table, then 404
         * will be shown.
         */ elseif (empty($list) == true) {

            $app->view->display('error/404', ['title' => '404 Error']);
        }
        /**
         * If data is zero, 404 not found.
         */ elseif (count(_h($list->id)) <= 0) {

            $app->view->display('error/404', ['title' => '404 Error']);
        }
        /**
         * If we get to this point, the all is well
         * and it is ok to process the query and print
         * the results in a html format.
         */ else {

            $app->view->display('mrkt/list/view', [
                'title' => _t('View/Edit Email List'),
                'list' => $list
                ]
            );
        }
    });

    $app->match('GET|POST', '/campaign/(\d+)/', function ($id) use($app) {

        if ($app->req->isPost()) {
            try {
                $msg = $app->db->campaign();
                $msg->subject = $app->req->post['subject'];
                $msg->from_name = $app->req->post['from_name'];
                $msg->from_email = $app->req->post['from_email'];
                $msg->html = $app->req->post['html'];
                $msg->text = $app->req->post['text'];
                $msg->footer = $app->req->post['footer'];
                $msg->status = $app->req->post['status'];
                $msg->sendstart = $app->req->post['sendstart'];
                $msg->archive = $app->req->post['archive'];
                $msg->where('id = ?', $id)->_and_()
                    ->where('deptCode = ?', get_persondata('deptCode'));
                $msg->update();

                etsis_logger_activity_log_write('Update Record', 'Campaign', _filter_input_string(INPUT_POST, 'subject'), get_persondata('uname'));
                _etsis_flash()->success(_etsis_flash()->notice(200), $app->req->server['HTTP_REFERER']);
            } catch (NotFoundException $e) {
                _etsis_flash()->error($e->getMessage());
            } catch (ORMException $e) {
                _etsis_flash()->error($e->getMessage());
            } catch (Exception $e) {
                _etsis_flash()->error($e->getMessage());
            }
        }

        try {
            $msg = $app->db->campaign()
                ->where('owner = ?', get_persondata('personID'))->_and_()
                ->where('id = ?', $id)
                ->findOne();
        } catch (NotFoundException $e) {
            _etsis_flash()->error($e->getMessage(), $app->req->server['HTTP_REFERER']);
        } catch (ORMException $e) {
            _etsis_flash()->error($e->getMessage(), $app->req->server['HTTP_REFERER']);
        } catch (Exception $e) {
            _etsis_flash()->error($e->getMessage(), $app->req->server['HTTP_REFERER']);
        }

        /**
         * If the database table doesn't exist, then it
         * is false and a 404 should be sent.
         */
        if ($msg == false) {

            $app->view->display('error/404', ['title' => '404 Error']);
        }
        /**
         * If the query is legit, but there
         * is no data in the table, then 404
         * will be shown.
         */ elseif (empty($msg) == true) {

            $app->view->display('error/404', ['title' => '404 Error']);
        }
        /**
         * If data is zero, 404 not found.
         */ elseif (count(_h($msg->id)) <= 0) {

            $app->view->display('error/404', ['title' => '404 Error']);
        }
        /**
         * If we get to this point, the all is well
         * and it is ok to process the query and print
         * the results in a html format.
         */ else {

            etsis_register_style('form');
            etsis_register_style('bootstrap-datepicker');
            etsis_register_script('select2');
            etsis_register_script('select');
            etsis_register_script('bootstrap-datepicker');

            $app->view->display('mrkt/campaign/view', [
                'title' => _t('View/Edit Campaign'),
                'cpgn' => $msg
                ]
            );
        }
    });

    $app->get('/campaign/(\d+)/queue/', function ($id) use($app) {

        $cpgn = get_campaign_by_id($id);
        if (_h($cpgn->status) == 'processing') {
            _etsis_flash()->error(_t('Message is already queued.'), $app->req->server['HTTP_REFERER']);
            exit();
        }

        if (_h($cpgn->id) <= 0) {
            _etsis_flash()->success(_t('Campaign does not exist.'), $app->req->server['HTTP_REFERER']);
            exit();
        }

        $app->hook->{'do_action'}('queue_campaign', $cpgn);

        etsis_redirect($app->req->server['HTTP_REFERER']);
    });

    $app->get('/campaign/(\d+)/pause/', function ($id) use($app) {

        $cpgn = get_campaign_by_id($id);
        if (_h($cpgn->status) == 'paused') {
            _etsis_flash()->error(_t('Message is already paused.'), $app->req->server['HTTP_REFERER']);
            exit();
        }

        try {
            $upd = $app->db->campaign();
            $upd->set([
                    'status' => 'paused'
                ])
                ->where('id = ?', _h($cpgn->id))
                ->update();
            etsis_logger_activity_log_write('Update Record', 'Campaign Paused', _h($cpgn->subject), get_persondata('uname'));
            _etsis_flash()->success(_t('Campaign was successfully paused.'), $app->req->server['HTTP_REFERER']);
        } catch (NotFoundException $e) {
            _etsis_flash()->error($e->getMessage(), $app->req->server['HTTP_REFERER']);
        } catch (ORMException $e) {
            _etsis_flash()->error($e->getMessage(), $app->req->server['HTTP_REFERER']);
        } catch (Exception $e) {
            _etsis_flash()->error($e->getMessage(), $app->req->server['HTTP_REFERER']);
        }
    });

    $app->get('/campaign/(\d+)/resume/', function ($id) use($app) {

        $cpgn = get_campaign_by_id($id);
        if (_h($cpgn->status) == 'processing') {
            _etsis_flash()->error(_t('Message is already processing.'), $app->req->server['HTTP_REFERER']);
            exit();
        }

        try {
            $upd = $app->db->campaign();
            $upd->set([
                    'status' => 'processing'
                ])
                ->where('id = ?', _h($cpgn->id))
                ->update();
            etsis_logger_activity_log_write('Update Record', 'Campaign Resumed', _h($cpgn->subject), get_persondata('uname'));
            _etsis_flash()->success(_t('Campaign was successfully resumed.'), $app->req->server['HTTP_REFERER']);
        } catch (NotFoundException $e) {
            _etsis_flash()->error($e->getMessage(), $app->req->server['HTTP_REFERER']);
        } catch (ORMException $e) {
            _etsis_flash()->error($e->getMessage(), $app->req->server['HTTP_REFERER']);
        } catch (Exception $e) {
            _etsis_flash()->error($e->getMessage(), $app->req->server['HTTP_REFERER']);
        }
    });

    $app->post('/campaign/(\d+)/test/', function ($id) use($app) {
        $cpgn = get_campaign_by_id($id);
        $c_list = $app->db->campaign_list()->where('cid = ?', $id)->findOne();
        $sub = get_person_by('personID', get_persondata('personID'));
        $send_to = $app->req->post['email'] != '' ? $app->req->post['email'] : _h($sub->email);

        $custom_headers = [
            'xcampaignid' => $id,
            'xlistid' => _h($c_list->lid),
            'xsubscriberid' => _h($sub->personID),
            'xsubscriberemail' => _h($sub->email),
        ];

        $footer = _escape($cpgn->footer);
        $footer = str_replace('{email}', _h($sub->email), $footer);
        $footer = str_replace('{from_email}', _h($cpgn->from_email), $footer);

        $msg = _escape($cpgn->html);
        $msg = str_replace('{todays_date}', Jenssegers\Date\Date::now()->format('M d, Y'), $msg);
        $msg = str_replace('{subject}', _h($cpgn->subject), $msg);
        $msg = str_replace('{view_online}', '<a href="' . get_base_url() . 'archive/' . $id . '/">' . _t('View this email in your browser') . '</a>', $msg);
        $msg = str_replace('{first_name}', _h($sub->fname), $msg);
        $msg = str_replace('{last_name}', _h($sub->lname), $msg);
        $msg = str_replace('{email}', _h($sub->email), $msg);
        $msg = str_replace('{address1}', _h($sub->address1), $msg);
        $msg = str_replace('{address2}', _h($sub->address2), $msg);
        $msg = str_replace('{city}', _h($sub->city), $msg);
        $msg = str_replace('{state}', _h($sub->state), $msg);
        $msg = str_replace('{postal_code}', _h($sub->zip), $msg);
        $msg = str_replace('{country}', _h($sub->country), $msg);
        $msg .= $footer;
        $app->hook->{'do_action_array'}('etsis_test_email_init', [(object) $custom_headers, $send_to, _h($cpgn->subject), $msg, _escape($cpgn->text), '']);
        etsis_redirect($app->req->server['HTTP_REFERER']);
    });

    $app->get('/campaign/(\d+)/report/', function ($id) use($app) {

        try {
            $cpgn = get_campaign_by_id($id);
            $opened = $app->db->tracking()
                ->where('tracking.cid = ?', $id)
                ->sum('tracking.viewed');
            $unique_opens = $app->db->tracking()
                ->where('tracking.cid = ?', $id)
                ->count('tracking.id');
            $clicks = $app->db->tracking_link()
                ->where('tracking_link.cid = ?', $id)
                ->groupBy('tracking_link.cid')
                ->sum('tracking_link.clicked');
            $unique_clicks = $app->db->tracking_link()
                ->where('tracking_link.cid = ?', $id)
                ->count('DISTINCT tracking_link.sid');
        } catch (NotFoundException $e) {
            _etsis_flash()->error($e->getMessage());
        } catch (ORMException $e) {
            _etsis_flash()->error($e->getMessage());
        } catch (Exception $e) {
            _etsis_flash()->error($e->getMessage());
        }

        try {
            Node::dispense('campaign_queue');
            $unique_unsubs = Node::table('campaign_queue')
                ->where('cid', '=', $id)
                ->andWhere('is_unsubscribed', '=', 1)
                ->findAll()
                ->count();
            Node::dispense('campaign_bounce');
            $unique_bounces = Node::table('campaign_bounce')
                ->where('cid', '=', $id)
                ->findAll()
                ->count();
        } catch (NodeQException $e) {
            _etsis_flash()->error($e->getMessage());
        } catch (Exception $e) {
            _etsis_flash()->error($e->getMessage());
        }

        /**
         * If the database table doesn't exist, then it
         * is false and a 404 should be sent.
         */
        if ($cpgn == false) {

            $app->view->display('error/404', ['title' => '404 Error']);
        }
        /**
         * If the query is legit, but there
         * is no data in the table, then 404
         * will be shown.
         */ elseif (empty($cpgn) == true) {

            $app->view->display('error/404', ['title' => '404 Error']);
        }
        /**
         * If data is zero, 404 not found.
         */ elseif (count(_h($cpgn->id)) <= 0) {

            $app->view->display('error/404', ['title' => '404 Error']);
        }
        /**
         * If we get to this point, the all is well
         * and it is ok to process the query and print
         * the results in a html format.
         */ else {

            etsis_register_style('form');
            etsis_register_style('table');

            $app->view->display('mrkt/campaign/report', [
                'title' => _h($cpgn->subject),
                'cpgn' => $cpgn,
                'opened' => $opened,
                'unique_opens' => $unique_opens,
                'clicks' => $clicks,
                'unique_clicks' => $unique_clicks,
                'unique_unsubs' => $unique_unsubs,
                'unique_bounces' => $unique_bounces
                ]
            );
        }
    });

    $app->get('/campaign/(\d+)/report/opened/', function ($id) use($app) {

        try {
            $cpgn = get_campaign_by_id($id);
            $opens = $app->db->person()
                ->select('person.email,tracking.*')
                ->_join('tracking', 'tracking.sid = person.personID')
                ->_join('campaign', 'tracking.cid = campaign.id')
                ->where('campaign.id = ?', _h($cpgn->id))
                ->find();

            $sum = $app->db->campaign()
                ->where('deptCode = ?', get_persondata('deptCode'))->_and_()
                ->where('id = ?', $id)
                ->sum('viewed');
        } catch (NotFoundException $e) {
            _etsis_flash()->error($e->getMessage());
        } catch (ORMException $e) {
            _etsis_flash()->error($e->getMessage());
        } catch (Exception $e) {
            _etsis_flash()->error($e->getMessage());
        }

        /**
         * If the database table doesn't exist, then it
         * is false and a 404 should be sent.
         */
        if ($cpgn == false) {

            $app->view->display('error/404', ['title' => '404 Error']);
        }
        /**
         * If the query is legit, but there
         * is no data in the table, then 404
         * will be shown.
         */ elseif (empty($cpgn) == true) {

            $app->view->display('error/404', ['title' => '404 Error']);
        }
        /**
         * If data is zero, 404 not found.
         */ elseif (count(_h($cpgn->id)) <= 0) {

            $app->view->display('error/404', ['title' => '404 Error']);
        }
        /**
         * If we get to this point, the all is well
         * and it is ok to process the query and print
         * the results in a html format.
         */ else {

            etsis_register_style('form');
            etsis_register_style('table');
            etsis_register_script('highcharts');
            etsis_register_script('opened');

            $app->view->display('mrkt/campaign/opened', [
                'title' => _h($cpgn->subject),
                'cpgn' => $cpgn,
                'opens' => $opens,
                'sum' => $sum
                ]
            );
        }
    });

    $app->get('/campaign/(\d+)/report/clicked/', function ($id) use($app) {

        try {
            $cpgn = get_campaign_by_id($id);
            $clicks = $app->db->person()
                ->select('person.email,tracking_link.*')
                ->_join('tracking_link', 'tracking_link.sid = person.personID')
                ->_join('campaign', 'tracking_link.cid = campaign.id')
                ->where('campaign.id = ?', _h($cpgn->id))
                ->find();

            $sum = $app->db->person()
                ->select('person.email,tracking_link.*')
                ->_join('tracking_link', 'tracking_link.sid = person.personID')
                ->_join('campaign', 'tracking_link.cid = campaign.id')
                ->where('campaign.id = ?', _h($cpgn->id))
                ->sum('tracking_link.clicked');
        } catch (NotFoundException $e) {
            _etsis_flash()->error($e->getMessage());
        } catch (ORMException $e) {
            _etsis_flash()->error($e->getMessage());
        } catch (Exception $e) {
            _etsis_flash()->error($e->getMessage());
        }

        /**
         * If the database table doesn't exist, then it
         * is false and a 404 should be sent.
         */
        if ($cpgn == false) {

            $app->view->display('error/404', ['title' => '404 Error']);
        }
        /**
         * If the query is legit, but there
         * is no data in the table, then 404
         * will be shown.
         */ elseif (empty($cpgn) == true) {

            $app->view->display('error/404', ['title' => '404 Error']);
        }
        /**
         * If data is zero, 404 not found.
         */ elseif (count(_h($cpgn->id)) <= 0) {

            $app->view->display('error/404', ['title' => '404 Error']);
        }
        /**
         * If we get to this point, the all is well
         * and it is ok to process the query and print
         * the results in a html format.
         */ else {

            etsis_register_style('form');
            etsis_register_style('table');
            etsis_register_script('highcharts');
            etsis_register_script('clicked');

            $app->view->display('mrkt/campaign/clicked', [
                'title' => _h($cpgn->subject),
                'cpgn' => $cpgn,
                'clicks' => $clicks,
                'sum' => $sum
                ]
            );
        }
    });

    $app->match('GET|POST', '/elfinder/', function () use($app) {

        $app->view->display('mrkt/elfinder', [
            'title' => 'elfinder 2.0',
            'cssArray' => ['plugins/elfinder/css/elfinder.min.css', 'plugins/elfinder/css/theme.css'],
            'jsArray' => ['plugins/elfinder/js/elfinder.full.js', 'plugins/elfinder/js/tinymce.plugin.public.js']
            ]
        );
    });

    /**
     * Before route check.
     */
    $app->before('GET|POST', '/bounce/', function() {
        if (!hasPermission('edit_settings')) {
            _etsis_flash()->error(_t('403 - Error: Forbidden.'), get_base_url() . 'dashboard' . '/');
        }
    });

    $app->match('GET|POST', '/bounce/', function () use($app) {
        try {
            $node = Node::table('php_encryption')->find(1);
        } catch (NodeQException $e) {
            _etsis_flash()->error($e->getMessage());
        } catch (Exception $e) {
            _etsis_flash()->error($e->getMessage());
        }

        try {
            if (_escape(get_option('etsis_bmh_password')) != '') {
                $password = Crypto::decrypt(_escape(get_option('etsis_bmh_password')), Key::loadFromAsciiSafeString($node->key));
            } else {
                $password = _escape(get_option('etsis_bmh_password'));
            }
        } catch (Defuse\Crypto\Exception\BadFormatException $e) {
            _etsis_flash()->error($e->getMessage());
        } catch (Defuse\Crypto\Exception\WrongKeyOrModifiedCiphertextException $e) {
            _etsis_flash()->error($e->getMessage());
        } catch (Exception $e) {
            _etsis_flash()->error($e->getMessage());
        }

        if ($app->req->isPost()) {

            try {
                $app->hook->update_option('etsis_bmh_host', $app->req->post['etsis_bmh_host']);
                $app->hook->update_option('etsis_bmh_username', $app->req->post['etsis_bmh_username']);
                $app->hook->update_option('etsis_bmh_password', Crypto::encrypt($app->req->post['etsis_bmh_password'], Key::loadFromAsciiSafeString($node->key)));
                $app->hook->update_option('etsis_bmh_mailbox', $app->req->post['etsis_bmh_mailbox']);
                $app->hook->update_option('etsis_bmh_port', $app->req->post['etsis_bmh_port']);
                $app->hook->update_option('etsis_bmh_service', $app->req->post['etsis_bmh_service']);
                $app->hook->update_option('etsis_bmh_service_option', $app->req->post['etsis_bmh_service_option']);
            } catch (Defuse\Crypto\Exception\BadFormatException $e) {
                _etsis_flash()->error($e->getMessage());
            } catch (Defuse\Crypto\Exception\WrongKeyOrModifiedCiphertextException $e) {
                _etsis_flash()->error($e->getMessage());
            } catch (Exception $e) {
                _etsis_flash()->error($e->getMessage());
            }

            // Update more options here
            $app->hook->do_action('update_bounce_options');
            /* Write to logs */
            etsis_logger_activity_log_write('Update', 'Settings', 'Bounce Email Settings', get_persondata('uname'));
            _etsis_flash()->success(_t('Settings saved successfully.'), $app->req->server['HTTP_REFERER']);
        }

        etsis_register_style('form');
        etsis_register_script('select');
        etsis_register_script('select2');

        $app->view->display('mrkt/bounce', [
            'title' => 'Bounce Email Settings',
            'password' => $password
            ]
        );
    });

    $app->match('GET|POST', '/mailmerge/', function () use($app) {

        if ($app->req->isPost()) {
            etsis_redirect(get_base_url() . 'mrkt/mailmerge' . '/' . $app->req->post['qID'] . '/' . $app->req->post['label'] . '/');
        }

        etsis_register_style('form');
        etsis_register_script('select');
        etsis_register_script('select2');

        $app->view->display('mrkt/mailmerge', [
            'title' => 'Mail Merge'
        ]);
    });

    $app->get('/mailmerge/(\d+)/([^/]+)', function ($id, $label) use($app) {
        try {
            $merge = $app->db->saved_query()
                ->where('id', $id)
                ->findOne();

            $results = $app->db->query("$merge->savedQuery");
            $q = $results->find(function ($data) {
                $array = [];
                foreach ($data as $d) {
                    $array[] = $d;
                }
                return $array;
            });
            // Standard format
            $pdf = new \app\src\PDFLabel();
            $pdf->PDF_Label($label);
            $pdf->Open();
            // Print labels
            foreach ($q as $row) {
                $text = sprintf("%s %s\n%s %s\n%s %s %s", $row['fname'], $row['lname'], $row['address1'], $row['address2'], $row['city'], $row['state'], $row['zip']);
                $pdf->Add_PDF_Label($text);
            }
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
         * If we get to this point, the all is well
         * and it is ok to process the query and print
         * the results in a html format.
         */ else {
            $pdf->Output('labels.pdf', 'I');
        }
    });

    $app->get('/getTemplate/(\d+)/', function($id) use($app) {
        try {
            $template = $app->db->template()
                ->where('deptCode = ?', get_persondata('deptCode'))->_and_()
                ->where('id = ?', $id)
                ->find();

            foreach ($template as $tpl) {
                echo _escape($tpl->content);
            }
        } catch (NotFoundException $e) {
            _etsis_flash()->error($e->getMessage());
        } catch (ORMException $e) {
            _etsis_flash()->error($e->getMessage());
        } catch (Exception $e) {
            _etsis_flash()->error($e->getMessage());
        }
    });

    $app->get('/getOpenedDayReport/(\d+)/', function ($id) use($app) {

        try {
            $q = $app->db->query(
                "SELECT DATE_FORMAT(tracking.first_open, '%W, %M %d, %Y') as open_date, SUM(tracking.viewed) as num_opens "
                . "FROM tracking "
                . "JOIN campaign ON tracking.cid = campaign.id "
                . "WHERE campaign.deptCode = ? AND campaign.id = ? "
                . "GROUP BY DATE_FORMAT(tracking.first_open, '%W, %M %d, %Y')", [get_persondata('deptCode'), $id]
            );
            // Use closure as callback
            $results = etsis_cache_get($id, 'oday_report');
            if (empty($results)) {
                // Use closure as callback
                $results = $q->find(function($data) {
                    $array = [];
                    foreach ($data as $d) {
                        $array[] = $d;
                    }
                    return $array;
                });
                etsis_cache_add($id, $results, 'oday_report');
            }
            // Retrieve data passed from query to closure
            $rows = [];
            foreach ($results as $r) {
                $row[0] = Jenssegers\Date\Date::parse(_h($r['open_date']))->format('D d, M Y');
                $row[1] = _h($r['num_opens']);
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

    $app->get('/getOpenedHourReport/(\d+)/', function ($id) use($app) {

        try {
            $q = $app->db->query(
                "SELECT tracking.first_open as open_time, COUNT(tracking.id) as opens "
                . "FROM tracking "
                . "JOIN campaign ON tracking.cid = campaign.id "
                . "WHERE campaign.deptCode = ? AND campaign.id = ? "
                . "GROUP BY hour(tracking.first_open), day(tracking.first_open)", [get_persondata('deptCode'), $id]
            );
            // Use closure as callback
            $results = etsis_cache_get($id, 'ohour_report');
            if (empty($results)) {
                // Use closure as callback
                $results = $q->find(function($data) {
                    $array = [];
                    foreach ($data as $d) {
                        $array[] = $d;
                    }
                    return $array;
                });
                etsis_cache_add($id, $results, 'ohour_report');
            }
            // Retrieve data passed from query to closure
            $rows = [];
            foreach ($results as $r) {
                $row[0] = Jenssegers\Date\Date::parse(_h($r['open_time']))->format('D d, M Y / h:00 A');
                $row[1] = _h($r['opens']);
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

    $app->get('/getClickedDayReport/(\d+)/', function ($id) use($app) {

        try {
            $q = $app->db->query(
                "SELECT DATE_FORMAT(tracking_link.addDate, '%W, %M %d, %Y') as click_date, SUM(tracking_link.clicked) as num_clicks "
                . "FROM tracking_link "
                . "JOIN campaign ON tracking_link.cid = campaign.id "
                . "WHERE campaign.deptCode = ? AND campaign.id = ? "
                . "GROUP BY DATE_FORMAT(tracking_link.addDate, '%W, %M %d, %Y')", [get_persondata('deptCode'), $id]
            );

            $results = etsis_cache_get($id, 'cday_report');
            if (empty($results)) {
                // Use closure as callback
                $results = $q->find(function($data) {
                    $array = [];
                    foreach ($data as $d) {
                        $array[] = $d;
                    }
                    return $array;
                });
                etsis_cache_add($id, $results, 'cday_report');
            }
            // Retrieve data passed from query to closure
            $rows = [];
            foreach ($results as $r) {
                $row[0] = Jenssegers\Date\Date::parse(_h($r['click_date']))->format('D d, M Y');
                $row[1] = _h($r['num_clicks']);
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

    $app->get('/getClickedHourReport/(\d+)/', function ($id) use($app) {

        try {
            $q = $app->db->query(
                "SELECT tracking_link.addDate as click_hour, COUNT(tracking_link.id) as clicks "
                . "FROM tracking_link "
                . "JOIN campaign ON tracking_link.cid = campaign.id "
                . "WHERE campaign.deptCode = ? AND campaign.id = ? "
                . "GROUP BY hour(tracking_link.addDate), day(tracking_link.addDate)", [get_persondata('deptCode'), $id]
            );

            $results = etsis_cache_get($id, 'chour_report');
            if (empty($results)) {
                // Use closure as callback
                $results = $q->find(function($data) {
                    $array = [];
                    foreach ($data as $d) {
                        $array[] = $d;
                    }
                    return $array;
                });
                etsis_cache_add($id, $results, 'chour_report');
            }
            // Retrieve data passed from query to closure
            $rows = [];
            foreach ($results as $r) {
                $row[0] = Jenssegers\Date\Date::parse(_h($r['click_hour']))->format('D d, M Y / h:00 A');
                $row[1] = _h($r['clicks']);
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

    $app->get('/campaign/(\d+)/d/', function ($id) use($app) {
        try {
            $msg = $app->db->campaign()
                ->where('deptCode = ?', get_persondata('deptCode'))->_and_()
                ->where('owner = ?', get_persondata('personID'))
                ->where('id = ?', $id);

            try {
                Node::table('campaign_queue')
                    ->where('cid', '=', $id)
                    ->delete();
            } catch (NodeQException $e) {
                _etsis_flash()->error($e->getMessage());
            } catch (Exception $e) {
                _etsis_flash()->error($e->getMessage());
            }

            $msg->reset()->findOne($id)->delete();
            etsis_cache_delete($id, 'campaign');
            etsis_cache_delete($id, 'cpgn');
            etsis_cache_delete($id, 'clist');
            _etsis_flash()->success(_etsis_flash()->notice(200), $app->req->server['HTTP_REFERER']);
        } catch (NotFoundException $e) {
            _etsis_flash()->error($e->getMessage(), $app->req->server['HTTP_REFERER']);
        } catch (ORMException $e) {
            _etsis_flash()->error($e->getMessage(), $app->req->server['HTTP_REFERER']);
        } catch (Exception $e) {
            _etsis_flash()->error($e->getMessage(), $app->req->server['HTTP_REFERER']);
        }
    });

    $app->get('/list/(\d+)/d/', function ($id) use($app) {
        try {
            Node::table('campaign_queue')
                ->where('lid', '=', $id)
                ->delete();
        } catch (NodeQException $e) {
            _etsis_flash()->error($e->getMessage());
        } catch (Exception $e) {
            _etsis_flash()->error($e->getMessage());
        }

        try {
            $app->db->list()
                ->reset()
                ->findOne($id)
                ->where('owner = ?', get_person('personID'))->_and_()
                ->where('deptCode = ?', get_persondata('deptCode'))
                ->delete();
            _etsis_flash()->success(_etsis_flash()->notice(200), get_base_url() . 'mrkt/list' . '/');
        } catch (NotFoundException $e) {
            _etsis_flash()->error($e->getMessage(), get_base_url() . 'mrkt/list' . '/');
        } catch (ORMException $e) {
            _etsis_flash()->error($e->getMessage(), get_base_url() . 'mrkt/list' . '/');
        } catch (Exception $e) {
            _etsis_flash()->error($e->getMessage(), get_base_url() . 'mrkt/list' . '/');
        }
    });

    $app->get('/list/(\d+)/c/', function ($id) use($app) {
        try {
            $list = $app->db->list()
                ->where('deptCode = ?', get_persondata('deptCode'))->_and_()
                ->where('owner = ?', get_persondata('personID'))
                ->where('id = ?', $id)
                ->findOne();
            $list->set([
                    'rule' => NULL
                ])
                ->update();
            _etsis_flash()->success(_etsis_flash()->notice(200), get_base_url() . 'mrkt/list' . '/' . $id . '/');
        } catch (NotFoundException $e) {
            _etsis_flash()->error($e->getMessage(), get_base_url() . 'mrkt/list' . '/' . $id . '/');
        } catch (ORMException $e) {
            _etsis_flash()->error($e->getMessage(), get_base_url() . 'mrkt/list' . '/' . $id . '/');
        } catch (Exception $e) {
            _etsis_flash()->error($e->getMessage(), get_base_url() . 'mrkt/list' . '/' . $id . '/');
        }
    });

    $app->get('/template/(\d+)/d/', function ($id) use($app) {
        try {
            $app->db->template()
                ->where('owner = ?', get_persondata('personID'))->_and_()
                ->where('id = ?', $id)
                ->reset()
                ->findOne($id)
                ->delete();

            _etsis_flash()->success(_etsis_flash()->notice(200), $app->req->server['HTTP_REFERER']);
        } catch (NotFoundException $e) {
            _etsis_flash()->{'error'}($e->getMessage(), $app->req->server['HTTP_REFERER']);
        } catch (ORMException $e) {
            _etsis_flash()->{'error'}($e->getMessage(), $app->req->server['HTTP_REFERER']);
        } catch (Exception $e) {
            _etsis_flash()->{'error'}($e->getMessage(), $app->req->server['HTTP_REFERER']);
        }
    });
});

/**
 * Before route check.
 */
$app->before('POST|PATCH|PUT|OPTIONS|DELETE', '/connector/', function() {
    if (!is_user_logged_in()) {
        _etsis_flash()->error(_t('401 - Error: Unauthorized.'), get_base_url());
        exit();
    }
});

$app->match('GET|POST', '/connector/', function () use($app) {
    error_reporting(0);
    if (_h(get_option('elfinder_driver')) === 'elf_local_driver') :
        try {
            _mkdir($app->config('file.savepath') . 'shared' . '/');
        } catch (\app\src\Core\Exception\IOException $e) {
            Cascade::getLogger('error')->error(sprintf('IOSTATE[%s]: Unable to create directory: %s', $e->getCode(), $e->getMessage()));
        }
        $opts = array(
            // 'debug' => true,
            'roots' => array(
                array(
                    'driver' => 'LocalFileSystem',
                    'path' => $app->config('file.savepath') . 'shared/',
                    'alias' => 'Files',
                    'mimeDetect' => 'mime_content_type',
                    'mimefile' => BASE_PATH . 'app/src/elFinder/mime.types',
                    'accessControl' => 'access',
                    'attributes' => array(
                        array(
                            'read' => true,
                            'write' => true,
                            'locked' => false
                        )
                    ),
                    'uploadAllow' => [
                        'image/png', 'image/gif', 'image/jpeg',
                        'application/pdf', 'application/msword', 'application/rtf',
                        'application/vnd.ms-excel', 'application/x-compress',
                        'application/x-compressed-tar', 'application/x-gzip',
                        'application/x-tar', 'application/zip', 'audio/mpeg',
                        'audio/x-m4a', 'audio/x-wav', 'text/css', 'text/plain', 'text/x-comma-separated-values',
                        'text/rdf', 'video/mpeg', 'video/mp4', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                        'application/vnd.ms-powerpoint', 'application/vnd.ms-excel'],
                    'uploadDeny' => ['application/x-php'],
                    'uploadOrder' => array('allow', 'deny')
                )
            )
        );
    else :
        $opts = array(
            //'debug' => true,
            'roots' => array(
                array(
                    'driver' => 'S3',
                    'path' => 'shared',
                    'URL' => 'http://' . _h(get_option('amz_s3_bucket')) . '.s3.amazonaws.com/shared/',
                    'alias' => 'Files',
                    'mimeDetect' => 'mime_content_type',
                    'mimefile' => BASE_PATH . 'app/src/elFinder/mime.types',
                    'accessControl' => 'access',
                    'uploadAllow' => [
                        'image/png', 'image/gif', 'image/jpeg',
                        'application/pdf', 'application/msword', 'application/rtf',
                        'application/vnd.ms-excel', 'application/x-compress',
                        'application/x-compressed-tar', 'application/x-gzip',
                        'application/x-tar', 'application/zip', 'audio/mpeg',
                        'audio/x-m4a', 'audio/x-wav', 'text/css', 'text/plain', 'text/x-comma-separated-values',
                        'text/rdf', 'video/mpeg', 'video/mp4', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                        'application/vnd.ms-powerpoint', 'application/vnd.ms-excel'],
                    'uploadDeny' => ['application/x-php'],
                    'uploadOrder' => array('allow', 'deny'),
                    "s3" => array(
                        "key" => _h(get_option('amz_s3_access_key')),
                        "secret" => _h(get_option('amz_s3_secret_key')),
                        "region" => 'us-east-1'
                    ),
                    "bucket" => _h(get_option('amz_s3_bucket')),
                    "acl" => "public-read"
                )
            )
        );
    endif;
    // run elFinder
    $connector = new elFinderConnector(new elFinder($opts));
    $connector->run();
});

/**
 * Before route check.
 */
$app->before('GET', '/archive/', function() use($app) {
    header('Content-Type: application/json');
    $app->res->_format('json', 204);
    exit();
});

$app->get('/archive/(\d+)/', function ($id) use($app) {
    try {
        $cpgn = $app->db->campaign()
            ->where('campaign.id = ?', $id)->_and_()
            ->where('campaign.archive = "1"')
            ->findOne();
    } catch (NotFoundException $e) {
        _etsis_flash()->{'error'}($e->getMessage());
    } catch (ORMException $e) {
        _etsis_flash()->{'error'}($e->getMessage());
    } catch (Exception $e) {
        _etsis_flash()->{'error'}($e->getMessage());
    }

    /**
     * If the database table doesn't exist, then it
     * is false and a 404 should be sent.
     */
    if ($cpgn == false) {
        header('Content-Type: application/json');
        $app->res->_format('json', 404);
        exit();
    }
    /**
     * If the query is legit, but there
     * is no data in the table, then 404
     * will be shown.
     */ elseif (empty($cpgn) == true) {
        header('Content-Type: application/json');
        $app->res->_format('json', 404);
        exit();
    }
    /**
     * If data is zero, 404 not found.
     */ elseif (count(_h($cpgn->id)) <= 0) {
        header('Content-Type: application/json');
        $app->res->_format('json', 404);
        exit();
    }
    /**
     * If we get to this point, the all is well
     * and it is ok to process the query and print
     * the results in a html format.
     */ else {

        $app->view->display('index/archive', [
            'title' => _h($cpgn->subject),
            'cpgn' => $cpgn
            ]
        );
    }
});

/**
 * Before route check.
 */
$app->before('GET', '/tracking/', function() use($app) {
    header('Content-Type: application/json');
    $app->res->_format('json', 204);
    exit();
});

/**
 * Before route check.
 */
$app->before('GET', '/tracking/cid/', function() use($app) {
    header('Content-Type: application/json');
    $app->res->_format('json', 204);
    exit();
});

$app->get('/tracking/cid/(\d+)/sid/(\d+)/', function ($cid, $sid) use($app) {

    //Begin the header output
    header('Content-Type: image/png');

    try {
        $tracking = $app->db->tracking()
            ->where('cid = ?', $cid)->_and_()
            ->where('sid = ?', $sid)
            ->count('id');

        if ($tracking <= 0) {
            $track = $app->db->tracking();
            $track->insert([
                'cid' => $cid,
                'sid' => $sid,
                'first_open' => \Jenssegers\Date\Date::now(),
                'viewed' => +1
            ]);

            $app->db->query(
                "UPDATE `campaign` "
                . "SET `viewed` = `viewed` + 1 "
                . "WHERE `id` = ?", [$cid]
            );
        } else {
            $track = $app->db->tracking()
                ->where('cid = ?', $cid)->_and_()
                ->where('sid = ?', $sid)
                ->findOne();
            $track->set([
                    'viewed' => _h((int) $track->viewed) + 1
                ])
                ->update();

            $app->db->query(
                "UPDATE `campaign` "
                . "SET `viewed` = `viewed` + 1 "
                . "WHERE `id` = ?", [$cid]
            );
        }
        etsis_cache_flush_namespace('domain_report');
        etsis_cache_flush_namespace('oday_report');
        etsis_cache_flush_namespace('ohour_report');
        etsis_cache_flush_namespace('cday_report');
        etsis_cache_flush_namespace('chour_report');
    } catch (NotFoundException $e) {
        Cascade::getLogger('error')->{'error'}(sprintf('SQLSTATE[%s]: %s', $e->getCode(), $e->getMessage()));
    } catch (ORMException $e) {
        Cascade::getLogger('error')->{'error'}(sprintf('SQLSTATE[%s]: %s', $e->getCode(), $e->getMessage()));
    } catch (Exception $e) {
        Cascade::getLogger('error')->{'error'}(sprintf('SQLSTATE[%s]: %s', $e->getCode(), $e->getMessage()));
    }
    //Get the http URI to the image
    $img = get_base_url() . 'static/assets/img/blank.png';

    //Get the filesize of the image for headers
    $filesize = filesize(BASE_PATH . 'static/assets/img/blank.png');

    //Now actually output the image requested, while disregarding if the database was affected
    header('Pragma: public');
    header('Expires: 0');
    header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
    header('Cache-Control: private', false);
    header('Content-Disposition: attachment; filename="blank.png"');
    header('Content-Transfer-Encoding: binary');
    header('Content-Length: ' . $filesize);
    readfile($img);

    //All done, get out!
    exit();
});

$app->get('/lt/', function () use($app) {

    try {
        if (strpos($app->req->get['utm_campaign'], '_') !== false) {
            $cid = $app->req->get['cid'];
        } else {
            $cid = $app->req->get['utm_campaign'];
        }

        if (is_numeric($app->req->get['utm_term']) !== false) {
            $sid = $app->req->get['utm_term'];
        } else {
            $sid = $app->req->get['sid'];
        }

        $tracking = $app->db->tracking_link()
            ->where('cid = ?', $cid)->_and_()
            ->where('sid = ?', $sid)->_and_()
            ->where('url = ?', $app->req->get['url'])
            ->count('id');

        if ($tracking <= 0) {
            $track1 = $app->db->tracking_link();
            $track1->insert([
                'cid' => $cid,
                'sid' => $sid,
                'source' => $app->req->get['utm_source'],
                'medium' => $app->req->get['utm_medium'],
                'url' => $app->req->get['url'],
                'clicked' => +1,
                'addDate' => \Jenssegers\Date\Date::now()
            ]);
        } else {
            $track2 = $app->db->tracking_link()
                ->where('cid = ?', $cid)->_and_()
                ->where('sid = ?', $sid)->_and_()
                ->where('url = ?', $app->req->get['url'])
                ->findOne();
            $track2->set([
                    'clicked' => _h((int) $track2->clicked) + 1
                ])
                ->update();
        }
        etsis_cache_flush_namespace('domain_report');
        etsis_cache_flush_namespace('oday_report');
        etsis_cache_flush_namespace('ohour_report');
        etsis_cache_flush_namespace('cday_report');
        etsis_cache_flush_namespace('chour_report');
    } catch (NotFoundException $e) {
        Cascade::getLogger('error')->{'error'}($e->getMessage());
    } catch (ORMException $e) {
        Cascade::getLogger('error')->{'error'}($e->getMessage());
    } catch (Exception $e) {
        Cascade::getLogger('error')->{'error'}($e->getMessage());
    }
    etsis_redirect($app->req->get['url']);
});

$app->setError(function () use($app) {

    $app->view->display('error/404', [
        'title' => '404 Error'
    ]);
});
