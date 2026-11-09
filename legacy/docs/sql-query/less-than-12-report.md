Some colleges only run classes for a particular term if enough students are registered into the course. This less than 12 report is an example query to use if your college needs to run a report to determine if a course is going to run. Change 12 to the minimum number set by your institution.

**Release 6.3.x and above**

```
SELECT DISTINCT COUNT(stcs.courseSecCode) AS "# of Students", CONCAT(course_sec.termCode,'-',course_sec.courseSecCode) AS "Course Section", course_sec.secShortTitle AS "Title", CONCAT(person.lname,', ',person.fname) AS 'Faculty',course_sec.roomCode AS 'Room',course_sec.dotw AS 'Meeting Day',CONCAT(course_sec.startTime,' to ',course_sec.endTime) AS 'Meeting Time'
FROM stcs
LEFT JOIN course_sec
ON stcs.courseSecCode = course_sec.courseSecCode AND stcs.termCode = course_sec.termCode
LEFT JOIN person
ON course_sec.facID = person.personID
WHERE stcs.termCode = '2013/FA'
AND stcs.status IN('A','N')
GROUP BY stcs.courseSecCode,stcs.termCode
HAVING COUNT(stcs.courseSecCode) < 12;
```