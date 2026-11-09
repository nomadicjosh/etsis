<?php
if (!defined('BASE_PATH'))
    exit('No direct script access allowed');
use app\src\Core\Exception\NotFoundException;
use app\src\Core\Exception\Exception;
use PDOException as ORMException;
use Cascade\Cascade;

/*
  Module Name: Saved Query Module
  Module URI: https://www.edutracsis.com/client/cart/index/product/id/19/c/
  Version: 1.1.1
  Description: Create quick access to the queries you need for ad-hoc reporting.
  Author: Joshua Parker
  Author URI: https://www.edutracsis.com/
  Module Slug: savedquery
 */

/**
 * Function used in the checking of whether
 * the saved query module exists.
 */
function savedquery_module()
{
    return true;
}

function userQuery()
{
    $app = \Liten\Liten::getInstance();
    try {
        $name = get_persondata('fname') . ' ' . get_persondata('lname');
        $sq = $app->db->query(
            'SELECT id as ID, savedQueryName as Name 
        FROM saved_query 
        WHERE personID = ? 
        UNION ALL 
        SELECT id as ID, savedQueryName as Name 
        FROM saved_query 
        WHERE shared LIKE ?', [get_persondata('personID'), "%$name%"]
        );
        $q = $sq->find(function($data) {
            $array = [];
            foreach ($data as $d) {
                $array[] = $d;
            }
            return $array;
        });
        foreach ($q as $v) {
            echo '<option value="' . _h($v['ID']) . '">' . _h($v['Name']) . '</option>';
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
 * Query that retrieves the query statement based on id
 * to be used for the CSV to Email reports.
 * 
 * @since 4.5
 */
function getQuery($id)
{
    $app = \Liten\Liten::getInstance();
    try {
        $sq = $app->db->query("SELECT savedQuery FROM saved_query WHERE id = ?", [ $id]);
        $q = $sq->find(function($data) {
            $array = [];
            foreach ($data as $d) {
                $array[] = $d;
            }
            return $array;
        });
        foreach ($q as $r) {
            return $r['savedQuery'];
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
 * Query that retrieves the query name based on id
 * to be used for the CSV to Email reports.
 * 
 * @since 4.5
 */
function getQueryName($id)
{
    $app = \Liten\Liten::getInstance();
    try {
        $sq = $app->db->query("SELECT savedQueryName FROM saved_query WHERE id = ?", [ $id]);
        $q = $sq->find(function($data) {
            $array = [];
            foreach ($data as $d) {
                $array[] = $d;
            }
            return $array;
        });
        foreach ($q as $r) {
            return $r['savedQueryName'];
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
