# Database ERD

AIMS uses a multi-tenant MySQL schema with 26+ tables in 3NF.

```mermaid
erDiagram
    companies ||--o{ users : has
    companies ||--o{ categories : has
    companies ||--o{ suppliers : has
    companies ||--o{ products : has
    companies ||--o{ stock_movements : has
    companies ||--o{ invoices : has
    companies ||--o{ audit_logs : has
    companies ||--o{ notifications : has
    companies ||--o{ payment_transactions : has
    companies ||--o{ cms_pages : has
    companies ||--o{ import_logs : has
    companies ||--o{ warehouses : has
    companies ||--o{ purchase_orders : has

    users ||--o{ user_roles : assigned
    roles ||--o{ user_roles : includes
    roles ||--o{ role_permissions : grants
    permissions ||--o{ role_permissions : assigned
    users ||--o{ refresh_tokens : owns
    users ||--o{ files : uploads

    categories ||--o{ products : categorizes
    suppliers ||--o{ products : supplies
    suppliers ||--o{ purchase_orders : fulfills

    products ||--o{ stock_movements : tracks
    products ||--o{ invoice_items : contains
    products ||--o{ warehouse_stock : stores

    invoices ||--o{ invoice_items : includes
    invoices ||--o{ payment_transactions : paid_by

    warehouses ||--o{ warehouse_stock : holds

    users ||--o{ audit_logs : performs
    users ||--o{ notifications : receives
    users ||--o{ import_logs : uploads
```

## Mandatory system tables

| Table | Purpose |
|-------|---------|
| users | Accounts with roles and company scope |
| roles | admin, manager, staff |
| user_roles | Many-to-many user and role link |
| permissions | Fine-grained access slugs |
| role_permissions | Role to permission mapping |
| refresh_tokens | JWT refresh token storage |
| audit_logs | Critical action audit trail |
| notifications | In-app alerts |
| settings | Global key/value config |
| files | Uploaded file metadata |

## Domain tables

| Table | Purpose |
|-------|---------|
| companies | Tenant root |
| categories | Product groups |
| suppliers | Vendors |
| products | Inventory items |
| stock_movements | Stock in/out history |
| invoices / invoice_items | Billing |
| payment_transactions | Payment records |
| cms_pages | Landing page content blocks |
| import_logs | Import job history |
| warehouses / warehouse_stock | Storage locations |
| purchase_orders | Supplier orders |

## Supporting tables

cache, cache_locks, jobs, job_batches, failed_jobs, sessions, password_reset_tokens
