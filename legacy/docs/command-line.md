As of release 6.2.10, you can manage your eduTrac SIS (etSIS) installation by command line. As etSIS continues to grow, some processes will be too intensive to run via a particular screen. The commands and subcommands list is short at the moment, but more and more commands/subcommands will be adde in the future.

**Note:** Windows users will need to install [Cygwin](https://cygwin.com/index.html) to give them Linux type functionality via the command line.

Before your can use the following commands and subcommands, create an ssh session to your server, and run the following two commands:

```
$ chmod +x etsis

$ chmod +x phinx
```

## Flags

|                         |                                                            |
|-------------------------|------------------------------------------------------------|
| **Parameters**          | **Description**                                            |
| – -require=&lt;path&gt; | Load a PHP file before running a command.                  |
| – -path=&lt;path&gt;    | Path to eduTrac SIS files.                                 |
| – -dir=&lt;path&gt;     | Set the path to locate file or where file should be saved. |
| – -verbose, – -v        | Turn on verbose output.                                    |
| – -quiet, – -q          | Disable all output.                                        |

## core Command

The core command allows you to install, update, and manage the etSIS system.

### migrate

The migrate subcommand will run database migrations, create config file, and update with database credentials. This command can also be used for database update after upgrading to a new etSIS release. For better clarity, this will be renamed to migrate in the near future. If this is your first time using the new migration tool, you must first rename *phinx.sample.php* to *phinx.php* and then edit *phinx.php* with your database details. It should look similar to this sample:

```
defined('DB_HOST') or define('DB_HOST', 'localhost'); // Usually localhost or 127.0.0.1.
defined('DB_NAME') or define('DB_NAME', ''); // Database name.
defined('DB_USER') or define('DB_USER', ''); // Database username.
defined('DB_PASS') or define('DB_PASS', ''); // Database password.
defined('DB_PORT') or define('DB_PORT', 3306); // Database port.
```

After updating the file with your database credentials, run the following command for install and upgrades.

```
$ ./etsis core migrate
```

### rollback

The rollback subcommand will undo a previous migration.

```
$ ./etsis core rollback
```

### status

The status subcommand will display all previous migrations.

```
$ ./etsis core status
```

### check\_update

The check\_update subcommand will check your system against the api to determine if you are using the latest release or not.

```
$ ./etsis core check_update
```

### update

The update subcommand will update your system to the latest update. After running this command, you should run the [*migrate*](https://web.archive.org/web/20210623200832/https://www.edutracsis.com/kb/command-line/#migrate) command to see if your database needs updating.

```
$ ./etsis core update
```

## cli Command

The cli command returns information about the ETSIS-CLI script.

### version

The version subcommand displays the current version of ETSIS-CLI.

```
$ ./etsis cli version
```

### release

The release subcommand returns the current release information of the etSIS installation.

```
$ ./etsis cli release
```

### info

The info subcommand returns some information about your server environment.

```
$ ./etsis cli info
```

## db Command

The db command allows you perform basic database operations.

### backup

The backup subcommand lets you create a backup of your database. Notice that in the example below, you can add the *–dir=* argument to save the backup to a directory you choose. If you use this argument, make sure not to save it some place where it is downloadable via the web.

Also, you can use the *–no-data* argument if you only want to dump the database structure or use the *–no-create-info* option if you only want to dump the data.

```
#Create a backup and saves file in the root directory.
$ ./etsis db backup
Starting export process...
Writing to file "2016-09-211474420191_etsis-dump.sql"
Success: Database export is complete.

#Create a backup and saves file in a directory of your choosing.
$ ./etsis db backup --dir=app/tmp
Starting export process...
Writing to file "app/tmp/2016-09-211474420191_etsis-dump.sql"
Success: Database export is complete.

#Create a dump of the database structure only. (Added in release 6.3.0)
$ ./etsis db backup --dir=app/tmp --no-data
Starting export process...
Writing to file "app/tmp/2016-09-211474420191_etsis-dump.sql"
Success: Database export is complete.

#Create a dump of the data only. (Added in release 6.3.0)
$ ./etsis db backup --dir=app/tmp --no-create-info
Starting export process...
Writing to file "app/tmp/2016-09-211474420191_etsis-dump.sql"
Success: Database export is complete.
```

### optimize

The optimize subcommand allows you to optimize the etSIS database.

```
$ ./etsis db optimize
Starting optimization process...
 Table: acad_year 100% [=====================================================] 0:01 / 0:03
 Table: activity_log 100% [==================================================] 0:01 / 0:06
 Table: address 100% [=======================================================] 0:01 / 0:10
 .........................................................................................
Database optimization complete.
```

### tables

The tables subcommand allows you to print a list of the tables in the etSIS database.

```
$ ./etsis db tables
 Table: acad_year
 Table: activity_log
 Table: address
 .........................................................................................
Database table list complete.
```