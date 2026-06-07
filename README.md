# AIMS — Automated Inventory Management System

> Full-stack enterprise inventory platform — Lab Course 2, Stack #7  
> University for Business and Technology (UBT) · Academic Year 2025/2026

---

## Tech Stack

| Layer | Technology |
|---|---|
| Backend | Laravel 12 (PHP 8.2) |
| Frontend | React 19 + Vite 6 |
| Styling | Tailwind CSS 3.4 + Custom CSS |
| Database (SQL) | MySQL 8+ — 31 relational tables |
| Database (NoSQL) | Redis — Hash, Sorted Set, Set |
| Real-time | Laravel Reverb + Laravel Echo (WebSockets) |
| State Management | Zustand |
| Routing | React Router 7 |
| 3D Visualization | Three.js + @react-three/fiber |
| Charts | Recharts |
| PDF | DomPDF (barryvdh/laravel-dompdf) |
| Auth | JWT — access token + refresh token |

---

## Architecture

Layered architecture with strict separation of concerns:

```
HTTP Request
    ↓
Controller       — validates input, returns HTTP response (no business logic)
    ↓
Service          — all business logic, transactions, event broadcasting
    ↓
Repository       — database queries, isolated from business logic
    ↓
Eloquent Model   — relationships, casts, accessors
```

Multi-tenancy: every record has `company_id`. The `BelongsToCompany` trait automatically scopes all queries to the authenticated user's company.

---

## Lab 2 checklist

| Area | Status |
|------|--------|
| Laravel + React + Vite + MySQL | Done |
| Redis (NoSQL) | Done when Redis is running; app falls back if not |
| 24+ DB tables, mandatory 10 system tables | Done — see `docs/erd.md` |
| Controllers / Services / Repositories | Done on main modules |
| JWT + refresh tokens, RBAC, validation | Done |
| WebSockets (Reverb + Echo) | Done — run `php artisan reverb:start` |
| Zustand, lazy routes, Tailwind | Done |
| Advanced search (5 lists) | products, categories, suppliers, invoices, stock movements |
| Export / import (5 lists, CSV/JSON/Excel) | Reports page + `/api/export/{list}` |
| CMS (landing text only) | Site Content admin page |
| Dynamic reports | Reports page |
| Payments | Simulated gateway (local transaction records) |
| Git + docs | README, OpenAPI, Postman, ERD |

Team tasks (not in code): invite `elton.boshnjaku@ubt-uni.net`, keep GitHub Projects board updated, make sure every member has their own commits.

---

## Database Tables (31 total)

### Mandatory System Tables (10)
| Table | Purpose |
|---|---|
| `users` | User accounts with hashed passwords |
| `roles` | Admin, Manager, User role definitions |
| `user_roles` | Many-to-many: users ↔ roles |
| `permissions` | Granular access permissions |
| `role_permissions` | Many-to-many: roles ↔ permissions |
| `refresh_tokens` | JWT refresh token storage and revocation |
| `audit_logs` | Full audit trail of every critical action |
| `notifications` | In-app notifications per user |
| `settings` | Global system configuration key-value pairs |
| `files` | File metadata for uploads and documents |

### Domain Tables (21)
`companies`, `products`, `categories`, `suppliers`, `stock_movements`, `invoices`, `invoice_items`, `payment_transactions`, `warehouses`, `warehouse_stock`, `cms_pages`, `purchase_orders`, `import_logs`, `sessions`, `cache`, `jobs`, `failed_jobs`, `job_batches`, `password_reset_tokens`, `migrations`, `cache_locks`

---

## Real-Time Communication

Implemented via **Laravel Reverb** (WebSocket server) + **Laravel Echo** (frontend client).

| Event | Channel | Trigger |
|---|---|---|
| `StockUpdated` | `private-company.{id}` | Stock movement recorded |
| `LowStockDetected` | `private-company.{id}` | Product falls below min quantity |

Frontend listens and updates Dashboard, 3D Map, and Notification Center live — **no polling**.

---

## Redis NoSQL Usage

| Structure | Key | Data | TTL |
|---|---|---|---|
| Hash | `dashboard_stats:{companyId}` | KPI counters | 5 min |
| Sorted Set | `activity_feed:{companyId}` | Last 100 user actions | No TTL |
| Set | `low_stock_alerts:{companyId}` | Low-stock product IDs | 1 hour |

Graceful degradation: if Redis is unavailable, the system falls back to MySQL queries automatically.

---

## Additional Features Implemented

### 1. Advanced Search
Global search across products, categories, suppliers, invoices, and stock movements. Supports full-text, SKU, barcode, and filter-based queries via `SearchController`.

### 2. Content Management System (CMS)
Admin edits predefined static landing page blocks (hero title, slogan, about text) without touching business data or writing code. Frontend reads from `/api/cms/published`.

### 3. Data Export & Import
- **Export**: Products and stock movements to CSV
- **Import**: Bulk product import via CSV with validation and error reporting
- Covers 5+ data lists across the application

### 4. Dynamic Report Generation
Date-range reports for stock movements, category summaries, and supplier performance. Reports can be filtered and exported.

### 5. Invoice Payment Tracking
Multi-payment support per invoice. Records partial payments with notes, recalculates remaining balance, and transitions status automatically: `unpaid → partially_paid → paid`.

### 6. 3D Warehouse Digital Twin
Interactive Three.js 3D map showing 4 zones (16 shelves), live stock levels mapped to box density on shelves, heatmap mode, forklift/truck/AGV vehicles, and real-time WebSocket updates.

---

## Prerequisites

- PHP 8.2+
- Composer 2+
- Node.js 18+ and pnpm (`npm i -g pnpm`)
- MySQL 8+
- Redis (Windows: [Memurai](https://www.memurai.com/) or WSL)
- XAMPP / Laragon (optional, for local MySQL)

---

## Installation & Setup

### 1. Clone the repository

```bash
git clone https://github.com/AnisNeziri/Lab2.git
cd Lab2
```

### 2. Create the database

```sql
CREATE DATABASE inventory_system CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

### 3. Backend setup

```bash
cd backend
cp .env.example .env          # Windows: copy .env.example .env
composer install
php artisan key:generate
```

Edit `.env` with your database credentials (see Environment Variables section below), then:

```bash
php artisan migrate --seed
php artisan serve
```

Backend API runs at: `http://localhost:8000`

### 4. WebSocket server (separate terminal)

```bash
cd backend
php artisan reverb:start
```

Reverb WebSocket server runs at: `ws://localhost:8080`

### 5. Frontend setup

```bash
cd frontend
pnpm install
pnpm run dev
```

Frontend runs at: `http://localhost:5173`

---

## Environment Variables

### Backend (`backend/.env`)

```env
APP_NAME="AIMS Inventory"
APP_ENV=local
APP_KEY=                          # generated by php artisan key:generate
APP_URL=http://localhost:8000

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=inventory_system
DB_USERNAME=root
DB_PASSWORD=

CACHE_STORE=redis
REDIS_HOST=127.0.0.1
REDIS_PORT=6379
REDIS_PASSWORD=null

BROADCAST_CONNECTION=reverb
REVERB_APP_ID=aims-app
REVERB_APP_KEY=aims-key
REVERB_APP_SECRET=aims-secret
REVERB_HOST=127.0.0.1
REVERB_PORT=8080
REVERB_SCHEME=http
```

### Frontend (`frontend/.env`)

```env
VITE_API_URL=http://localhost:8000
VITE_REVERB_APP_KEY=aims-key
VITE_REVERB_HOST=127.0.0.1
VITE_REVERB_PORT=8080
VITE_REVERB_SCHEME=http
```

---

## Default Login Accounts

| Email | Password | Role |
|---|---|---|
| `admin@enterprise.com` | `password` | Admin |
| `manager@enterprise.com` | `password` | Manager |
| `staff@enterprise.com` | `password` | User |

---

## API Endpoints (52 routes)

| Method | Endpoint | Description |
|---|---|---|
| POST | `/api/login` | Authenticate and receive JWT tokens |
| POST | `/api/register` | Register new company + admin user |
| POST | `/api/refresh` | Refresh expired access token |
| POST | `/api/logout` | Revoke current token |
| GET | `/api/dashboard` | KPI stats (cached in Redis) |
| GET | `/api/dashboard/activity-feed` | Live activity feed from Redis |
| GET | `/api/dashboard/low-stock-alerts` | Low-stock alerts from Redis |
| GET | `/api/products` | Paginated product list with filters |
| POST | `/api/products` | Create product |
| PUT | `/api/products/{id}` | Update product |
| DELETE | `/api/products/{id}` | Delete product (admin/manager) |
| GET | `/api/products/export` | Export products to CSV |
| POST | `/api/products/import` | Import products from CSV |
| GET | `/api/shelves/{locationCode}/products` | Products at a specific shelf (3D Map) |
| GET | `/api/stock-movements` | Stock movement history |
| POST | `/api/stock-movements` | Record stock in/out |
| GET | `/api/stock-movements/export` | Export movements to CSV |
| GET | `/api/categories` | List categories |
| GET | `/api/suppliers` | List suppliers |
| GET | `/api/invoices` | List invoices |
| POST | `/api/invoices` | Create invoice with line items |
| GET | `/api/invoices/{id}` | Invoice detail with items + payments |
| GET | `/api/invoices/{id}/pdf` | Download invoice as PDF |
| GET | `/api/payments` | List payment transactions |
| POST | `/api/payments` | Record payment against invoice |
| GET | `/api/reports` | Dynamic reports |
| GET | `/api/search` | Global full-text search |
| GET | `/api/notifications` | User notifications |
| GET | `/api/users` | User management (admin only) |
| GET | `/api/activity-logs` | Audit log (admin only) |
| GET/PUT | `/api/cms/{slug}` | CMS page content (admin only) |

Full Postman collection: `docs/postman_collection.json`

---

## CSV Import Format

### Products

```csv
name,sku,category,quantity,min_quantity,price,unit
Wireless Mouse,WM-001,Electronics,50,10,19.99,pcs
USB-C Hub,UC-002,Electronics,30,5,34.50,pcs
```

### Stock Movements

```csv
sku,type,quantity,note
WM-001,in,100,Restocked from supplier
UC-002,out,5,Sold to client
```

---

## Role Permissions

| Feature | Admin | Manager | User |
|---|---|---|---|
| Dashboard, Products, Stock, Invoices | Yes | Yes | Yes |
| 3D Warehouse Map | Yes | Yes | Yes |
| Reports, Search, Categories, Suppliers | Yes | Yes | Yes |
| Create/Edit Products & Invoices | Yes | Yes | Yes |
| Delete Products/Categories/Suppliers | Yes | Yes | No |
| User Management | Yes | No | No |
| Activity Logs | Yes | No | No |
| CMS (Site Content) | Yes | No | No |

---

## Useful Commands

```bash
# Run backend tests
cd backend && php artisan test

# Re-seed database (reset all data)
cd backend && php artisan migrate:fresh --seed

# Production frontend build
cd frontend && pnpm run build

# Clear all Laravel caches
cd backend && php artisan optimize:clear
```

---

## Project Structure

```
Lab2/
├── backend/
│   ├── app/
│   │   ├── Http/Controllers/Api/   # Thin controllers (52 routes)
│   │   ├── Services/               # Business logic layer
│   │   ├── Repositories/           # Data access layer
│   │   │   ├── Contracts/          # Interfaces
│   │   │   └── Eloquent/           # MySQL implementations
│   │   ├── Models/                 # Eloquent models
│   │   ├── Events/                 # WebSocket broadcast events
│   │   └── Services/Redis/         # Redis NoSQL service
│   ├── database/
│   │   ├── migrations/             # 26+ migration files
│   │   └── seeders/                # Demo data seeders
│   └── routes/api.php              # All API route definitions
│
└── frontend/
    └── src/
        ├── pages/                  # Route-based lazy-loaded pages
        ├── components/             # Reusable UI components
        ├── store/                  # Zustand global state
        ├── api/                    # API client modules
        └── lib/echo.js             # WebSocket (Echo) client
```

---

## Team

UBT — Computer Science and Engineering  
Lab Course 2 · Stack #7 · Academic Year 2025/2026

> Repository invite for professor: **elton.boshnjaku@ubt-uni.net**
