@echo off
cd /d C:\xampp\htdocs\side-house
start "" cmd /c "php artisan serve"
timeout /t 2 /nobreak > nul
start http://127.0.0.1:8000/login