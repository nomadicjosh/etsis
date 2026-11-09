The following query allows you to run a student account balance when you want to see what students have an outstanding balance grouped by terms. This query will only be useful to those who have purchased the `Financial Module`.

**Release 6.0.01 &amp; Above**

```
SELECT bill.termCode AS Term,bill.stuID AS 'Student ID',
CONCAT(person.lname, ', ', person.fname) AS 'Student Name',
CourseFees AS 'Course Fees',
StuFees AS 'Other Fees',
COALESCE(SUM(CourseFees),0)+COALESCE(SUM(StuFees),0) AS 'Beginning Balance',
Payments,
COALESCE(SUM(CourseFees),0)+COALESCE(SUM(StuFees),0)+COALESCE(SUM(Payments),0) AS 'Ending Balance',
bill.stu_comments AS 'Student Notes',
bill.staff_comments AS 'Staff Notes',
LastPaid.LastPayment AS 'Last Payment',
LastPaid.PaymentDate AS 'Payment Date'
FROM stu_acct_bill AS bill LEFT JOIN
(SELECT COALESCE(SUM(y.amount),0)*-1 AS 'CourseFees',y.stuID,y.termCode
 FROM stu_acct_fee y
 WHERE type = 'Tuition'
 GROUP BY y.stuID,y.termCode) saf 
 ON bill.stuID = saf.stuID AND bill.termCode = saf.termCode
 LEFT JOIN
 (SELECT COALESCE(SUM(a.amount),0)*-1 AS 'StuFees',a.stuID,a.termCode
 FROM stu_acct_fee a
 WHERE type = 'Fee'
 GROUP BY a.stuID, a.termCode) student_fee
 ON bill.stuID = student_fee.stuID AND bill.termCode = student_fee.termCode
 LEFT JOIN
 (SELECT COALESCE(SUM(amount),0) As Payments,stuID,termCode,amount,paymentDate
 FROM payment 
 GROUP BY stuID,termCode) payment 
 ON bill.stuID = payment.stuID AND bill.termCode = payment.termCode
 LEFT JOIN person ON bill.stuID = person.personID
 LEFT JOIN
 (SELECT amount AS LastPayment,stuID,termCode,paymentDate AS PaymentDate
 FROM payment AS LastPaid
 WHERE paymentDate = ANY (SELECT MAX(paymentDate) FROM payment GROUP BY stuID,termCode)
 GROUP BY stuID,termCode) LastPaid 
 ON bill.stuID = LastPaid.stuID AND bill.termCode = LastPaid.termCode
GROUP BY bill.stuID,bill.termCode
HAVING COALESCE(SUM(CourseFees),0)+COALESCE(SUM(StuFees),0)+COALESCE(SUM(Payments),0) < '0'
```