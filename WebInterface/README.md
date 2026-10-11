# DMV Web Interface

Three PHP pages that register a car to an owner in the DMV database.

| File | Table | On success |
|------|-------|------------|
| persons.php | Persons | redirects to cars.php |
| cars.php | Cars | redirects to title.php |
| title.php | Title | shows a success message |

Each page connects to `localhost` as user `jetson` / password `orin`, database `DMV`.
License, state, and VIN are carried forward in the URL so the later pages are prefilled.

Submit only the three .php files.

## Test locally

`setup_dmv.sql` recreates the schema if you want to try the pages on your own MySQL/MariaDB.
Put the three .php files in a web server's document root (Apache + PHP, or `php -S localhost:8080`)
and open persons.php in a browser.
