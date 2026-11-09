Use the example query in order to run a final grade report.

**Release 6.3.x and above**

```
SELECT stac.stuID AS 'Student ID',
CONCAT(person.lname,', ',person.fname) AS 'Student Name',
stac.courseSection AS 'Course Section',
stac.shortTitle AS 'Course Title',
stac.grade AS 'Final Grade'
FROM stac
LEFT JOIN person
ON stac.stuID = person.personID
WHERE stac.termCode = '2013/FA';
```

**Release 4.4 =&gt; 6.2.x**

```
SELECT a.stuID AS 'Student ID',
 
CONCAT(b.lname,', ',b.fname) AS 'Student Name',
 
a.courseSection AS 'Course Section',
 
a.shortTitle AS 'Course Title',
 
a.grade AS 'Final Grade'
 
FROM stu_acad_cred a
 
LEFT JOIN person b
 
ON a.stuID = b.personID
 
WHERE a.termCode = '13/FA';
```

**Release 4.3 and below**

```
SELECT a.stuID AS 'Student ID',

CONCAT(b.lname,', ',b.fname) AS 'Student Name',

CONCAT(a.termCode,'-',a.courseSecCode) AS 'Course Section',

c.secShortTitle AS 'Course Title',

a.grade AS 'Final Grade'

FROM stu_acad_cred a

LEFT JOIN person b

ON a.stuID = b.personID

LEFT JOIN course_sec c

ON a.courseSecCode = c.courseSecCode

AND a.termCode = c.termCode

WHERE a.termCode = '13/FA';
```