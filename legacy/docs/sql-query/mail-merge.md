The mail merge feature allows you to use a `saved query` to generate mailing labels using Avery templates. In order to create a saved query, your fields must be listed in the following order (fname,lname,address1,address2,city,state,zip).

So, let’s say you wanted to generate mailing labels for prospective students who have just been accepted to your institution. Your saved query would look something like the following example:

```
SELECT a.lname,a.fname,c.address1,c.address2,c.city,c.state,c.zip
FROM person a
LEFT JOIN application b ON a.personID = b.personID
LEFT JOIN address c ON a.personID = c.personID
WHERE b.applStatus = 'Accepted'
AND b.applDate BETWEEN '2012-07-02' AND '2012-09-01'
```