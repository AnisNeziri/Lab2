# Inventory System

Web application for managing inventory, built with Laravel (API), React (frontend), and MySQL.

## Project structure

```
backend/    Laravel REST API
frontend/   React + Vite UI
```

## Requirements

- PHP 8.2+
- Composer
- Node.js 18+
- MySQL 8+ (XAMPP or standalone)

## Setup

### 1. Database

Create a MySQL database:

```sql
CREATE DATABASE inventory_system;
```

### 2. Backend

```bash
cd backend
cp .env.example .env   # Windows: copy .env.example .env
composer install
php artisan key:generate
```

Update `.env` with your MySQL credentials, then:

```bash
php artisan migrate
php artisan serve
```

API runs at `http://localhost:8000`.

### 3. Frontend

```bash
cd frontend
npm install
npm run dev
```

UI runs at `http://localhost:5173`.

## Team

Collaborative university project.
