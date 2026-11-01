# HouseCall

HouseCall is a web and mobile-friendly service delivery booking system that links households with
local service providers (plumbers, gardeners, cleaners, and high school / tertiary tutors) in real time.

Built with PHP, MySQL, JavaScript and CSS in Visual Studio Code, running on XAMPP.

## Roles

* **Client**: searches for service providers, books them, rates the service.
* **Service provider**: sets rates, areas and operating times, confirms bookings, advertises work.
* **Administrator**: manages accounts, tracks service delivery, views reports.

## Setup

1. Copy this folder to `C:\xampp\htdocs\housecall`.
2. Start Apache and MySQL in the XAMPP Control Panel.
3. In phpMyAdmin (`http://localhost/phpmyadmin`) import `database/housecall.sql`.
4. Copy `includes/config.example.php` to `includes/config.php`.
5. Open `http://localhost/housecall/`.

## Progress

- [x] Day 1: project structure, database, layout, home page
- [ ] Day 2: accounts, validation, login security
- [ ] Day 3: booking flow
- [ ] Day 4: administration, reports, backup, testing