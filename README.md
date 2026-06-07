# AIMS — Automated Inventory Management System

Full-stack enterprise inventory platform built with Laravel 12, React 19, MySQL, Redis, and WebSocket-based real-time updates.

## Tech Stack

| Layer | Technology |
|-------|------------|
| Backend | Laravel 12 REST API |
| Frontend | React 19 + Vite + Tailwind CSS |
| Database | MySQL 8+ (26 relational tables) |
| Cache/Queue | Redis |
| Real-time | Laravel Reverb + Laravel Echo (WebSockets) |
| State | Zustand |
| Routing | React Router 7 |
| PDF | DomPDF |
| Testing | PHPUnit |

## Architecture

Layered architecture with clear separation of concerns:

```text
Controllers  →  request validation and HTTP responses only
Services     →  business logic, transactions, broadcasting
Repositories →  database access and queries
Models       →  Eloquent entities with relationships
```

## Requirements Compliance (Lab 2)

| Area | Status | Notes |
|------|--------|-------|
| Stack #7 Laravel + React + Vite + MySQL | PASS | Full project uses this stack |
| NoSQL (Redis/MongoDB) | PARTIAL | Redis supported via `CACHE_STORE=redis`; file cache fallback for local dev |
| 24+ relational tables (3NF) | PASS | 26+ tables with FKs, indexes, audit columns — see [docs/erd.md](docs/erd.md) |
| Mandatory system tables | PASS | `users`, `roles`, `user_roles`, `permissions`, `role_permissions`, `refresh_tokens`, `audit_logs`, `notifications`, `settings`, `files` |
| Layered architecture | PARTIAL | Controllers → Services → Repositories on core modules; some controllers still thin-ORM |
| JWT access + refresh tokens | PASS | `JwtService`, `POST /api/refresh`, frontend auto-refresh on 401 |
| Auth, RBAC, validation, CORS, `.env` | PASS | Role middleware, input validation, hashed passwords |
| State management + lazy loading | PASS | Zustand + route/component lazy loading |
| Real-time (WebSockets) | PASS | Laravel Reverb + Echo; live notifications and dashboard refresh |
| API docs + ERD + README | PASS | OpenAPI, Postman collection, ERD diagram |
| CMS (static landing content) | PASS | Admin edits predefined landing blocks only; homepage reads `/api/cms/published` |
| Advanced features (min. 3) | PASS | Search, import/export, reports, payments, PDF invoices, barcode scanner |

### Advanced Features Implemented
1. **Advanced Search** — global search across products, categories, suppliers, invoices, stock
2. **Import/Export** — CSV import and export for products and stock movements
3. **Dynamic Reports** — category, supplier, and stock summary reports with export
4. **CMS** — non-technical admin edits landing page text (hero, features, about) without touching business data
5. **Online Payments** — invoice payment processing with `payment_transactions` records

### Real-Time
WebSocket events via Laravel Reverb for stock updates, low-stock alerts, live notifications, and dashboard refresh. No polling.

### Presentation Checklist (outside codebase)
- Git: individual commits and invite `elton.boshnjaku@ubt-uni.net`
- Project management: Jira / Trello / GitHub Projects with To Do / In Progress / Done

## Project Structure

```text
backend/
  app/Http/Controllers/Api/   Thin controllers
  app/Services/               Business logic
  app/Repositories/           Data access layer
  app/Events/                 Broadcast events
  database/migrations/        26-table schema
  routes/api.php              API routes
  tests/Feature/              Integration tests

frontend/
  src/pages/                  Route-based lazy-loaded pages
  src/components/             Reusable UI (lazy-loaded where heavy)
  src/store/                  Zustand state management
  src/api/                    API client modules
  src/lib/echo.js             WebSocket client

docs/
  erd.md                      Database diagram
  openapi.yaml                API specification
  postman_collection.json     Postman import file
```

## Setup

### Prerequisites
- PHP 8.2+, Composer
- Node.js 18+, pnpm
- MySQL 8+
- Redis (recommended for cache and broadcasting)

### Database

```sql
CREATE DATABASE inventory_system;
```

### Backend

```bash
cd backend
copy .env.example .env
composer install
php artisan key:generate
php artisan migrate --seed
php artisan serve
```

API: `http://localhost:8000`

### Real-Time Server

In a separate terminal:

```bash
cd backend
php artisan reverb:start
```

### Frontend

```bash
cd frontend
pnpm install
pnpm run dev
```

UI: `http://localhost:5173`

## Environment Variables

```env
DB_CONNECTION=mysql
DB_DATABASE=inventory_system
DB_USERNAME=root
DB_PASSWORD=

CACHE_STORE=redis
REDIS_HOST=127.0.0.1

BROADCAST_CONNECTION=reverb
REVERB_APP_ID=aims-app
REVERB_APP_KEY=aims-key
REVERB_APP_SECRET=aims-secret
REVERB_HOST=127.0.0.1
REVERB_PORT=8080
```

Frontend `.env` (optional, defaults provided):

```env
VITE_REVERB_APP_KEY=aims-key
VITE_REVERB_HOST=127.0.0.1
VITE_REVERB_PORT=8080
VITE_REVERB_SCHEME=http
```

## Default Accounts

| Email | Password | Role |
|-------|----------|------|
| admin@enterprise.com | password | admin |
| manager@enterprise.com | password | manager |
| staff@enterprise.com | password | staff |

## API Documentation

- OpenAPI spec: [docs/openapi.yaml](docs/openapi.yaml)
- Postman collection: [docs/postman_collection.json](docs/postman_collection.json)
- Import the Postman collection and set `baseUrl` to `http://localhost:8000/api`

## Commands

```bash
# Run tests
cd backend && php artisan test

# Production frontend build
cd frontend && pnpm run build

# Start all backend services (API + queue + logs + Vite)
cd backend && composer run dev
```

## CSV Import Format

Products CSV columns: `name, sku, category, quantity, min_quantity, price, unit`

Example:

```csv
name,sku,category,quantity,min_quantity,price,unit
Wireless Mouse,WM-001,Electronics,50,10,19.99,pcs
```
