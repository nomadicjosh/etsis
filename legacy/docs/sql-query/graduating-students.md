When graduating students, you will need to enter a student’s id/name or create a saved query that will grab the ID’s of students who are eligible to graduate (graduation flag set to 1 via SACP). The eligible to graduate flag is found in the student program table since students graduate from programs and could graduate from multiple programs from the same institution. Below is the query you will need to run during graduation time.

**Release 6.3.x and above**

```
SELECT * FROM sacp WHERE eligible_to_graduate = '1' AND currStatus = 'A';
```

**Release 6.2x and below**

```
SELECT stuID FROM stu_program WHERE eligible_to_graduate = '1' AND currStatus = 'A';
```