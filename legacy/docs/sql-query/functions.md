Functions help analyze data by performing a set of operations to manipulate data. They perform operations and may take an argument. Arguments are columns from tables fed into the function from a query statement.

There are two main groups of SQL functions:

1. Scalar functions are single input and single output.
2. Aggregate (GROUP BY) functions work on sets or groups of rows and return one output per group.

Lets say you wanted to create a pin number based on the last four digits of a person social security number. The following query would give you the list you need:

```
SELECT lname, fname, RIGHT(ssn,4) FROM person;
```

Below is a list of comment SQL functions and their syntax:

```
SELECT lname, fname, LEFT(ssn,3) FROM person;
```

 Returns the first three digits of the ssn column.

```
SELECT lname, fname, RIGHT(ssn,4) FROM person;
```

 Returns the last four digits of the ssn column.

```
SELECT lname, fname, SUBSTRING(ssn,8,4) FROM person;
```

Extracts the last four digits starting at the eighth character and returns the next four characters.

### SUM

The SQL Function \`SUM\` totals the values in the specified column.

#### Syntax

SUM(column\_name)

Let’s say that we wanted to get the sum of credits completed from the stu\_acad\_cred table. The following query would retrieve the results we need:

```
SELECT stuID, SUM(compCred) FROM stu_acad_cred WHERE grade IS NOT NULL GROUP BY stuID;
```

### COUNT

The SQL Function COUNT(column\_name), counts the number of non-null values in the specified column, while COUNT(\*) counts the number of selected rows.

#### Syntax

COUNT(colum\_name)  
COUNT(\*)

For example. lets run a query that retrieves the number of students with an active status:

```
SELECT COUNT(stuID) FROM student WHERE status = 'A';
```

 You’ve come to the end of this section. To test your knowledge, consider [taking the quiz](https://web.archive.org/web/20220124172036/https://www.edutracsis.com/sql-functions/).