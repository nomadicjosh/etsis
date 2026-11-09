Student load is automatically created and calculated for every student. You can retrieve a student’s term load by running the following query:

**Release 6.3.x and above**

```
SELECT sttr.stuLoad, sttr.termCode, person.fname, person.lname
FROM sttr
LEFT JOIN person
ON sttr.stuID = person.personID
WHERE sttr.termCode = '2013/FA';
```