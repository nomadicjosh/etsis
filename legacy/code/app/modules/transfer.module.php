<?php
if (!defined('BASE_PATH'))
    exit('No direct script access allowed');
use app\src\Core\Exception\NotFoundException;
use app\src\Core\Exception\Exception;
use PDOException as ORMException;
use Cascade\Cascade;

/*
  Module Name: Transfer Module
  Module URI: https://www.edutracsis.com/client/cart/index/product/id/19/c/
  Version: 1.1.2
  Description: Allows you to create external courses from other institutions in order to add transfer credits for transfer students.
  Author: Joshua Parker
  Author URI: https://www.edutracsis.com/
  Module Slug: savedquery
 */

/**
 * Function used in the checking of whether
 * the transfer module exists.
 */
function transfer_module()
{
    return true;
}

/**
 * Function for transfer credit module.
 * 
 * @since 4.5
 */
function get_external_course($id = '')
{
    $app = \Liten\Liten::getInstance();
    try {
        $extr = $app->db->query('SELECT id,instCode,courseName FROM external_course ORDER BY id');
        $q = $extr->find(function($data) {
            $array = [];
            foreach ($data as $d) {
                $array[] = $d;
            }
            return $array;
        });
        foreach ($q as $r) {
            echo '<option value="' . $r['id'] . '"' . selected($r['id'], $id, false) . '>' . $r['instCode'] . ' ' . $r['courseName'] . '</option>' . "\n";
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
 * Function for transfer credit module.
 * 
 * @since 4.5
 */
function get_courses($id = '')
{
    $app = \Liten\Liten::getInstance();
    try {
        $crse = $app->db->query('SELECT courseID,courseCode FROM course WHERE currStatus = "A" AND creditType = "I" ORDER BY courseID');
        $q = $crse->find(function($data) {
            $array = [];
            foreach ($data as $d) {
                $array[] = $d;
            }
            return $array;
        });
        foreach ($q as $r) {
            echo '<option value="' . $r['courseID'] . '"' . selected($r['courseID'], $id, false) . '>' . $r['courseCode'] . '</option>' . "\n";
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
