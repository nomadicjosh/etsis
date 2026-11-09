## Introduction

By filtering and using selection criteria, you can leverage the relationships within a relational database to limit your results. The queries we’ve been using until now have been retrieving all rows from the database. However, in most situations, you want your queries to return a subset of information instead of the enter table.

The WHERE clause can be added to the SQL statement to narrow the rows returned by the query.

The WHERE clause:

1. Allows the definition of filtering and row selection criteria.
2. Limits the query results based on the criteria you specify.

### Example

```
SELECT fname, lname FROM person WHERE ssn = '0';
```

## Operators

The WHERE clause uses a number of standard mathematical comparisons to filter rows:

1. = equal to
2. &gt; greater than
3. &gt;= greater than or equal to
4. &lt; less than
5. &lt;= less than or equal to
6. &lt;&gt; not equal to

Let’s say that you need a list of people who were born after March 20, 1977. The query below would give you the results you need:

```
SELECT fname, lname FROM person WHERE dob > '1977-03-20';
```

### BETWEEN

When you want to do a comparison, the BETWEEN operator is very useful when looking for numbers or dates falling within a certain range.

For example, let’s say we wanted to find a list of people who were born in the year 1977. The query below would yield those results:

```
SELECT fname, lname, dob
FROM person
WHERE dob BETWEEN '1977-01-01' AND
'1977-12-31';
```

### NOT

We can use the NOT keyword to return the opposite of the query below by adding the keyword before the BETWEEN keyword. The following query will yield the opposite of above:

```
SELECT fname, lname, dob
FROM person
WHERE dob NOT BETWEEN '1977-01-01' AND
'1977-12-31';
```

### LIKE

When querying the database for information, sometimes you might not know what you are looking for or you may not know how to spell a person’s last name but you know the first three letters of the last name.

The wildcard ‘%‘ stands for any number of characters including no character. Searching for ‘James%’ could retrieve ‘James’ or ‘Jameson’. This is useful when comparing text strings for similarities. The keyword LIKE must be used in conjunction with a wild card.

#### Example

```
SELECT fname, lname, dob FROM person WHERE fname LIKE 'James%';
```

### NULL

NULL values represent a null or empty value in a database column. Sometimes a column may contain zero (0) which is not the same as NULL. Zero is not an empty value. Another option for checking for NULL values is an empty string (”).

For example, let’s say we want to find all the records in the ‘person’ table that has NULL values for ethnicity. The following query would give us the results we need:

```
SELECT fname, lname FROM person WHERE ethnicity = '';
```

As another example, let’s retrieve results from the ‘attendance’ table where the ‘status’ column is null.

```
SELECT * FROM attendance WHERE status IS NULL;
```

As you probably noticed, the first query and the second query is different. The reason being is that the \`ethnicity\` column in the \`person\` table is not setup for NULL value, however, the column could be empty. The \`status\` column in the \`attendance\` table is setup for default NULL values. To check if a column is setup to default NULL values if empty, use the ‘explain’ command before the table name. ‘explain attendance would yield the following results in the screenshot below:

[![](data:image/gif;base64,R0lGODdhAQABAPAAAP///wAAACwAAAAAAQABAEACAkQBADs=)](https://web.archive.org/web/20220124172228/https://www.edutracsis.com/wp-content/uploads/2013/10/explain-command.png)

Check out the Null column in the screenshot above. There are only two that are set at default NULL values, and that is \`date\` and \`status\`.

Complex Criteria

With the combination of what we have learned so far about filtering data, we can use those concepts to create complex criteria conditions. Use logical connectors AND and OR to link the filtering/selection criteria. Multiple conditions can be specified using AND/OR keywords.

1. If the ‘AND’ keyword is used, then both conditions must be true.
2. If the ‘OR’ keyword is used, then either condition must be true.

For example, let’s run a report where persons have both a social security number and date of birth:

```
SELECT fname, lname, ssn, dob FROM person WHERE ssn > '0' AND dob <> '0000-00-00';
```

The above query will return a list of people who have a valid social \`AND\` a valid date of birth.

When multiple conditions are specified in a \`WHERE\` clause, the following order of precedence is used:

1. Parenthetical expressions
2. Arithmetic operators
3. Concatenation operators
4. Comparison operators
5. IS \[NOT] NULL, LIKE, \[NOT] IN
6. \[NOT] BETWEEN
7. NOT logical condition
8. AND logical condition
9. OR logical condition

 When a WHERE clause uses multiple conditions, it is better to use parentheses to impose the order of precedence rather than to relying on the default order of precedence.

 Now that you have completed this section, you can proceed to [take the quiz](https://web.archive.org/web/20220124172228/https://www.edutracsis.com/filtering-criteria/ "Filtering Criteria").