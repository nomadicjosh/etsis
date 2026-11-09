There are two types of SQL statements:

1. DML (Data Manipulation Language)
2. DDL (Data Definition Language)

For generation SQL reports, we will be focusing on DML statements. We will first look at two keywords that make up a SQL statement:`SELECT`and`FROM`.

1. SELECT – A keyword that specifies the data that will be the output of the query.
2. FROM – A keyword that indicates the table from which to extract the data.

### FROM Keyword

```
SELECT ssn FROM person;
```

TheFROM clause is always evaluated first even though it follows theSELECT cause.  The table name follows the keywordFROM. So from the example above, the name of the table person follows the keywordFROM.

### SELECT Keyword

```
SELECT ssn, lname, fname FROM person;
```

1. Column names follow theSELECT keyword and proceeds theFROM keyword.
2. Multiple columns can be listed but should be separated by a comma.
3. All columns listed must come from the table listed in theFROM clause.

In most cases, select statements lists all columns by using the ‘\*’ as a wildcard instead of listing column names. In more advanced cases, they can include calculations and other expressions.

### SQL Evaluation Process

As mentioned above, the FROM clause in a select statement if evaluated first. However, that is just one part of the equation. The list below is in the order of how a select statement is evaluated.

1. FROM clause
2. Selection criteria
3. SELECT clause

> FROM clause – The portion of a select statement that defines the table on which to query.
> 
> Selection criteria – The portion of the select statement that defines the characteristics of the rows to select from the table.
> 
> SELECT clause – The portion of the select statement that define which columns to output with the SQL statement result.