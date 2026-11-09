A person in the system could have several different roles. A particular person could have a student, staff and faculty role. When assigning roles to a person, you should assign them the highest role.

Nevertheless, sometimes a mistake is made when a student with only the student role is assigned the wrong person type. In order to find students with wrong person type, use the following query.

```
SELECT a.stuID, b.fname, b.lname, b.personType

FROM student a

LEFT JOIN person b

ON a.stuID = b.personID

WHERE personType != 'STU';
```