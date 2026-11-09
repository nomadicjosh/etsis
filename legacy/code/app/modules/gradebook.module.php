<?php
if (!defined('BASE_PATH'))
    exit('No direct script access allowed');
use app\src\Core\Exception\NotFoundException;
use app\src\Core\Exception\Exception;
use PDOException as ORMException;
use Cascade\Cascade;

/*
  Module Name: Gradebook Module
  Module URI: https://www.edutracsis.com/client/cart/index/product/id/19/c/
  Version: 1.1.3
  Description: Create assignments for grading and send emails with attachments to a student or group of students.
  Author: Joshua Parker
  Author URI: https://www.edutracsis.com/
  Module Slug: gradebook
 */

/**
 * Function used in the checking of whether
 * the multisite/campus module exists.
 */
function gradebook_module()
{
    return true;
}

function assignmentExist($id)
{
    $app = \Liten\Liten::getInstance();
    try {
        $assign = $app->db->assignment()
            ->where('courseSecID = ?', $id)
            ->count('id');
        if ($assign > 0) {
            return true;
        } else {
            return false;
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

function gradebookExist($id)
{
    $app = \Liten\Liten::getInstance();
    try {
        $gb = $app->db->gradebook()
            ->where('courseSecID = ?', $id)
            ->count('id');
        if ($gb > 0) {
            return true;
        } else {
            return false;
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

function student_grade($id, $aID)
{
    $app = \Liten\Liten::getInstance();
    try {
        $grade = $app->db->gradebook()
            ->where('stuID = ?', $id)->_and_()
            ->where('assignID = ?', $aID);
        $q = $grade->find(function($data) {
            $array = [];
            foreach ($data as $d) {
                $array[] = $d;
            }
            return $array;
        });
        return $q;
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

function final_grade($stuID, $sectID)
{
    $app = \Liten\Liten::getInstance();
    try {
        $final = $app->db->stac()
            ->where('stuID = ?', $stuID)->_and_()
            ->where('courseSecID = ?', $sectID)
            ->findOne();

        if (false == _h($final->grade)) {
            return 'NULL';
        } else {
            return _h($final->grade);
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

function gb_assignment($id)
{
    $app = \Liten\Liten::getInstance();
    try {
        $count = $app->db->assignment()
            ->where('courseSecID = ?', $id)
            ->count();

        return $count;
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

function get_attendance_status($date, $sectID, $stuID)
{
    $app = \Liten\Liten::getInstance();
    try {
        $q = $app->db->attendance()
            ->_join('person', 'attendance.stuID = person.personID')
            ->where('attendance.courseSecID = ?', $sectID)->_and_()
            ->where('attendance.stuID = ?', $stuID)->_and_()
            ->where('attendance.date = ?', $date)
            ->orderBy('person.lname');

        $attend = $q->find(function($data) {
            $array = [];
            foreach ($data as $d) {
                $array[] = $d;
            }
            return $array;
        });
        return $attend;
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

function get_attendance_numbers($sectID, $stuID, $status)
{
    $app = \Liten\Liten::getInstance();
    try {
        $count = $app->db->attendance()
            ->where('attendance.courseSecID = ?', $sectID)->_and_()
            ->where('attendance.stuID = ?', $stuID)->_and_()
            ->whereIn('attendance.status', $status)
            ->groupBy('attendance.courseSecID,attendance.stuID')
            ->count('attendance.id');
        return $count;
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

function does_attendance_exist($sectID, $status)
{
    $app = \Liten\Liten::getInstance();
    try {
        $count = $app->db->attendance()
            ->where('attendance.courseSecID = ?', $sectID)->_and_()
            ->whereIn('attendance.status', $status)
            ->groupBy('attendance.courseSecID,attendance.stuID')
            ->count('attendance.id');
        return $count;
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
