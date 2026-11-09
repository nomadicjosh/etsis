```
SELECT state FROM address WHERE addressType = 'P' AND endDate = '0000-00-00';
```

|           |
|-----------|
| **State** |
| SC        |
| SC        |
| SC        |
| MA        |

Sometimes the returned results of your select statement may contain duplicate information. The example above demonstrates what happens when querying the address table without the DISTINCT keyword. The address table may contain several rows in which several people are from the same state. Therefore, we must use the DISTINCT keyword in order to suppress duplicates.

```
SELECT DISTINCT state FROM address WHERE addressType = 'P' AND date = '0000-00-00';
```