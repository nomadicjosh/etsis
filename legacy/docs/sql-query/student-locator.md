If you need to locate a student because of an emergency situation, you can run the following student locator query.

**Release 6.3.x and above**

```
SELECT course_sec.secShortTitle AS 'Course Name',
course_sec.courseSecCode AS 'Course Section',
course_sec.roomCode AS 'Room',
course_sec.startTime AS 'Start Time',
course_sec.endTime AS 'End Time'
FROM course_sec 
LEFT JOIN stcs 
ON course_sec.courseSecID = stcs.courseSecID 
WHERE course_sec.termCode = '2013/FA' 
AND stcs.stuID = '16'
AND course_sec.dotw REGEXP('M')
AND '11:30 A.M.'
BETWEEN course_sec.startTime
AND course_sec.endTime;
```

**Release 4.4 =&gt; 6.2.x**

```
SELECT a.secShortTitle AS 'Course Name',
a.courseSecCode AS 'Course Section',
a.roomCode AS 'Room',
a.startTime AS 'Start Time',
a.endTime AS 'End Time'
FROM course_sec a 
LEFT JOIN stu_course_sec b 
ON a.courseSecID = b.courseSecID 
WHERE a.termCode = '13/FA' 
AND b.stuID = '16'
AND a.dotw REGEXP('M')
AND '11:30 A.M.'
BETWEEN a.startTime
AND a.endTime;
```

**Release 4.3 &amp; Below**

```
SELECT a.secShortTitle AS 'Course Name',

a.courseSecCode AS 'Course Section',

a.roomCode AS 'Room',

a.startTime AS 'Start Time',

a.endTime AS 'End Time'

FROM course_sec a 

LEFT JOIN stu_course_sec b 

ON a.courseSecCode = b.courseSecCode

AND a.termCode = b.termCode 

WHERE a.termCode = '13/FA' 

AND b.stuID = '16'

AND a.dotw REGEXP('M')

AND '11:30 A.M.'

BETWEEN a.startTime

AND a.endTime;
```