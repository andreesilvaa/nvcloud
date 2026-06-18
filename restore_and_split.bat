@echo off
cd C:\laragon\www\nvcloud
echo Restoring original app.php from git...
git show fb54727:app.php > app.php
echo Lines:
php -r "echo substr_count(file_get_contents('app.php'), PHP_EOL) . ' lines';"
echo.
echo Running split_pages.php...
php split_pages.php
echo Done.
