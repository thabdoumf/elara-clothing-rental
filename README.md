# Élara – Clothing Rental Platform

Élara is a web-based clothing rental platform developed as a Computer Science graduation project at Djillali Liabes University, Algeria.

## Features

- Browse clothing products
- Search and filter products by category, gender and price
- View product details and available sizes
- Register and log in
- Submit and manage rental requests
- View personal rental history
- Admin dashboard for product and rental management
- Product stock and size management

## Technology

- PHP
- MySQL / MariaDB
- HTML, CSS and JavaScript
- Bootstrap (where used in the interface)
- PHPMailer
- XAMPP for local development

## Project structure

- `index.php` – product catalogue
- `product.php` – product details
- `login.php` and `register.php` – authentication
- `rent.php` and `my_rentals.php` – rental workflow
- `dashboard.php` – administration dashboard
- `location_vetement.sql` – database schema
- `uploads/` – product images

## Local setup

1. Install XAMPP and start Apache and MySQL.
2. Create a database named `location_vetement` in phpMyAdmin.
3. Import `location_vetement.sql`.
4. Copy `config.example.php` to `config.local.php`.
5. Update the database and SMTP settings in `config.local.php`.
6. Place the project folder inside XAMPP's `htdocs` directory.
7. Open the project at `http://localhost/licence` (adjust the folder name if needed).

## Security

- `config.local.php` contains private local configuration and must never be committed.
- Use a dedicated database account in production instead of the XAMPP root account.
- Configure SMTP credentials locally. Do not publish app passwords or API keys.

## Academic information

- Project: Élara
- Field: Computer Science
- University: Djillali Liabes University, Sidi Bel Abbès, Algeria
- Academic year: 2025/2026
