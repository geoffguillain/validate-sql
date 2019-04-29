# SQL Import Validation

WP CLI command to validate and report common failures that come from bad or not cleaned SQL dumps.

## How to use
```
$ wp validate-sql -—file=mysqlfile.sql
```

## Reports

The WP CLI command will provide a report with success, warnings and actions to take before importing the sql file.

e.g.
```
$ wp validate-sql --file=sql-error.sql

Checking for table prefix...
Warning: We have found some DROP TABLE statements with a custom prefix.
	 xyz_commentmeta 

Warning: We have found some CREATE TABLE statements with a custom prefix.
	 xyz_commentmeta 

Checking if all CREATE TABLE statements have a matching DROP TABLE statement...
Success: We have found a matching DROP TABLE statement for each table.

Checking for charset...
Success: We have found some UTF8MB4 charsets
Warning: We have found some latin1 or UTF8 charsets that should be converted to UTF8MB4
Warning: We have found some custom charset, please check your SQL file

Validating WP core DROP TABLE statements...
Warning: Missing core drop statement: 
	 wp_commentmeta 
	                
Validating WP core CREATE TABLE statements...
Warning: Missing core create statement: 
	 wp_commentmeta 

Checking for CREATE or DROP DATABASE statements...
Warning: We have found some unwanted statemnents: 
	 CREATE DATABASE databasename; 
	 DROP DATABASE databasename;   

Checking for siteurl and home options...
We have found 2 entries.
+-------------+--------------------------+
| option_name | option_value             |
+-------------+--------------------------+
| siteurl     | http://dev.local/tradmed |
| home        | http://dev.local/tradmed |
+-------------+--------------------------+
```

## TO DOs
- Give the opportunity to provide multiple sql files at once for one dump
- Migrate the tool to the vip-go SQL import command