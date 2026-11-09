<?php
if (!defined('BASE_PATH'))
    exit('No direct script access allowed');
use app\src\Core\Exception\NotFoundException;
use app\src\Core\Exception\Exception;
use PDOException as ORMException;
use Cascade\Cascade;

/*
 * Module Name: Booking Module
 * Module URI: https://www.edutracsis.com/client/cart/index/product/id/19/c/
 * Version: 1.1.2
 * Description: This module can be used to book room/classroom space, book for events and meetings, and includes an institution calendar, faculty timetables as well as student timetables.
 * Author: Joshua Parker
 * Author URI: https://www.edutracsis.com/
 * Module Slug: booking
 */

/**
 * Function used in the checking of whether
 * the booking module exists.
 */
function booking_module()
{
    return true;
}

/**
 *
 * @since 4.1.8
 */
function convert_day($date)
{
    $weekday = \Jenssegers\Date\Date::parse($date)->format('N');
    return $weekday;
}

/**
 * This function is needed when sending room
 * request emails to the scheduler.
 *
 * @since 4.1.7
 */
function convertCat($cat)
{
    $app = \Liten\Liten::getInstance();
    try {
        $c = $app->db->query("SELECT * FROM event_category WHERE catID = ?", [
            $cat
        ]);
        $q = etsis_cache_get('event_category' . $cat, 'event');
        if (empty($q)) {
            $q = $c->find(function ($data) {
                $array = [];
                foreach ($data as $d) {
                    $array[] = $d;
                }
                return $array;
            });
            etsis_cache_add('event_category' . $cat, $q, 'event');
        }
        foreach ($q as $r) {
            return $r['cat_name'];
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
 *
 * @since 4.0.9
 */
function room_booking($data)
{
    $app = \Liten\Liten::getInstance();
    try {
        $email = _etsis_email();
        $mess = new \app\src\Core\etsis_Messages();
        /**
         * If check_event is set, then we will
         * check to make sure that the room and
         * time slots are available for booking.
         */
        if (!empty($data['check_event']) && $data['check_event'] == 'Check Availability') {
            $roomCode = $data['roomCode'];
            $sDate = $data['startDate'];
            $endDate = $data['endDate'];
            $sTime = \Jenssegers\Date\Date::parse($data['startTime'])->format('H:i:s');
            $eTime = \Jenssegers\Date\Date::parse($data['endTime'])->format('H:i:s');
            $start = $sDate . " " . $sTime;
            $end = $sDate . " " . $eTime;
            $repeats = $data['repeats'];
            $repeatFreq = $data['repeatFreq'];

            if (empty($repeats)) {
                $repeat = 0;
                $freq = 0;

                $room = $app->db->query("SELECT 
                        a.roomCode,a.termCode,a.title,b.*
                    FROM event a
                    LEFT JOIN event_meta b ON a.eventID = b.eventID 
                    WHERE a.roomCode = ?
                    AND (b.start BETWEEN ? AND ?
                    OR b.end BETWEEN ? AND ?)
                    GROUP BY b.roomCode,b.start,b.end,b.title", [
                    $data['roomCode'],
                    $start,
                    $end,
                    $start,
                    $end
                ]);
                $q = $room->find(function ($data) {
                    $array = [];
                    foreach ($data as $d) {
                        $array[] = $d;
                    }
                    return $array;
                });
                if (count($q[0]['eventID']) > 0) {
                    echo '<div class="alerts alerts-error text-center">';
                    foreach ($q as $k => $v) {
                        echo 'Conflict:&nbsp;&nbsp;&nbsp;&nbsp;' . $v['termCode'] . '&nbsp;&nbsp;&nbsp;&nbsp;' . $v['roomCode'] . '&nbsp;&nbsp;&nbsp;&nbsp;' . $v['title'] . '&nbsp;&nbsp;&nbsp;&nbsp;' . date('F d, o h:i A', strtotime($v['start'])) . '&nbsp;&nbsp;&nbsp;&nbsp;' . date('F d, o h:i A', strtotime($v['end']));
                    }
                    echo '</div>';
                }
            } else {
                $startDate = new \DateTime("$sDate");
                $lastDate = new \DateTime("$endDate");
                $days = $lastDate->diff($startDate)->format("%a");
                $limit = $days + 1;
                $until = ($limit / $repeatFreq);
                if ($repeatFreq == 1) {
                    $weekday = 0;
                }

                for ($x = 0; $x < $until; $x ++) {
                    $room = $app->db->query("SELECT * 
                            FROM event_meta as a
                            WHERE a.roomCode = ? 
                            AND (a.start BETWEEN ? AND ?
                            OR a.end BETWEEN ? AND ?)
                            GROUP BY a.roomCode,a.start,a.end,a.title", [
                        $roomCode,
                        $start,
                        $end,
                        $start,
                        $end
                    ]);
                    $q = $room->find(function ($data) {
                        $array = [];
                        foreach ($data as $d) {
                            $array[] = $d;
                        }
                        return $array;
                    });
                    if (count($q[0]['eventID']) > 0) {
                        echo '<div class="alerts alerts-error text-center">';
                        foreach ($q as $k => $v) {
                            echo 'Conflict:&nbsp;&nbsp;&nbsp;&nbsp;' . $v['termCode'] . '&nbsp;&nbsp;&nbsp;&nbsp;' . $v['roomCode'] . '&nbsp;&nbsp;&nbsp;&nbsp;' . $v['title'] . '&nbsp;&nbsp;&nbsp;&nbsp;' . date('F d, o h:i A', strtotime($v['start'])) . '&nbsp;&nbsp;&nbsp;&nbsp;' . date('F d, o h:i A', strtotime($v['end'])) . '<br />';
                        }
                        echo '</div>';
                    }
                    $sDate = strtotime($start . '+' . $repeatFreq . 'DAYS');
                    $eDate = strtotime($end . '+' . $repeatFreq . 'DAYS');
                    $start = date("Y-m-d H:i:s", $sDate);
                    $end = date("Y-m-d H:i:s", $eDate);
                }
            }
        }

        /**
         * If add_event is set, then we will
         * book the room and the available time slots.
         */
        if (!empty($data['add_event']) && $data['add_event'] == 'Submit') {
            $title = $data['title'];
            $text = $data['title'];
            $pID = get_persondata('personID');
            $roomCode = $data['roomCode'];
            $sDate = $data['startDate'];
            $endDate = $data['endDate'];
            $weekday = date('N', strtotime($sDate));
            $sTime = date('H:i:s', strtotime($data['startTime']));
            $eTime = date('H:i:s', strtotime($data['endTime']));
            $start = $sDate . " " . $sTime;
            $end = $sDate . " " . $eTime;
            $repeats = $data['repeats'];
            $repeatFreq = $data['repeatFreq'];

            if (empty($repeats)) {
                $repeat = 0;
                $freq = 0;

                $e = $app->db->event();
                $e->eventType = $data['eventType'];
                $e->catID = $data['catID'];
                $e->requestor = $pID;
                $e->roomCode = $roomCode;
                $e->termCode = $data['termCode'];
                $e->title = $title;
                $e->description = $text;
                $e->weekday = $weekday;
                $e->startDate = $sDate;
                $e->startTime = $sTime;
                $e->endTime = $eTime;
                $e->repeats = $repeat;
                $e->repeatFreq = $freq;
                $e->status = 'A';
                $e->addDate = \Jenssegers\Date\Date::now()->format('Y-m-d');
                $e->addedBy = $data['addedBy'];
                $e->save();
                $_id = $app->db->lastInsertId();

                $em = $app->db->event_meta();
                $em->eventID = $_id;
                $em->roomCode = $roomCode;
                $em->requestor = $pID;
                $em->start = $start;
                $em->end = $end;
                $em->title = $title;
                $em->description = $text;
                $em->addDate = \Jenssegers\Date\Date::now()->format('Y-m-d');
                $em->addedBy = $data['addedBy'];
                $em->save();
                etsis_cache_flush_namespace('sbook');
                etsis_cache_flush_namespace('event');
                etsis_cache_flush_namespace('event_meta');
            } else {
                $startDate = new \DateTime("$sDate");
                $lastDate = new \DateTime("$endDate");
                $days = $lastDate->diff($startDate)->format("%a");
                $limit = $days + 1;
                $until = ($limit / $repeatFreq);
                if ($repeatFreq == 1) {
                    $weekday = 0;
                }

                $e = $app->db->event();
                $e->eventType = $data['eventType'];
                $e->catID = $data['catID'];
                $e->requestor = $pID;
                $e->roomCode = $roomCode;
                $e->termCode = $data['termCode'];
                $e->title = $title;
                $e->description = $text;
                $e->weekday = $weekday;
                $e->startDate = $sDate;
                $e->startTime = $sTime;
                $e->endTime = $eTime;
                $e->repeats = $repeats;
                $e->repeatFreq = $repeatFreq;
                $e->status = 'A';
                $e->addDate = \Jenssegers\Date\Date::now()->format('Y-m-d');
                $e->addedBy = $data['addedBy'];
                $e->save();
                $_id = $app->db->lastInsertId();

                for ($x = 0; $x < $until; $x ++) {
                    $em = $app->db->event_meta();
                    $em->eventID = $_id;
                    $em->roomCode = $roomCode;
                    $em->requestor = $pID;
                    $em->start = $start;
                    $em->end = $end;
                    $em->title = $title;
                    $em->description = $text;
                    $em->addDate = \Jenssegers\Date\Date::now()->format('Y-m-d');
                    $em->addedBy = $data['addedBy'];
                    $em->save();

                    $sDate = strtotime($start . '+' . $repeatFreq . 'DAYS');
                    $eDate = strtotime($end . '+' . $repeatFreq . 'DAYS');
                    $start = date("Y-m-d H:i", $sDate);
                    $end = date("Y-m-d H:i", $eDate);
                }
                etsis_cache_flush_namespace('sbook');
                etsis_cache_flush_namespace('event');
                etsis_cache_flush_namespace('event_meta');
            }
        }

        /**
         * If book_request is set, then we will
         * book the room and the available time slots.
         */
        if (!empty($data['book_request']) && $data['book_request'] == 'Book It') {
            $title = $data['title'];
            $text = $data['description'];
            $requestor = $data['requestor'];
            $roomCode = $data['roomCode'];
            $sDate = $data['startDate'];
            $endDate = $data['endDate'];
            $weekday = date('N', strtotime($sDate));
            $sTime = date('H:i:s', strtotime($data['startTime']));
            $eTime = date('H:i:s', strtotime($data['endTime']));
            $start = $sDate . " " . $sTime;
            $end = $sDate . " " . $eTime;
            $repeats = $data['repeats'];
            $repeatFreq = $data['repeatFreq'];

            /**
             * Stuff for confirmation emails
             */
            $from = _h(get_option('room_request_email'));
            $headers = "From: $from" . "\r\n";
            $headers .= "X-Mailer: PHP/" . phpversion() . "\r\n";
            $headers .= "MIME-Version: 1.0" . "\r\n";
            $headers .= "Content-type:text/html;charset=UTF-8" . "\r\n";

            if (empty($repeats)) {
                $repeat = 0;
                $freq = 0;

                $e = $app->db->event();
                $e->eventType = $data['eventType'];
                $e->catID = $data['catID'];
                $e->requestor = $requestor;
                $e->roomCode = $roomCode;
                $e->title = $title;
                $e->description = $text;
                $e->weekday = $weekday;
                $e->startDate = $sDate;
                $e->startTime = $sTime;
                $e->endTime = $eTime;
                $e->repeats = $repeat;
                $e->repeatFreq = $freq;
                $e->status = 'A';
                $e->addDate = \Jenssegers\Date\Date::now();
                $e->addedBy = get_persondata('personID');

                if ($e->save()) {
                    $_id = $app->db->lastInsertId();
                    $message = _escape(get_option('room_booking_confirmation_text'));
                    $message = str_replace('#title#', $title, $message);
                    $message = process_email_html($body, _t("Room/Event Booking Confirmation"));
                    $email->etsisMail(get_user_value($requestor, 'email'), "Room/Event Booking Confirmation", $message, $headers);
                    $em = $app->db->event_meta();
                    $em->eventID = $_id;
                    $em->roomCode = $roomCode;
                    $em->requestor = $requestor;
                    $em->start = $start;
                    $em->end = $end;
                    $em->title = $title;
                    $em->description = $text;
                    $em->addDate = \Jenssegers\Date\Date::now()->format('Y-m-d');
                    $em->addedBy = $data['addedBy'];
                    $em->save();
                    /* Begin delete event_request once it's been booked and email sent. */
                    $app->db->query("DELETE FROM event_request WHERE requestID = ?", [
                        $data['requestID']
                    ]);
                    etsis_cache_delete($data['requestID'], 'request');
                    etsis_cache_flush_namespace('event');
                    etsis_cache_flush_namespace('event_meta');
                    /* End delete event_request. */
                    $mess->init('success_message', $mess->notice(200));
                    etsis_redirect(get_base_url() . 'calendar/requests/');
                } else {
                    $mess->init('error_message', $mess->notice(409));
                    etsis_redirect(get_base_url() . 'calendar/requests/');
                }
            } else {
                $startDate = new \DateTime("$sDate");
                $lastDate = new \DateTime("$endDate");
                $days = $lastDate->diff($startDate)->format("%a");
                $limit = $days + 1;
                $until = ($limit / $repeatFreq);
                if ($repeatFreq == 1) {
                    $weekday = 0;
                }

                $e = $app->db->event();
                $e->eventType = $data['eventType'];
                $e->catID = $data['catID'];
                $e->requestor = $requestor;
                $e->roomCode = $roomCode;
                $e->termCode = $data['termCode'];
                $e->title = $title;
                $e->description = $text;
                $e->weekday = $weekday;
                $e->startDate = $sDate;
                $e->startTime = $sTime;
                $e->endTime = $eTime;
                $e->repeats = $repeats;
                $e->repeatFreq = $repeatFreq;
                $e->status = 'A';
                $e->addDate = \Jenssegers\Date\Date::now()->format('Y-m-d');
                $e->addedBy = $data['addedBy'];
                $e->save();
                $_id = $app->db->lastInsertId();

                for ($x = 0; $x < $until; $x ++) {
                    $em = $app->db->event_meta();
                    $em->eventID = $_id;
                    $em->roomCode = $roomCode;
                    $em->requestor = $requestor;
                    $em->start = $start;
                    $em->end = $end;
                    $em->title = $title;
                    $em->description = $text;
                    $em->addDate = \Jenssegers\Date\Date::now()->format('Y-m-d');
                    $em->addedBy = $data['addedBy'];
                    $em->save();
                    $lastID = $em->lastInsertId();

                    $sDate = strtotime($start . '+' . $repeatFreq . 'DAYS');
                    $eDate = strtotime($end . '+' . $repeatFreq . 'DAYS');
                    $start = date("Y-m-d H:i", $sDate);
                    $end = date("Y-m-d H:i", $eDate);
                }
                if ($lastID > 0) {
                    $message = _escape(get_option('room_booking_confirmation_text'));
                    $message = str_replace('#title#', $title, $message);
                    $message = process_email_html($body, _t("Room/Event Booking Confirmation"));
                    $email->etsisMail(get_user_value($requestor, 'email'), "Room/Event Booking Confirmation", $message, $headers);
                    /* Begin delete event_request once it's been booked and email sent. */
                    $app->db->query("DELETE FROM event_request WHERE requestID = ?", [
                        $data['requestID']
                    ]);
                    etsis_cache_delete($data['requestID'], 'request');
                    etsis_cache_flush_namespace('event');
                    etsis_cache_flush_namespace('event_meta');
                    /* End delete event_request. */
                    $mess->init('success_message', $mess->notice(200));
                    etsis_redirect(get_base_url() . 'calendar/requests/');
                } else {
                    $mess->init('error_message', $mess->notice(409));
                    etsis_redirect(get_base_url() . 'calendar/requests/');
                }
            }
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
 *
 * @since 4.1.7
 */
function room_request($data)
{
    $app = \Liten\Liten::getInstance();
    try {
        $email = _etsis_email();
        $from = get_persondata('email');
        $to = _h(get_option('room_request_email'));
        $headers = "From: $from" . "\r\n";
        $headers .= "CC: $from" . "\r\n";
        $headers .= "X-Mailer: PHP/" . phpversion() . "\r\n";
        $headers .= "MIME-Version: 1.0" . "\r\n";
        $headers .= "Content-type:text/html;charset=UTF-8" . "\r\n";
        $message = _escape(get_option('room_request_text'));
        $message = str_replace('#id#', get_persondata('personID'), $message);
        $message = str_replace('#uname#', get_persondata('uname'), $message);
        $message = str_replace('#name#', get_name(get_persondata('personID')), $message);
        $message = str_replace('#fname#', get_persondata('fname'), $message);
        $message = str_replace('#lname#', get_persondata('lname'), $message);
        $message = str_replace('#email#', $from, $message);
        $message = str_replace('#title#', $data['title'], $message);
        $message = str_replace('#description#', $data['description'], $message);
        $message = str_replace('#request_type#', $data['eventType'], $message);
        $message = str_replace('#category#', convertCat($data['catID']), $message);
        $message = str_replace('#room#', $data['roomCode'], $message);
        $message = str_replace('#firstday#', $data['startDate'], $message);
        $message = str_replace('#lastday#', $data['endDate'], $message);
        $message = str_replace('#sTime#', $data['startTime'], $message);
        $message = str_replace('#eTime#', $data['endTime'], $message);
        $message = str_replace('#repeat#', $data['repeats'], $message);
        $message = str_replace('#occurrence#', $data['repeatFreq'], $message);
        $message = str_replace('#adminemail#', _h(get_option('system_email')), $message);
        $message = str_replace('#url#', get_base_url(), $message);
        $message = str_replace('#helpdesk#', _h(get_option('help_desk')), $message);
        $message = str_replace('#instname#', _h(get_option('institution_name')), $message);
        $message = str_replace('#mailaddr#', _h(get_option('mailing_address')), $message);
        $message = process_email_html($message, $data['title']);
        if ($email->etsisMail($to, _t("Room Request: ") . $data['title'], $message, $headers)) {

            $r = $app->db->event_request();
            $r->eventType = $data['eventType'];
            $r->catID = $data['catID'];
            $r->requestor = $data['requestor'];
            $r->roomCode = $data['roomCode'];
            $r->termCode = NULL;
            $r->title = $data['title'];
            $r->description = $data['description'];
            $r->weekday = $data['weekday'];
            $r->startDate = $data['startDate'];
            $r->startTime = $data['startTime'];
            $r->endDate = ($data['endDate'] != '' ? $data['endDate'] : NULL);
            $r->endTime = $data['endTime'];
            $r->repeats = $data['repeats'];
            $r->repeatFreq = $data['repeatFreq'];
            $r->addDate = $data['addDate'];
            $r->save();

            etsis_cache_flush_namespace('request');
            _etsis_flash()->success(_etsis_flash()->notice(200), get_base_url() . 'form/request-form/');
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
 *
 * @since 4.1.8
 */
function update_booking_request($data)
{
    $app = \Liten\Liten::getInstance();
    try {
        $r = $app->db->event_request();
        $r->eventType = $data['eventType'];
        $r->catID = $data['catID'];
        $r->requestor = $data['requestor'];
        $r->roomCode = $data['roomCode'];
        $r->title = $data['title'];
        $r->description = $data['description'];
        $r->weekday = $data['weekday'];
        $r->startDate = $data['startDate'];
        $r->startTime = $data['startTime'];
        $r->endDate = ($data['endDate'] != '' ? $data['endDate'] : NULL);
        $r->endTime = $data['endTime'];
        $r->repeats = $data['repeats'];
        $r->repeatFreq = $data['repeatFreq'];
        $r->where('requestID = ?', $data['requestID'])->update();
        $r->udpate();

        etsis_cache_delete($data['requestID'], 'request');
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

/**
 *
 * @since 4.1.7
 */
function eventCategory($active = NULL)
{
    $app = \Liten\Liten::getInstance();
    try {
        $cat = $app->db->query("SELECT * FROM event_category WHERE catID <> '1'");
        $q = etsis_cache_get('event_category', 'event');
        if (empty($q)) {
            $q = $cat->find(function ($data) {
                $array = [];
                foreach ($data as $d) {
                    $array[] = $d;
                }
                return $array;
            });
            etsis_cache_add('event_category', $q, 'event');
        }
        foreach ($q as $r) {
            echo '<option value="' . _h($r['catID']) . '"' . selected($active, _h($r['catID']), false) . '>' . _h($r['cat_name']) . '</option>' . "\n";
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

function get_requestor($active = NULL)
{
    $app = \Liten\Liten::getInstance();
    try {
        $req = $app->db->query("SELECT 
        				a.personID 
    				FROM person a 
					LEFT JOIN staff b ON a.personID = b.staffID 
					LEFT JOIN student c ON a.personID = c.stuID 
					WHERE b.status = 'A' 
					OR c.status = 'A'");
        $q = $req->find(function ($data) {
            $array = [];
            foreach ($data as $d) {
                $array[] = $d;
            }
            return $array;
        });
        foreach ($q as $r) {
            echo '<option value="' . _h($r['personID']) . '"' . selected($active, _h($r['personID']), false) . '>' . get_name($r['personID']) . '</option>' . "\n";
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
