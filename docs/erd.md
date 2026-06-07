# Database ERD

AIMS uses a multi-tenant relational schema with 26 tables (3NF normalized).

```mermaid
erDiagram
    companies ||--o{ users : has
    companies ||--o{ categories : has
    companies ||--o{ suppliers : has
    companies ||--o{ products : has
    companies ||--o{ stock_movements : has
    companies ||--o{ invoices : has
    companies ||--o{ activity_logs : has
    companies ||--o{ notifications : has
    companies ||--o{ payment_transactions : has
    companies ||--o{ cms_pages : has
    companies ||--o{ import_logs : has
    companies ||--o{ warehouses : has
    companies ||--o{ purchase_orders : has

    categories ||--o{ products : categorizes
    suppliers ||--o{ products : supplies
    suppliers ||--o{ purchase_orders : fulfills

    products ||--o{ stock_movements : tracks
    products ||--o{ invoice_items : contains
    products ||--o{ warehouse_stock : stores

    invoices ||--o{ invoice_items : includes
    invoices ||--o{ payment_transactions : paid_by

    warehouses ||--o{ warehouse_stock : holds

    users ||--o{ activity_logs : performs
    users ||--o{ notifications : receives
    users ||--o{ import_logs : uploads

    roles ||--o{ role_permission : grants
    permissions ||--o{ role_permission : assigned

    companies {
        bigint id PK
        string name
        string address
        timestamps created_at
        timestamps updated_at
    }

    users {
        bigint id PK
        bigint company_id FK
        string name
        string email UK
        string password
        enum role
        string api_token
        boolean must_change_password
        boolean temporary_password_consumed
    }

    products {
        bigint id PK
        bigint company_id FK
        bigint category_id FK
        bigint supplier_id FK
        string name
        string sku
        string barcode
        int quantity
        int min_quantity
        decimal price
    }

    roles {
        bigint id PK
        string name UK
        string slug UK
    }

    permissions {
        bigint id PK
        string slug UK
        string group
    }

    notifications {
        bigint id PK
        bigint company_id FK
        bigint user_id FK
        string type
        string title
        text message
        timestamp read_at
    }

    payment_transactions {
        bigint id PK
        bigint company_id FK
        bigint invoice_id FK
        decimal amount
        string status
        string transaction_ref UK
    }

    cms_pages {
        bigint id PK
        bigint company_id FK
        string slug
        string title
        longtext content
        boolean is_published
    }
```

## Table inventory (26)

| Table | Purpose |
|-------|---------|
| users | Authentication and role assignment |
| password_reset_tokens | Password recovery |
| sessions | Session storage |
| cache / cache_locks | Application cache |
| jobs / job_batches / failed_jobs | Queue processing |
| companies | Multi-tenant root entity |
| categories | Product categorization |
| suppliers | Vendor management |
| products | Inventory items |
| stock_movements | Stock in/out audit trail |
| invoices | Customer billing |
| invoice_items | Line items per invoice |
| activity_logs | User action audit |
| roles | RBAC roles |
| permissions | Granular access rights |
| role_permission | Role-to-permission mapping |
| notifications | Real-time alert storage |
| payment_transactions | Online payment records |
| cms_pages | Content management |
| import_logs | CSV import audit |
| warehouses | Storage locations |
| warehouse_stock | Per-warehouse quantities |
| purchase_orders | Procurement tracking |
