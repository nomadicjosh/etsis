<?php
if (!defined('BASE_PATH'))
    exit('No direct script access allowed');
use app\src\Core\Exception\NotFoundException;
use app\src\Core\Exception\Exception;
use PDOException as ORMException;
use Cascade\Cascade;

/*
 * Module Name: myetSIS Extended Module
 * Module URI: https://www.edutracsis.com/client/cart/index/product/id/19/c/
 * Version: 1.1.1
 * Description: The module extends the current self service portal which allows for an admin to post news, quick links, and info pages.
 * Author: Joshua Parker
 * Author URI: https://www.edutracsis.com/
 * Module Slug: myet
 */
$app = \Liten\Liten::getInstance();

/**
 * Function used in the checking of whether
 * the myetSIS self service module exists.
 */
function myetsis_module()
{
    return true;
}

/**
 * Retrieve published pages and show in dropdown.
 */
function met_page()
{
    $app = \Liten\Liten::getInstance();
    try {
        $page = $app->db->met_page()
            ->select('page_title,page_slug')
            ->where('status = "publish"')
            ->orderBy('sort');

        $q = etsis_cache_get('met_page', 'met_page');
        if (empty($q)) {
            $q = $page->find(function ($data) {
                $array = [];
                foreach ($data as $d) {
                    $array[] = $d;
                }
                return $array;
            });
            etsis_cache_add('met_page', $q, 'met_page');
        }

        foreach ($q as $r) {
            echo '<li><a href="' . get_base_url() . 'page/' . $r['page_slug'] . '/">' . $r['page_title'] . '</a></li>' . "\n";
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

/**
 * Retrieve active links and show in dropdown.
 */
function met_link()
{
    $app = \Liten\Liten::getInstance();
    try {
        $link = $app->db->met_link()
            ->select('link_title,link_src')
            ->where('status = "active"')
            ->orderBy('sort');

        $q = etsis_cache_get('met_link', 'met_link');
        if (empty($q)) {
            $q = $link->find(function ($data) {
                $array = [];
                foreach ($data as $d) {
                    $array[] = $d;
                }
                return $array;
            });
            etsis_cache_add('met_link', $q, 'met_link');
        }

        foreach ($q as $r) {
            echo '<li><a href="' . $r['link_src'] . '">' . $r['link_title'] . '</a></li>' . "\n";
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

/**
 * Check if there are published pages.
 * If so, return true.
 *
 * @since 4.3
 */
function metPageExist()
{
    $app = \Liten\Liten::getInstance();
    try {
        $page = $app->db->met_page()
            ->select('id')
            ->where('status = "publish"')
            ->orderBy('sort');
        $q = $page->find(function ($data) {
            $array = [];
            foreach ($data as $d) {
                $array[] = $d;
            }
            return $array;
        });
        if (count($q[0]['id']) > 0) {
            return true;
        }
        return false;
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
 * Check if there are published news items.
 * If so, return true.
 *
 * @since 4.3
 */
function metNewsExist()
{
    $app = \Liten\Liten::getInstance();
    try {
        $new = $app->db->met_news()
            ->select('id')
            ->where('status = "publish"');
        $q = $new->find(function ($data) {
            $array = [];
            foreach ($data as $d) {
                $array[] = $d;
            }
            return $array;
        });
        if (count($q[0]['id']) > 0) {
            return true;
        }
        return false;
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
 * News widget function since `views`
 * don't work in the index view.
 *
 * @since 4.3
 */
function wNews()
{
    $app = \Liten\Liten::getInstance();
    try {
        $news = $app->db->met_news()
            ->select('news_title,news_slug,news_content,status,addedBy,addDate')
            ->where('status = "publish"')
            ->orderBy('addDate', 'DESC')
            ->limit(2);

        $q = etsis_cache_get('met_news', 'met_news');
        if (empty($q)) {
            $q = $news->find(function ($data) {
                $array = [];
                foreach ($data as $d) {
                    $array[] = $d;
                }
                return $array;
            });
            etsis_cache_add('met_news', $q, 'met_news');
        }

        foreach ($q as $r) {
            $array[] = $r;
        }
        return $array;
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
 * Check if there are published links.
 * If so, return true.
 *
 * @since 4.3
 */
function metLinkExist()
{
    $app = \Liten\Liten::getInstance();
    try {
        $link = $app->db->met_link()
            ->select('id')
            ->where('status = "active"');
        $q = $link->find(function ($data) {
            $array = [];
            foreach ($data as $d) {
                $array[] = $d;
            }
            return $array;
        });
        if (count($q[0]['id']) > 0) {
            return true;
        }
        return false;
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
 * A function with retrieves a myetSIS page
 * content based on a $_GET[] variable.
 * Purpose
 * of this function if for the_myetsis_page_content
 * filter.
 *
 * @since 4.3
 */
function get_myetsis_page($slug)
{
    $app = \Liten\Liten::getInstance();
    try {
        $page = $app->db->met_page()->where('page_slug = ?', $slug);

        $q = etsis_cache_get('met_page' . $slug, 'met_page');
        if (empty($q)) {
            $q = $page->find(function ($data) {
                $array = [];
                foreach ($data as $d) {
                    $array[] = $d;
                }
                return $array;
            });
            etsis_cache_add('met_page' . $slug, $q, 'met_page');
        }

        foreach ($q as $r) {
            return _escape($r['page_content']);
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

/**
 * The myetSIS page filter.
 *
 * @since 4.3
 */
function the_myetsis_page_content($slug)
{
    $app = \Liten\Liten::getInstance();
    $page_content = get_myetsis_page($slug);
    $page_content = $app->hook->apply_filter('the_myetsis_page_content', $page_content);
    $page_content = str_replace(']]>', ']]&gt;', $page_content);
    return $page_content;
}
