In order to be an effective reporter, you must understand JOIN’s. JOIN is a SQL keyword that let’s you query data from one or more database tables usually by way of a Foreign Key. A foreign key is a field in one table that usually points to the primary key in another table. I say usually because that is not always the case with eduTrac. Instead the foreign key may point to a term or code in another table.

This type of joining of data occurs quite often with relational database systems such as the one eduTrac uses (MySQL). If the database is a well designed one, then there will be several tables in that database containing related data.

### INNER JOIN

The first join we will look at is called INNER JOIN or just JOIN, which is the most frequently used clause or key phrase. The INNER JOIN clause will produce records that will match in all three tables. Which means every student will have a person record and every student will have some sort of stu\_course\_sec record.

The query looks something like this:

```
SELECT student.stuID AS 'Student ID', CONCAT( person.lname, ', ', person.fname ) AS 'Student Name', stu_course_sec.termCode AS 'Last Term Registered'
FROM student
INNER JOIN person ON student.stuID = person.personID
INNER JOIN stu_course_sec ON student.stuID = stu_course_sec.stuID
LEFT OUTER JOIN stu_course_sec section ON ( stu_course_sec.stuID = section.stuID
AND stu_course_sec.termCode < section.termCode )
WHERE section.stuID IS NULL
GROUP BY stu_course_sec.stuID
```

The above query gives me the information that I am looking for which is a list of students and the last term each student was registered. The use of LEFT OUTER JOIN will be explained later in the article. The relationship between the three tables is the student ID in both the student and stu\_course\_sec table which is a foreign key to the primary key in the person table (personID). The query will give me the following results:

| Student ID | Student Name   | Last Term Registered |
|------------|----------------|----------------------|
| 1          | Brown, James   | 13/FA                |
| 4          | Teapot, Terry  | 13/FA                |
| 12         | Mouse, Mickey  | 13/FA                |
| 16         | Baggins, Bilbo | 13/FA                |

### LEFT JOIN

If I wanted a list of students with the last term registered regardless of whether or not they have ever registered for a course, then I would used a LEFT JOIN. A LEFT JOIN will return results which matches every entry in the left tables (student and person) regardless of any matching entries in the right table (stu\_course\_sec).

Our new query will look like this:

```
SELECT student.stuID AS 'Student ID', CONCAT( person.lname, ', ', person.fname ) AS 'Student Name', stu_course_sec.termCode AS 'Last Term Registered'
FROM student
LEFT JOIN person ON student.stuID = person.personID
LEFT JOIN stu_course_sec ON student.stuID = stu_course_sec.stuID
LEFT OUTER JOIN stu_course_sec section ON ( stu_course_sec.stuID = section.stuID
AND stu_course_sec.termCode < section.termCode )
WHERE section.stuID IS NULL
GROUP BY stu_course_sec.stuID
```

Now, our new query will give me the following results:

| Student ID | Student Name   | Last Term Registered |
|------------|----------------|----------------------|
| 13         | Lovelace, John | NULL                 |
| 1          | Brown, James   | 13/FA                |
| 4          | Teapot, Terry  | 13/FA                |
| 12         | Mouse, Mickey  | 13/FA                |
| 16         | Baggins, Bilbo | 13/FA                |

### RIGHT JOIN

We can’t use our example query as an example for RIGHT JOIN. So let’s say I wanted a list of students and courses regardless if anyone is enrolled in those courses. I would use a RIGHT JOIN to accomplish this. A RIGHT JOIN returns results which matches every entry on the right table (course\_sec) regardless of any matching entries on the left table (student).

This is what our example query looks like:

```
SELECT student.stuID AS 'Student ID',
CONCAT(person.lname,', ',person.fname) AS 'Student Name',
course_sec.courseSection AS 'Course Section'
FROM student
LEFT JOIN person ON student.stuID = person.personID
LEFT JOIN stu_course_sec ON student.stuID = stu_course_sec.stuID
RIGHT JOIN course_sec on stu_course_sec.courseSection = course_sec.courseSection
```

Our query will yield the following results:

| Student ID | Student Name  | Course Section      |
|------------|---------------|---------------------|
| 12         | Mouse, Mickey | 12/FA-EMATH-1000-01 |
| 12         | Mouse, Mickey | 13/FA-EMATH-2007-01 |
| NULL       | NULL          | 13/FA-EPSYC-1001-02 |
| NULL       | NULL          | 13/FA-EPSYC-1240-01 |

As a note, RIGHT JOIN’s are rarely used because they are just the opposite of LEFT JOIN’s and basically yield the same kinds of results.

### LEFT OUTER JOIN

As a brief explanation, a LEFT OUTER JOIN will return records from the left table even if there no match in the right table.