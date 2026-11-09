Below is an example of using *CASE* to Translate Cryptic Values into Meaningful Descriptions.

```
SELECT 

CASE currStatus 

WHEN 'A' THEN 'Active' 

WHEN 'I' THEN 'Inactive' 

WHEN 'P' THEN 'Pending' 

ELSE 'Obsolete' 

END AS 'Status',

courseSecCode AS 'Course Sec Code', 

secShortTitle AS 'Title' 

FROM course_sec;
```