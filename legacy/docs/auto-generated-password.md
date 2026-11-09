User passwords are auto generated when the administrator creates a new person record. Their is a hierarchy for creating user passwords and that hierarchy is specified below. This is also true when an administrator resets a person’s password. In either case, the password will be suffixed with *etSIS\**. **Please note that usernames and passwords are case sensitive.**

1. If ssn number is not null, then this will be the new user’s password. With the suffixed added, the password will end up being *234752947etSIS\**.
2. Else if date of birth is not null, then this will be the users password without dashes in the following format (YYYYMMDD). With the suffix added, the password will end up being *19770420etSIS\**.
3. Else, if the first two are null, then the default password will be \`myaccount\`. With the suffixed added, the password will end up being *myaccountetSIS\**.

## Importing

If you are using [person.csv](https://web.archive.org/web/20210726191846/https://www.edutracsis.com/kb/csv-templates/) to import person records, you need to make sure that the passwords are [hashed](https://web.archive.org/web/20210726191846/https://www.edutracsis.com/api/1.2/pass/) and not plaintext.