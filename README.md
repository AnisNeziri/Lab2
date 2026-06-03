# Inventory System

Inventory System is a full-stack web application for managing products, categories, stock adjustments, low-stock alerts, and inventory reporting.

## Tech Stack

- Backend: Laravel 12 REST API
- Frontend: React 19 with Vite
- Database: MySQL
- Testing: PHPUnit feature tests

## Project Structure

```text
backend/    Laravel API, migrations, seeders, tests
frontend/   React + Vite user interface
```

## Requirements

- PHP 8.2+
- Composer
- Node.js 18+
- MySQL 8+ or XAMPP with MySQL enabled

## Setup

Create the database first:

```sql
CREATE DATABASE inventory_system;
```

Install and start the backend:

```bash
cd backend
copy .env.example .env
composer install
php artisan key:generate
php artisan migrate --seed
php artisan serve
```

The API runs on `http://localhost:8000`.

Install and start the frontend in a second terminal:

```bash
cd frontend
npm install
npm run dev
```

The UI runs on `http://localhost:5173`.

## Environment

For a default XAMPP setup, keep these database values in `backend/.env`:

```env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=inventory_system
DB_USERNAME=root
DB_PASSWORD=
```

## Features

- Product CRUD with category assignment
- Category CRUD with delete protection when products exist
- Stock in/out movements with quantity validation
- Dashboard totals and low-stock alerts
- Category and stock value reports
- Product search, filtering, sorting, pagination, and CSV export

## Useful Commands

```bash
# Backend tests
cd backend
php artisan test

# Frontend production build
cd frontend
npm run build
```
