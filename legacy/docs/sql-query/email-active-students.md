If you need to send an email blast to a list of active students, use the example query below. You an also use this query as a basis for emailing active academic staff, support staff, or all staff members.

```
SELECT a.uname,a.email,a.lname,a.fname,a.personID
FROM person a
LEFT JOIN student b ON a.personID = b.stuID
WHERE b.status = 'A';
```