When you need to batch register a group of students into a course section, there are two ways you can create a saved query.

Use this first query example if you need to grab a list of student ID’s from a course section that is going to be cancelled and you need to re-register them into a new course section. This query selects all students based on a certain criteria that have a registration status of  ‘A’ and ‘N’.

**Release 6.3.x and above**

```
SELECT stuID
FROM stcs
WHERE courseSection = '2013/FA-EMATH-1001-01'
AND status IN('A','N');
```

Use this second query example if you have a list of student ID’s and want them registered into a particular course section if they are active students.

```
SELECT stuID
FROM student
WHERE stuID IN('1221','3423','1834')
AND status = 'A';
```

**Release 4.4 -&gt; 6.2.x**

```
SELECT stuID
FROM stu_course_sec
WHERE courseSection = '13/FA-EMATH-1001-01'
AND status IN('A','N');
```

**Release 4.3 &amp; Below**

```
SELECT stuID
FROM stu_course_sec
WHERE courseSecCode = 'EMATH-1001-01'
AND termCode = '13/FA'
AND status IN('A','N');
```