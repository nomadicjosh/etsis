<?php
if (!defined('BASE_PATH'))
    exit('No direct script access allowed');
use app\src\Core\Exception\NotFoundException;
use app\src\Core\Exception\Exception;
use PDOException as ORMException;
use Cascade\Cascade;

/*
 * Module Name: Financial Module
 * Module URI: https://www.edutracsis.com/client/cart/index/product/id/19/c/
 * Version: 1.1.3
 * Description: The financial module includes student accounts, billing tables, general ledger, batch fees as well as the options of recording payments and the issuing of refunds.
 * Author: Joshua Parker
 * Author URI: https://www.edutracsis.com/
 * Module Slug: financial
 */
$app = \Liten\Liten::getInstance();

/**
 * Function used in the checking of whether
 * the multisite/campus module exists.
 */
function financial_module()
{
    return true;
}

function fee_table_dropdown()
{
    $app = \Liten\Liten::getInstance();
    try {
        $fee = $app->db->query('SELECT * FROM billing_table WHERE status = "A"');
        $q = etsis_cache_get('billingtable', 'billtable');
        if (empty($q)) {
            $q = $fee->find(function ($data) {
                $array = [];
                foreach ($data as $d) {
                    $array[] = $d;
                }
                return $array;
            });
            etsis_cache_add('billingtable', $q, 'billtable');
        }
        foreach ($q as $v) {
            echo '<option value="' . _escape($v['id']) . '">' . _escape($v['amount']) . ' ' . _escape($v['name']) . '</option>' . "\n";
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
 * Payment type dropdown: shows general list of payment types and
 * if $typeID is not NULL, shows the payment type attached
 * to a particular record.
 *
 * @since 1.0.3
 * @param string $typeID
 *            - optional
 * @return string Returns the record id if selected is true.
 */
function payment_type_dropdown($typeID = NULL)
{
    $app = \Liten\Liten::getInstance();
    try {
        $pay = $app->db->payment_type();
        $q = $pay->find(function ($data) {
            $array = [];
            foreach ($data as $d) {
                $array[] = $d;
            }
            return $array;
        });
        foreach ($q as $v) {
            echo '<option value="' . _escape($v['id']) . '"' . selected($typeID, _escape($v['id']), false) . '>' . _escape($v['type']) . '</option>' . "\n";
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
 * General ledger account dropdown: pulls dropdown list from general ledger
 * account table.
 * if $activeID is not NULL, shows the record attached
 * to a particular record.
 *
 * @since 1.1.5
 * @param string $activeID            
 * @return mixed
 */
function gl_acct_dropdown($activeID = NULL)
{
    $app = \Liten\Liten::getInstance();
    try {
        $q = $app->db->query("SELECT * FROM gl_account");
        $options = "";
        foreach ($q as $row) {
            $options .= '<option value="' . _escape($row->glacctID) . '"' . selected($activeID, _escape($row->glacctID), false) . '>' . _escape($row->gl_acct_number) . ' | ' . _escape($row->gl_acct_name) . '</option>';
        }
        echo $options;
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

function paypal_payment($data)
{
    $app = \Liten\Liten::getInstance();
    try {
        if (is_array($data)) {
            $q = $app->db->payment();
            $q->stuID = $data['stuID'];
            $q->termCode = $data['term'];
            $q->amount = $data['amt'];
            $q->paymentTypeID = '4';
            $q->paymentDate = $app->db - NOW();
            $q->save();
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
 * @since 4.1.6
 */
function past_balance($stuID, $term)
{
    $app = \Liten\Liten::getInstance();
    try {
        $bal = $app->db->query("SELECT 
                        stu_acct_bill.termCode AS Term,
                        COALESCE(SUM(Fees),0)+COALESCE(SUM(Tuition),0)+COALESCE(SUM(Payments),0) AS 'Balance'
                    FROM stu_acct_bill LEFT JOIN
                         (SELECT COALESCE(SUM(a.amount),0)*-1 AS 'Fees',a.stuID,a.termCode
                    FROM stu_acct_fee a 
                         WHERE a.type = 'Fee' 
                         GROUP BY a.stuID, a.termCode) student_fee
                         ON stu_acct_bill.stuID = student_fee.stuID AND stu_acct_bill.termCode = student_fee.termCode
                         LEFT JOIN
                         (SELECT COALESCE(SUM(b.total),0)*-1 AS 'Tuition',b.stuID,b.termCode 
                    FROM stu_acct_tuition b 
                        GROUP BY b.stuID, b.termCode) tuition 
                        ON stu_acct_bill.stuID = tuition.stuID AND stu_acct_bill.termCode = tuition.termCode
                        LEFT JOIN 
                         (SELECT COALESCE(SUM(amount),0) As Payments,stuID,termCode,amount,paymentDate
                    FROM payment 
                         GROUP BY stuID,termCode) payment 
                         ON stu_acct_bill.stuID = payment.stuID AND stu_acct_bill.termCode = payment.termCode
                         LEFT JOIN person ON stu_acct_bill.stuID = person.personID
                         WHERE stu_acct_bill.stuID = ?
                         AND stu_acct_bill.termCode <> ?
                    GROUP BY stu_acct_bill.stuID,stu_acct_bill.termCode
                    HAVING COALESCE(SUM(Fees),0)+COALESCE(SUM(Tuition),0)+COALESCE(SUM(Payments),0) < '0'", [
            $stuID,
            $term
        ]);

        $q = $bal->find(function ($data) {
            $array = [];
            foreach ($data as $d) {
                $array[] = $d;
            }
            return $array;
        });

        $a = [];
        foreach ($q as $r) {
            $a[] = $r;
        }
        return $a;
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
 * @since 4.1.6
 */
function past_credit($stuID, $term)
{
    $app = \Liten\Liten::getInstance();
    try {
        $credit = $app->db->query("SELECT 
                        bill.termCode AS Term,
                        COALESCE(SUM(CourseFees),0)+COALESCE(SUM(StuFees),0)+COALESCE(SUM(Payments),0) AS 'Credit'
                    FROM stu_acct_bill AS bill LEFT JOIN
                        (SELECT COALESCE(SUM(y.courseFee+y.labFee+y.materialFee),0)*-1 AS 'CourseFees',x.stuID,x.termCode
                    FROM stcs x LEFT JOIN course_sec y ON x.termCode = y.termCode AND x.courseSecCode = y.courseSecCode
                         GROUP BY x.stuID,x.termCode) stcs 
                         ON bill.stuID = stcs.stuID AND bill.termCode = stcs.termCode
                         LEFT JOIN
                         (SELECT COALESCE(SUM(b.amount),0)*-1 AS 'StuFees',a.stuID,a.termCode
                    FROM stu_acct_fee a
                         LEFT JOIN billing_table b ON a.type = b.type AND a.amount = b.amount
                         GROUP BY a.stuID, a.termCode) stu_acct_fee
                         ON bill.stuID = stu_acct_fee.stuID AND bill.termCode = stu_acct_fee.termCode
                         LEFT JOIN
                         (SELECT COALESCE(SUM(amount),0) As Payments,stuID,termCode,amount,paymentDate
                    FROM payment 
                         GROUP BY stuID,termCode) payment 
                         ON bill.stuID = payment.stuID AND bill.termCode = payment.termCode
                         LEFT JOIN person ON bill.stuID = person.personID
                         WHERE bill.stuID = ?
                         AND bill.termCode <> ?
                    GROUP BY bill.stuID,bill.termCode
                    HAVING COALESCE(SUM(CourseFees),0)+COALESCE(SUM(StuFees),0)+COALESCE(SUM(Payments),0) > '0'", [
            $stuID,
            $term
        ]);

        $q = $credit->find(function ($data) {
            $array = [];
            foreach ($data as $d) {
                $array[] = $d;
            }
            return $array;
        });

        $a = [];
        foreach ($q as $r) {
            $a[] = $r;
        }
        return $a;
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

function has_balance($id, $term)
{
    $app = \Liten\Liten::getInstance();

    try {
        $fee = $app->db->query("SELECT 
                        SUM(amount) AS Bill 
                    FROM stu_acct_fee  
                    WHERE stuID = ? 
                    AND termCode = ? 
                    AND type = 'Fee' 
                    GROUP BY stuID,termCode", [
            $id,
            $term
        ]);

        $q1 = $fee->find(function ($data) {
            $array = [];
            foreach ($data as $d) {
                $array[] = $d;
            }
            return $array;
        });

        foreach ($q1 as $r1) {
            $beginBalance = $r1['Bill'];
        }

        $tuition = $app->db->query("SELECT 
                        SUM(total) AS Tuition 
                    FROM stu_acct_tuition  
                    WHERE stuID = ? 
                    AND termCode = ? 
                    GROUP BY stuID,termCode", [
            $id,
            $term
        ]);

        $tui = $tuition->find(function ($data) {
            $array = [];
            foreach ($data as $d) {
                $array[] = $d;
            }
            return $array;
        });

        foreach ($tui as $rTuition) {
            $sumTuition = $rTuition['Tuition'];
        }

        $payment = $app->db->query("SELECT 
                        SUM(amount) AS 'Payment' 
                    FROM payment 
                    WHERE stuID = ? 
                    AND termCode = ? 
                    GROUP BY stuID,termCode", [
            $id,
            $term
        ]);

        $q3 = $payment->find(function ($data) {
            $array = [];
            foreach ($data as $d) {
                $array[] = $d;
            }
            return $array;
        });

        foreach ($q3 as $r3) {
            $payment = $r3['Payment'];
        }

        $refund = $app->db->query("SELECT 
                        SUM(amount) AS 'Refund' 
                    FROM refund 
                    WHERE stuID = ? 
                    AND termCode = ? 
                    GROUP BY stuID,termCode", [
            $id,
            $term
        ]);

        $q4 = $refund->find(function ($data) {
            $array = [];
            foreach ($data as $d) {
                $array[] = $d;
            }
            return $array;
        });

        foreach ($q4 as $r4) {
            $refund = $r4['Refund'];
        }

        $studentTuition = bcadd($beginBalance, $sumTuition, 2);

        $endBalance = bcsub($payment, $studentTuition, 2);
        $currentBalance = bcsub($endBalance, $refund, 2);

        if ($currentBalance < 0) {
            return ' style="color:#d9534f"';
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
 * Retrieves payment method description based on the paymentTypeID.
 *
 * @since 6.0.00
 * @param
 *            int paymentTypeID
 * @return string
 */
function convert_payment_method($id)
{
    $app = \Liten\Liten::getInstance();
    try {
        $q = $app->db->payment_type()->where('id = ?', $id);
        $method = $q->find(function ($data) {
            $array = [];
            foreach ($data as $d) {
                $array[] = $d;
            }
            return $array;
        });
        return $method[0]['type'];
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
 * Generates a unique billID for invoicing.
 *
 * @since 6.0.00
 * @return string
 */
function generate_stu_billID()
{
    $app = \Liten\Liten::getInstance();
    try {
        $q = $app->db->stu_acct_bill()
            ->select('billID')
            ->orderBy('id', 'DESC')
            ->limit(1);
        $bill = $q->find(function ($data) {
            $array = [];
            foreach ($data as $d) {
                $array[] = $d;
            }
            return $array;
        });
        $id = substr($bill[0]['billID'], 3);
        return \app\src\ID::string(3) . str_pad($id + 1, 8, '0', STR_PAD_LEFT);
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
 * Function to help generate a student bill
 * if it doesn't exist, or just add fees
 * if it does exist.
 *
 * @since 6.0.00
 * @param string $term            
 * @param int $stuID            
 * @param int $sectID            
 */
function generate_stu_bill($term, $stuID, $sectID = null)
{
    $app = \Liten\Liten::getInstance();
    try {
        $sect = get_course_sec($sectID);

        $billCount = $app->db->stu_acct_bill()
            ->where('stuID = ?', $stuID)->_and_()
            ->where('termCode = ?', $term)
            ->count();

        if ($billCount <= 0) {
            // Create bill because it does not exist and add fees.
            $billID = generate_stu_billID();
            $cBill = $app->db->stu_acct_bill();
            $cBill->billID = $billID;
            $cBill->stuID = $stuID;
            $cBill->termCode = $term;
            $cBill->authCode = \app\src\ID::code(7) . '-' . \app\src\ID::code(7) . '-' . \app\src\ID::code(7);
            $cBill->postedBy = get_persondata('personID');
            $cBill->billingDate = \Jenssegers\Date\Date::now();
            $cBill->billTimeStamp = \Jenssegers\Date\Date::now();
            $cBill->save();

            $aFee = $app->db->stu_acct_fee();
            $aFee->billID = $billID;
            $aFee->stuID = $stuID;
            $aFee->termCode = $term;
            $aFee->type = 'Tuition';
            $aFee->description = $sect->courseSection;
            $aFee->amount = $sect->courseFee + $sect->materialFee + $sect->labFee;
            $aFee->postedBy = get_persondata('personID');
            $aFee->feeDate = \Jenssegers\Date\Date::now();
            $aFee->feeTimeStamp = \Jenssegers\Date\Date::now();
            $aFee->save();

            $tuition = $app->db->stu_acct_tuition();
            $tuition->stuID = $stuID;
            $tuition->termCode = $term;
            $tuition->total = $sect->courseFee + $sect->materialFee + $sect->labFee;
            $tuition->postedBy = get_persondata('personID');
            $tuition->tuitionTimeStamp = \Jenssegers\Date\Date::now();
            $tuition->save();
            /**
             * Student Business Rule Check
             * 
             * @since 6.3.0
             */
            $app->hook->do_action_array('stu_acct_rule', [$billID, $term, $stuID]);
        } else {
            // Select existing bill.
            $bill = $app->db->stu_acct_bill()
                ->where('stuID = ?', $stuID)->_and_()
                ->where('termCode = ?', $term)
                ->findOne();

            $aFee = $app->db->stu_acct_fee();
            $aFee->billID = $bill->billID;
            $aFee->stuID = $stuID;
            $aFee->termCode = $term;
            $aFee->type = 'Tuition';
            $aFee->description = $sect->courseSection;
            $aFee->amount = $sect->courseFee + $sect->labFee + $sect->materialFee;
            $aFee->postedBy = get_persondata('personID');
            $aFee->feeDate = \Jenssegers\Date\Date::now();
            $aFee->feeTimeStamp = \Jenssegers\Date\Date::now();
            $aFee->save();

            $countTuition = $app->db->stu_acct_tuition()
                ->where('stuID = ?', $stuID)->_and_()
                ->where('termCode = ?', $term)
                ->count();

            if ($countTuition <= 0) {
                $tuition = $app->db->stu_acct_tuition();
                $tuition->stuID = $stuID;
                $tuition->termCode = $term;
                $tuition->total = $sect->courseFee + $sect->materialFee + $sect->labFee;
                $tuition->postedBy = get_persondata('personID');
                $tuition->tuitionTimeStamp = \Jenssegers\Date\Date::now();
                $tuition->save();
            } else {
                $stuTuition = $app->db->stu_acct_tuition()
                    ->where('stuID = ?', $stuID)->_and_()
                    ->where('termCode = ?', $term)
                    ->findOne();

                $tuition = $app->db->stu_acct_tuition();
                $tuition->total = $stuTuition->total + $sect->courseFee + $sect->materialFee + $sect->labFee;
                $tuition->where('stuID = ?', $stuID)->_and_()
                    ->where('termCode = ?', $term);
                $tuition->update();
            }
        }
        etsis_cache_flush_namespace('student_account');
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

function paypal_url()
{
    if (_escape(get_option('paypal_use_ssl')) == 'true') {
        $pre = 'https://';
    } else {
        $pre = 'http://';
    }

    if (_escape(get_option('enable_paypal_gateway')) == 1) {
        if (_escape(get_option('paypal_sandbox_mode')) == 'false') {
            $ppurl = $pre . 'www.paypal.com/cgi-bin/webscr';
        } else {
            $ppurl = $pre . 'www.sandbox.paypal.com/cgi-bin/webscr';
        }
    }

    return $ppurl;
}

function paypal_gateway($endingBalance, $bill)
{
    if ($endingBalance < 0) :
        if (_escape(get_option('enable_paypal_gateway')) == 1) :

            ?>

            <form class="form-horizontal"
                  action="<?= get_base_url(); ?>stu/redirectPaypal/" method="post"
                  autocomplete="off" data-label="color" parsley-validate>
                <div class="form-group">
                    <label class="col-md-3 control-label"><?= _t('Pay Bill'); ?></label>
                    <div class="col-md-3">
                        <input type="text" name="amount" class="form-control"
                               value="<?= money_format('%i', (double) $endingBalance * -1); ?>"
                               parsley-required="true" required /> <input type="hidden" name="cmd"
                               value="_xclick" /> <input type="hidden" name="business"
                               value="<?= _escape(get_option('paypal_business_email')); ?>" /> <input
                               type="hidden" name="currency_code"
                               value="<?= _escape(get_option('paypal_currency_code')); ?>" /> <input
                               type="hidden" name="item_name"
                               value="<?= get_name($bill[0]['stuID']); ?> <?= _t('Student Bill'); ?>" />
                        <input type="hidden" name="return"
                               value="<?= get_base_url(); ?>stu/payment-success/" /> <input
                               type="hidden" name="notify_url"
                               value="<?= get_base_url(); ?>stu/paypal-ipn/" /> <input
                               type="hidden" name="cancel_return"
                               value="<?= get_base_url(); ?>stu/payment-cancel/" /> <input
                               type="hidden" name="custom"
                               value="term=<?= _escape($bill[0]['termCode']); ?>&stuID=<?= _escape($bill[0]['stuID']); ?>" />
                        <input type="hidden" name="shipping" value="0.00" /> <input
                            type="hidden" name="item_number" value="<?= _escape($bill[0]['billID']); ?>" />
                        <input type="hidden" name="ppurl" value="<?= paypal_url(); ?>" />
                    </div>
                    <div class="col-md-3">
                        <button type="submit" class="btn btn-primary"><?= _t('Pay Now'); ?></button>
                    </div>
                </div>
            </form>


            <?php
        endif;

    endif;
}
$app->hook->add_action('stu_accts_payment_gateway', 'paypal_gateway', 10, 2);

/**
 * Shows a button to navigate to the previous terms
 * student account bill.
 *
 * @since 6.0.00
 * @param int $id            
 * @param int $stuID            
 * @return int
 */
function prev_stu_acct_record($id, $stuID)
{
    $app = \Liten\Liten::getInstance();
    try {
        $query = $app->db->stu_acct_bill()
            ->setTableAlias('sab')
            ->select('sab.billID')
            ->where('sab.stuID = ?', $stuID)->_and_()
            ->where('sab.id < ?', $id)
            ->orderBy('sab.id')
            ->limit(1);
        $result = $query->find(function ($data) {
            $array = [];
            foreach ($data as $d) {
                $array[] = $d;
            }
            return $array;
        });
        foreach ($result as $r) {
            return _escape($r['billID']);
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
 * Shows a button to navigate to the next terms
 * student account bill.
 *
 * @since 6.0.00
 * @param int $id            
 * @param int $stuID            
 * @return int
 */
function next_stu_acct_record($id, $stuID)
{
    $app = \Liten\Liten::getInstance();
    try {
        $query = $app->db->stu_acct_bill()
            ->setTableAlias('sab')
            ->select('sab.billID')
            ->where('sab.stuID = ?', $stuID)->_and_()
            ->where('sab.id > ?', $id)
            ->orderBy('sab.id')
            ->limit(1);
        $result = $query->find(function ($data) {
            $array = [];
            foreach ($data as $d) {
                $array[] = $d;
            }
            return $array;
        });
        foreach ($result as $r) {
            return _escape($r['billID']);
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
 * Billing table rule check.
 * 
 * @since 6.3.0
 * @param string $billID Unique bill id.
 * @param string $term Term to check for.
 * @param int $stuID Unique student ID.
 */
function etsis_reg_btrl_rule($billID, $term, $stuID)
{
    $app = \Liten\Liten::getInstance();

    try {
        $q = $app->db->billing_table()
            ->select('name,type,amount,rule')
            ->whereLike('term', "%$term%")->_and_()
            ->where('status = "A"');
        $btbl = $q->find(function ($data) {
            $array = [];
            foreach ($data as $d) {
                $array[] = $d;
            }
            return $array;
        });

        foreach ($btbl as $rec) {
            $rule = $rec['rule'];
            if ($rule != null) {
                $btrl = $app->db->query(
                    "SELECT v_sacp.stuID FROM v_sacp"
                    . " INNER JOIN stal ON v_sacp.stuID = stal.stuID AND v_sacp.prog = stal.acadProgCode"
                    . " WHERE $rule"
                );
                $q = $btrl->find(function($data) {
                    $array = [];
                    foreach ($data as $d) {
                        $array[] = $d;
                    }
                    return $array;
                });
                $a = [];
                foreach ($q as $row) {
                    $a[] = _escape($row['stuID']);
                }

                if (!in_array($stuID, $a)) {
                    return false;
                }
                add_stu_acct_fee($rec, $billID, $term, $stuID);

                add_stu_acct_tuition($rec, $billID, $term, $stuID);
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
 * This is a private function used by the financial business rule to generate
 * student fee.
 * 
 * @access private
 * @since 6.3.0
 * @param array $rec Array of the billing table.
 * @param sting $billID Unique generated bill ID.
 * @param string $term Term code to filter by.
 * @param int $stuID Unique student ID.
 */
function add_stu_acct_fee($rec, $billID, $term, $stuID)
{
    $app = \Liten\Liten::getInstance();
    try {
        if (_escape($rec['type']) == 'F') {
            $stu_fee = $app->db->stu_acct_fee()
                ->where('billID = ?', $billID)->_and_()
                ->where('stuID = ?', $stuID)->_and_()
                ->where('termCode = ?', $term)->_and_()
                ->where('type = "Fee"')->_and_()
                ->where('description = ?', _escape($rec['name']))->_and_()
                ->where('amount = ?', _escape($rec['amount']))
                ->count('id');
            if ($stu_fee <= 0) {
                $add_fee = $app->db->stu_acct_fee();
                $add_fee->insert([
                    'billID' => $billID,
                    'stuID' => $stuID,
                    'termCode' => $term,
                    'type' => 'Fee',
                    'description' => _escape($rec['name']),
                    'amount' => _escape($rec['amount']),
                    'feeDate' => Jenssegers\Date\Date::now(),
                    'feeTimeStamp' => Jenssegers\Date\Date::now(),
                    'postedBy' => get_persondata('personID')
                ]);
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
 * This is a private function used by the financial business rule to generate
 * student tuition.
 * 
 * @access private
 * @since 6.3.0
 * @param array $rec Array of the billing table.
 * @param sting $billID Unique generated bill ID.
 * @param string $term Term code to filter by.
 * @param int $stuID Unique student ID.
 */
function add_stu_acct_tuition($rec, $billID, $term, $stuID)
{
    $app = \Liten\Liten::getInstance();
    try {
        if (_escape($rec['type']) == 'T') {
            $stu_fee = $app->db->stu_acct_fee()
                ->where('billID = ?', $billID)->_and_()
                ->where('stuID = ?', $stuID)->_and_()
                ->where('termCode = ?', $term)->_and_()
                ->where('type = "Tuition"')->_and_()
                ->where('description = ?', _escape($rec['name']))->_and_()
                ->where('amount = ?', _escape($rec['amount']))
                ->count('id');
            if ($stu_fee <= 0) {
                $stu_tuition = $app->db->stu_acct_tuition()
                    ->where('stuID = ?', $stuID)->_and_()
                    ->where('termCode = ?', $term)
                    ->findOne();

                $add_fee = $app->db->stu_acct_fee();
                $add_fee->insert([
                    'billID' => $billID,
                    'stuID' => $stuID,
                    'termCode' => $term,
                    'type' => 'Tuition',
                    'description' => _escape($rec['name']),
                    'amount' => _escape($rec['amount']),
                    'feeDate' => Jenssegers\Date\Date::now(),
                    'feeTimeStamp' => Jenssegers\Date\Date::now(),
                    'postedBy' => get_persondata('personID')
                ]);
                if (count($stu_tuition > 0)) {
                    $upd_tuition = $app->db->stu_acct_tuition();
                    $upd_tuition->set([
                            'total' => _escape($rec['amount']) + _escape($stu_tuition->total)
                        ])
                        ->where('stuID = ?', $stuID)->_and_()
                        ->where('termCode = ?', $term);
                } else {
                    $add_tuition = $app->db->stu_acct_tuition();
                    $add_tuition->insert([
                        'stuID' => $stuID,
                        'termCode' => $term,
                        'total' => _escape($rec['amount']),
                        'postedBy' => get_persondata('personID'),
                        'tuitionTimeStamp' => Jenssegers\Date\Date::now(),
                    ]);
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
 * Retrieve a list of academic terms.
 * 
 * @since 6.3.1
 */
function get_btbl_terms()
{
    $app = \Liten\Liten::getInstance();
    try {
        $q = $app->db->term()
            ->where('termCode <> "NULL"')
            ->orderBy('termStartDate', 'DESC')
            ->find();
        foreach ($q as $r) {
            echo "{code: '" . _h($r->termCode) . "'" . ', name: ' . "'(" . _h($r->termCode) . ") " . _h($r->termName) . "'}" . ',' . "\n";
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
 * Adds label to the billing table status.
 * 
 * @since 6.3.1
 * @param string $status
 * @return string
 */
function etsis_btbl_status_label($status)
{
    $label = [
        'A' => 'label-success',
        'I' => 'label-danger'
    ];

    return $label[$status];
}
$app->hook->add_action('stu_acct_rule', 'etsis_reg_btrl_rule', 5, 3);
