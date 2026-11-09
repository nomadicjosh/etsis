<?php
if (!defined('BASE_PATH'))
    exit('No direct script access allowed');
use \app\src\Core\NodeQ\etsis_NodeQ as Node;
use app\src\Core\NodeQ\NodeQException;
use app\src\Core\Exception\NotFoundException;
use app\src\Core\Exception\Exception;
use PDOException as ORMException;
use Cascade\Cascade;

/**
 * myetSIS Extended Router
 *
 * @license GPLv3
 *         
 * @since 5.0.0
 * @package eduTrac SIS
 * @author Joshua Parker <joshmac3@icloud.com>
 */
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
    'components/modules/admin/forms/elements/bootstrap-timepicker/assets/custom/js/bootstrap-timepicker.init.js?v=v2.1.0',
    'components/modules/admin/tables/datatables/assets/lib/js/jquery.dataTables.min.js?v=v2.1.0',
    'components/modules/admin/tables/datatables/assets/lib/extras/TableTools/media/js/TableTools.min.js?v=v2.1.0',
    'components/modules/admin/tables/datatables/assets/custom/js/DT_bootstrap.js?v=v2.1.0',
    'components/modules/admin/tables/datatables/assets/custom/js/datatables.init.js?v=v2.1.0'
];

/**
 * Before route check.
 */
$app->before('GET|POST', '/admin(.*)', function () {
    if (!hasPermission('access_myetsis_admin')) {
        _etsis_flash()->error(_t('403 - Error: Forbidden.'), get_base_url());
        exit();
    }
});

$app->group('/admin', function () use($app, $css, $js) {

    $app->get('/', function () use($app, $css, $js) {

        $app->view->display('admin/index', [
            'title' => _t('Administration'),
            'cssArray' => $css,
            'jsArray' => $js
        ]);
    });

    /**
     * Before route check.
     */
    $app->before('GET|POST', '/pages(.*)', function () {
        if (!hasPermission('manage_myetsis_pages')) {
            _etsis_flash()->error(_t('403 - Error: Forbidden.'), get_base_url());
            exit();
        }
    });

    $app->match('GET|POST', '/pages/', function () use($app, $css, $js) {

        try {
            $pages = $app->db->met_page();
            $sql = $pages->find(function ($data) {
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

        if ($app->req->isPost()) {
            try {
                $slug = etsis_sanitize_string($app->req->post['page_title']);
                if ($app->req->post['new_page'] == 'newpage') {
                    $new = $app->db->met_page();
                    $new->page_title = $app->req->post['page_title'];
                    $new->page_slug = slugify($slug);
                    $new->page_content = $app->req->post['page_content'];
                    $new->status = $app->req->post['status'];
                    $new->sort = $app->req->post['sort'];
                    $new->addedBy = get_persondata('personID');
                    $new->addDate = \Jenssegers\Date\Date::now();
                    $new->save();

                    etsis_cache_flush_namespace('met_page');
                } else {
                    $curr = $app->db->met_page();
                    $curr->page_title = $app->req->post['page_title'];
                    $curr->page_slug = slugify($slug);
                    $curr->page_content = $app->req->post['page_content'];
                    $curr->status = $app->req->post['status'];
                    $curr->sort = $app->req->post['sort'];
                    $curr->where('id = ?', $app->req->post['id']);
                    $curr->update();

                    etsis_cache_flush_namespace('met_page');
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

        $app->view->display('admin/pages', [
            'title' => _t('Manage Pages'),
            'cssArray' => $css,
            'jsArray' => $js,
            'page' => $sql
        ]);
    });

    /**
     * Before route check.
     */
    $app->before('GET|POST', '/news(.*)', function () {
        if (!hasPermission('manage_myetsis_news')) {
            _etsis_flash()->error(_t('403 - Error: Forbidden.'), get_base_url());
            exit();
        }
    });

    $app->match('GET|POST', '/news/', function () use($app, $css, $js) {

        try {
            $news = $app->db->met_news();
            $sql = $news->find(function ($data) {
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

        if ($app->req->isPost()) {
            try {
                $slug = etsis_sanitize_string($app->req->post['news_title']);
                if ($app->req->post['new_post'] == 'newpost') {
                    $new = $app->db->met_news();
                    $new->news_title = $app->req->post['news_title'];
                    $new->news_slug = slugify($slug);
                    $new->news_content = $app->req->post['news_content'];
                    $new->status = $app->req->post['status'];
                    $new->addedBy = get_persondata('personID');
                    $new->addDate = \Jenssegers\Date\Date::now();
                    $new->save();

                    etsis_cache_flush_namespace('met_news');
                } else {
                    $curr = $app->db->met_news();
                    $curr->news_title = $app->req->post['news_title'];
                    $curr->news_slug = slugify($slug);
                    $curr->news_content = $app->req->post['news_content'];
                    $curr->status = $app->req->post['status'];
                    $curr->where('id = ?', $app->req->post['id']);
                    $curr->update();

                    etsis_cache_flush_namespace('met_news');
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

        $app->view->display('admin/news', [
            'title' => _t('Manage News & Announcements'),
            'cssArray' => $css,
            'jsArray' => $js,
            'news' => $sql
        ]);
    });

    /**
     * Before route check.
     */
    $app->before('GET|POST', '/links(.*)', function () {
        if (!hasPermission('manage_myetsis_links')) {
            _etsis_flash()->error(_t('403 - Error: Forbidden.'), get_base_url());
            exit();
        }
    });

    $app->match('GET|POST', '/links/', function () use($app, $css, $js) {

        if ($app->req->isPost()) {
            try {
                if (isset($app->req->post['id'])) {
                    $link = $app->db->met_link();
                    foreach (_filter_input_array(INPUT_POST) as $k => $v) {
                        $link->$k = $v;
                    }
                    $link->where('id = ?', _filter_input_int(INPUT_POST, 'id'));
                    $link->update();

                    etsis_cache_flush_namespace('met_link');
                    etsis_logger_activity_log_write('Update Record', 'myetSIS Link', _filter_input_string(INPUT_POST, 'link_title'), get_persondata('uname'));
                } else {
                    $link = $app->db->met_link();
                    foreach (_filter_input_array(INPUT_POST) as $k => $v) {
                        $link->$k = $v;
                    }
                    $link->save();

                    etsis_cache_flush_namespace('met_link');
                    etsis_logger_activity_log_write('New Record', 'myetSIS Link', _filter_input_string(INPUT_POST, 'link_title'), get_persondata('uname'));
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
            $links = $app->db->met_link();
            $sql = $links->find(function ($data) {
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

        $app->view->display('admin/links', [
            'title' => _t('Manage Quick Links'),
            'cssArray' => $css,
            'jsArray' => $js,
            'links' => $sql
        ]);
    });

    /**
     * Before route check.
     */
    $app->before('GET|POST', '/csseditor(.*)', function () {
        if (!hasPermission('edit_myetsis_css')) {
            _etsis_flash()->error(_t('403 - Error: Forbidden.'), get_base_url());
            exit();
        }
    });

    $app->match('GET|POST', '/csseditor/', function () use($app, $css, $js) {

        $app->view->display('admin/csseditor', [
            'title' => _t('myetSIS CSS Editor'),
            'cssArray' => $css,
            'jsArray' => $js
        ]);
    });

    /**
     * Before route check.
     */
    $app->before('GET|POST', '/deletePage/(\d+)/', function () {
        if (!hasPermission('delete_myetsis_page')) {
            _etsis_flash()->error(_t('403 - Error: Forbidden.'), get_base_url());
            exit();
        }
    });

    $app->get('/deletePage/(\d+)/', function ($id) use($app) {

        try {
            $page = $app->db->met_page()
                ->where('id = ?', $id);
            $page->delete();

            etsis_cache_flush_namespace('met_page');
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
     * Before route check.
     */
    $app->before('GET|POST', '/deleteLink/(\d+)/', function () {
        if (!hasPermission('delete_myetsis_link')) {
            _etsis_flash()->error(_t('403 - Error: Forbidden.'), get_base_url());
            exit();
        }
    });

    $app->get('/deleteLink/(\d+)/', function ($id) use($app) {

        try {
            $link = $app->db->met_link()
                ->where('id = ?', $id);
            $link->delete();

            etsis_cache_flush_namespace('met_link');
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
     * Before route check.
     */
    $app->before('GET|POST', '/deleteNews/(\d+)/', function () {
        if (!hasPermission('delete_myetsis_news')) {
            _etsis_flash()->error(_t('403 - Error: Forbidden.'), get_base_url());
            exit();
        }
    });

    $app->get('/deleteNews/(\d+)/', function ($id) use($app) {

        try {
            $link = $app->db->met_news()
                ->where('id = ?', $id);
            $link->delete();

            etsis_cache_flush_namespace('met_news');
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

    $app->get('/flushCache/', function () use($app) {
        etsis_cache_flush();
        _etsis_flash()->success(_t('Cache was flushed successfully.'), $app->req->server['HTTP_REFERER']);
    });
});

$app->before('GET|POST', '/news/', function () {
    if (_h(get_option('enable_myetsis_portal')) == 0 && !hasPermission('edit_myetsis_css')) {
        etsis_redirect(get_base_url() . 'offline' . '/');
    }
});

$app->match('GET|POST', '/news/', function () use($app) {
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
        $news = $app->db->met_news()
            ->where('status = "publish"')
            ->orderBy('addDate', 'DESC');

        $q = etsis_cache_get('news', 'met_news');
        if (empty($q)) {
            $q = $news->find(function ($data) {
                $array = [];
                foreach ($data as $d) {
                    $array[] = $d;
                }
                return $array;
            });
            etsis_cache_add('news', $q, 'met_news');
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

    $app->view->display('index/news', [
        'title' => _t('News & Announcements'),
        'cssArray' => $css,
        'jsArray' => $js,
        'news' => $q
    ]);
});

$app->before('GET|POST', '/news/([^/]+)/', function () {
    if (_h(get_option('enable_myetsis_portal')) == 0 && !hasPermission('edit_myetsis_css')) {
        etsis_redirect(get_base_url() . 'offline' . '/');
    }
});

$app->match('GET|POST', '/news/([^/]+)/', function ($slug) use($app) {
    $css = [
        'css/admin/module.admin.page.alt.form_elements.min.css'
    ];

    try {
        $news = $app->db->met_news()
            ->where('news_slug = ?', $slug);

        $q = etsis_cache_get('news' . $slug, 'met_news');
        if (empty($q)) {
            $q = $news->find(function ($data) {
                $array = [];
                foreach ($data as $d) {
                    $array[] = $d;
                }
                return $array;
            });
            etsis_cache_add('news' . $slug, $q, 'met_news');
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
     */ elseif (count($q) <= 0) {

        $app->view->display('error/404', [
            'title' => '404 Error'
        ]);
    } /**
     * If we get to this point, the all is well
     * and it is ok to process the query and print
     * the results in a html format.
     */ else {

        $app->view->display('index/view-news', [
            'title' => _h($q[0]['news_title']),
            'cssArray' => $css,
            'news' => $q
        ]);
    }
});

$app->before('GET|POST', '/page(.*)', function () use($app) {
    if (_h(get_option('enable_myetsis_portal')) == 0 && !hasPermission('edit_myetsis_css')) {
        etsis_redirect(get_base_url() . 'offline' . '/');
    }

    if (isset($app->req->get['pg'])) {
        etsis_redirect(get_base_url() . 'page' . '/' . $app->req->get['pg'] . '/');
    }
});

$app->get('/page/([^/]+)/', function ($slug) use($app) {
    $css = [
        'css/admin/module.admin.page.alt.form_elements.min.css'
    ];

    try {
        $page = $app->db->met_page()
            ->where('page_slug = ?', $slug)
            ->_and_()
            ->where('status = "publish"');

        $q = etsis_cache_get('page' . $slug, 'met_page');
        if (empty($q)) {
            $q = $page->find(function ($data) {
                $array = [];
                foreach ($data as $d) {
                    $array[] = $d;
                }
                return $array;
            });
            etsis_cache_add('page' . $slug, $q, 'met_page');
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
     */ elseif (count($q) <= 0) {

        $app->view->display('error/404', [
            'title' => '404 Error'
        ]);
    } /**
     * If we get to this point, the all is well
     * and it is ok to process the query and print
     * the results in a html format.
     */ else {

        $app->view->display('index/view-page', [
            'title' => _h($q[0]['page_title']),
            'cssArray' => $css,
            'page' => $q
        ]);
    }
});

/**
 * Before route check.
 */
$app->before('GET|POST', '/form/address/', function () {
    if (!is_user_logged_in()) {
        _etsis_flash()->error(_t('401 - Error: Unauthorized.'), get_base_url() . 'login' . '/');
        exit();
    }

    if (_h(get_option('enable_myetsis_portal')) == 0 && !hasPermission('edit_myetsis_css')) {
        etsis_redirect(get_base_url() . 'offline' . '/');
    }
});

$app->match('GET|POST', '/form/address/', function () use($app) {

    $css = [
        'css/admin/module.admin.page.alt.tables.min.css'
    ];
    $js = [
        'components/modules/admin/tables/datatables/assets/lib/js/jquery.dataTables.min.js?v=v2.1.0',
        'components/modules/admin/tables/datatables/assets/lib/extras/TableTools/media/js/TableTools.min.js?v=v2.1.0',
        'components/modules/admin/tables/datatables/assets/custom/js/DT_bootstrap.js?v=v2.1.0',
        'components/modules/admin/tables/datatables/assets/custom/js/datatables.init.js?v=v2.1.0'
    ];

    $app->view->display('form/address', [
        'title' => _t('Change of Address Form'),
        'cssArray' => $css,
        'jsArray' => $js
    ]);
});

/**
 * Before route check.
 */
$app->before('GET|POST', '/form/photo/', function () {
    if (!is_user_logged_in()) {
        _etsis_flash()->error(_t('401 - Error: Unauthorized.'), get_base_url() . 'login' . '/');
    }

    if (_h(get_option('enable_myetsis_portal')) == 0 && !hasPermission('edit_myetsis_css')) {
        etsis_redirect(get_base_url() . 'offline' . '/');
    }
});

$app->match('GET|POST', '/form/photo/', function () use($app) {

    $css = [
        'css/admin/module.admin.page.alt.form_elements.min.css'
    ];
    $js = [
        'components/modules/admin/forms/elements/bootstrap-select/assets/lib/js/bootstrap-select.js?v=v2.1.0',
        'components/modules/admin/forms/elements/bootstrap-select/assets/custom/js/bootstrap-select.init.js?v=v2.1.0',
        'components/modules/admin/forms/elements/select2/assets/lib/js/select2.js?v=v2.1.0',
        'components/modules/admin/forms/elements/select2/assets/custom/js/select2.init.js?v=v2.1.0',
        'components/modules/admin/forms/elements/bootstrap-datepicker/assets/lib/js/bootstrap-datepicker.js?v=v2.1.0',
        'components/modules/admin/forms/elements/bootstrap-datepicker/assets/custom/js/bootstrap-datepicker.init.js?v=v2.1.0',
        'components/modules/admin/forms/elements/jasny-fileupload/assets/js/bootstrap-fileupload.js?v=v2.1.0'
    ];

    if ($app->req->isPost()) {
        $file_formats = [
            "jpg",
            "png",
            "gif",
            "bmp"
        ]; // Set File format
        $filepath = BASE_PATH . "static/photos/";
        if ($app->req->post['submit'] == "Upload") {
            $name = $_FILES['file_source']['name'];
            $size = $_FILES['file_source']['size'];
            if (strlen($name)) {
                $extension = substr($name, strrpos($name, '.') + 1);
                if (in_array($extension, $file_formats)) {
                    if ($size < (4096 * 1024)) {
                        $imagename = get_persondata('uname') . ".png";
                        $tmp = $_FILES['file_source']['tmp_name'];
                        if (move_uploaded_file($tmp, $filepath . $imagename)) {
                            try {
                                $q = $app->db->person();
                                $q->photo = $imagename;
                                $q->where('personID = ?', get_persondata('personID'));
                                $q->update();
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
                        } else {
                            _etsis_flash()->error(_t('The system could not move the uploaded file. Please check with your system administrator.'), $app->req->server['HTTP_REFERER']);
                        }
                    } else {
                        _etsis_flash()->error(_t('Your image size is bigger than 4MB. Try to upload a smaller image.'), $app->req->server['HTTP_REFERER']);
                    }
                } else {
                    _etsis_flash()->error(_t('Invalid file format. You must upload a jpeg, gif, png or bmp file.'), $app->req->server['HTTP_REFERER']);
                }
            } else {
                _etsis_flash()->error(_t('You have to first select an image to upload.'), $app->req->server['HTTP_REFERER']);
            }
        }
    }

    $app->view->display('form/photo', [
        'title' => _t('School Photo'),
        'cssArray' => $css,
        'jsArray' => $js
    ]);
});

/**
 * Before route check.
 */
$app->before('GET|POST', '/form/deleteSchoolPhoto/', function () {
    if (!is_user_logged_in()) {
        _etsis_flash()->error(_t('401 - Error: Unauthorized.'), get_base_url() . 'login' . '/');
        exit();
    }

    if (_h(get_option('enable_myetsis_portal')) == 0 && !hasPermission('edit_myetsis_css')) {
        etsis_redirect(get_base_url() . 'offline' . '/');
        exit();
    }
});

$app->get('/form/deleteSchoolPhoto/', function () use($app) {

    try {
        $nae = $app->db->person();
        $nae->photo = NULL;
        $nae->update();
        unlink(BASE_PATH . 'static/photos/' . get_persondata('uname') . '.png');
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
 * Before route check.
 */
$app->before('POST', '/form/submitCOA/', function () {
    if (!is_user_logged_in()) {
        _etsis_flash()->error(_t('401 - Error: Unauthorized.'), get_base_url() . 'login' . '/');
        exit();
    }

    if (_h(get_option('enable_myetsis_portal')) == 0 && !hasPermission('edit_myetsis_css')) {
        etsis_redirect(get_base_url() . 'offline' . '/');
        exit();
    }
});

$app->post('/form/submitCOA/', function () use($app) {

    try {
        // Creates node's schema if does not exist.
        Node::dispense('change_address');

        $node = Node::table('change_address');
        $node->uname = (string) get_persondata('uname');
        $node->email = (string) $app->req->post['email'];
        $node->personid = (int) get_persondata('personID');
        $node->fname = (string) get_persondata('fname');
        $node->lname = (string) get_persondata('lname');
        $node->address1 = (string) $app->req->post['address1'];
        $node->address2 = (string) $app->req->post['address2'];
        $node->city = (string) $app->req->post['city'];
        $node->state = (string) $app->req->post['state'];
        $node->zip = (string) $app->req->post['zip'];
        $node->country = (string) $app->req->post['country'];
        $node->phone = (string) $app->req->post['phone'];
        $node->sent = (int) 0;
        $node->save();
        _etsis_flash()->success(_t('Your request has been queued for sending.'));
    } catch (NodeQException $e) {
        Cascade::getLogger('error')->error(sprintf('NODEQSTATE[%s]: %s', $e->getCode(), $e->getMessage()));
        _etsis_flash()->error(_etsis_flash()->notice(409));
    } catch (Exception $e) {
        Cascade::getLogger('error')->error(sprintf('NODEQSTATE[%s]: %s', $e->getCode(), $e->getMessage()));
        _etsis_flash()->error(_etsis_flash()->notice(409));
    }
    etsis_redirect(get_base_url() . 'form/address/');
});

$app->match('GET|POST', '/online-app/', function () use($app) {

    if ($app->req->isPost()) {
        try {
            $pass = $app->req->post['password'];

            if (!empty($app->req->post['captcha']) || !empty($app->req->post['recaptcha'])) {
                etsis_redirect(get_base_url() . 'spam' . '/');
                exit();
            }

            $uname = username_exists(_trim($app->req->post['uname']));
            if ($uname) {
                _etsis_flash()->error(sprintf(_t('The username <strong>%s</strong> is already taken. Try again with a more unique username.'), _trim($app->req->post['uname'])), $app->req->server['HTTP_REFERER']);
                etsis_redirect($app->req->server['HTTP_REFERER']);
                exit();
            }

            $eAddr = email_exists(_trim($app->req->post['email']));
            if ($eAddr) {
                _etsis_flash()->error(sprintf(_t('The email address <strong>%s</strong> is already in use. Try again with a different email address.'), _trim($app->req->post['email'])), $app->req->server['HTTP_REFERER']);
                etsis_redirect($app->req->server['HTTP_REFERER']);
                exit();
            }

            $nae = $app->db->person();
            $nae->uname = _trim($app->req->post['uname']);
            $nae->personType = 'APL';
            $nae->prefix = $app->req->post['prefix'];
            $nae->fname = $app->req->post['fname'];
            $nae->lname = $app->req->post['lname'];
            $nae->mname = $app->req->post['mname'];
            $nae->email = _trim($app->req->post['email']);
            $nae->ssn = (int) str_replace('-', '', $app->req->post['ssn']);
            $nae->dob = $app->req->post['dob'];
            $nae->veteran = $app->req->post['veteran'];
            $nae->ethnicity = $app->req->post['ethnicity'];
            $nae->gender = $app->req->post['gender'];
            $nae->emergency_contact = $app->req->post['emergency_contact'];
            $nae->emergency_contact_phone = _trim($app->req->post['emergency_contact_phone']);
            $nae->password = etsis_hash_password($app->req->post['password']);
            $nae->approvedDate = \Jenssegers\Date\Date::now();
            $nae->approvedBy = '1';
            if ($nae->save()) {
                $_id = $nae->lastInsertId();
                $addr = $app->db->address();
                $addr->personID = $_id;
                $addr->address1 = $app->req->post['address1'];
                $addr->address2 = $app->req->post['address2'];
                $addr->city = $app->req->post['city'];
                $addr->state = $app->req->post['state'];
                $addr->addressType = 'P';
                $addr->zip = $app->req->post['zip'];
                $addr->country = $app->req->post['country'];
                $addr->startDate = \Jenssegers\Date\Date::now();
                $addr->addressStatus = 'C';
                $addr->addDate = \Jenssegers\Date\Date::now();
                $addr->addedBy = $_id;
                $addr->phone1 = $app->req->post['phone'];
                $addr->email1 = _trim($app->req->post['email']);
                $addr->save();

                $appl = $app->db->application();
                $appl->acadProgCode = _trim($app->req->post['acadProgCode']);
                $appl->startTerm = _trim($app->req->post['startTerm']);
                $appl->PSAT_Verbal = $app->req->post['PSAT_Verbal'];
                $appl->PSAT_Math = $app->req->post['PSAT_Math'];
                $appl->SAT_Verbal = $app->req->post['SAT_Verbal'];
                $appl->SAT_Math = $app->req->post['SAT_Math'];
                $appl->ACT_English = $app->req->post['ACT_English'];
                $appl->ACT_Math = $app->req->post['ACT_Math'];
                $appl->personID = $_id;
                $appl->addDate = \Jenssegers\Date\Date::now();
                $appl->applDate = \Jenssegers\Date\Date::now();
                $appl->addedBy = $_id;
                $appl->admitStatus = $app->req->post['admitStatus'];
                $appl->applStatus = 'Pending';
                $appl->save();
                $_applid = $appl->lastInsertId();
            }
            /**
             * Fires after application has been successfully created.
             *
             * @since 6.2.0
             * @param string $pass
             *            Plaintext password.
             */
            $app->hook->do_action('myetsis_appl_password', $pass);

            /**
             * Fires after application has been created.
             *
             * @since 6.1.07
             * @param array $nae
             *            Person data object.
             */
            $app->hook->do_action('post_save_myetsis_appl', $nae);

            etsis_logger_activity_log_write('New Record', _t('Student Application Via myeT'), get_name($_id) . ' (' . _trim($app->req->post['acadProgCode']) . ')', _trim($app->req->post['uname']));
            try {
                _etsis_email()->myetsisRegConfirm($_id, $app->req->post['password']);
                _etsis_email()->myetsisApplication($_id, $_applid);
            } catch (phpmailerException $e) {
                Cascade::getLogger('error')->error(sprintf('PHPMailer[%s]: Error: %s', $e->getCode(), $e->getMessage()));
            }
            _etsis_flash()->success(_t('Your application has been submitted, and you can now log in with your username and password.'), get_base_url() . 'login' . '/');
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
        'components/modules/admin/forms/elements/bootstrap-datepicker/assets/custom/js/bootstrap-datepicker.init.js?v=v2.1.0'
    ];

    $app->view->display('index/apply', [
        'title' => _t('Online Application'),
        'cssArray' => $css,
        'jsArray' => $js
    ]);
});

$app->setError(function () use($app) {

    $app->view->display('error/404', [
        'title' => '404 Error'
    ]);
});
