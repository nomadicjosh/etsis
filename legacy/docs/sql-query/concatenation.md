A concatenation is the combining of two or more columns, expressions, or literal strings to display combined data in one column. The MySQL function for concatenation is`CONCAT`. For example:

*CONCAT( expression\_1, ‘ ‘, expression\_2 ) where expression\_1 and expression\_2 are either column names, expressions, or literal strings separated by a delimiter. In this case, it is a space.*

 A literal string is a set of characters, numbers, or a date that is not a column name. Any string of text serves as a literal string MUST be enclosed in SINGLE quotes. The literal displays for each row that the query returns. Numeric literals do not have to be in quotes.

```
SELECT CONCAT(fname,' ',lname) AS 'Name' FROM person;
```

The values from the *fname* and *lname* columns display together in one column of output with ‘Name’ as a column heading which is the literal string.

```
[table “” not found /]
```

 This is the end of the third section in this series. Feel free to [take the quiz](https://web.archive.org/web/20220124164614/https://www.edutracsis.com/building-sql-statement/ "Building an SQL Statement") to try out your knowledge.