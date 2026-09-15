# Multi-Tenant Loyalty API

A scalable multi-tenant loyalty and points management API built with Laravel 12.

This project provides a reusable backend architecture for businesses that need to manage multiple tenants, customers, points, rewards, and redemptions through a unified API.

The project also includes a Filament v4 administration panel for managing system data.

---

## ✨ Features

- Multi-Tenant Architecture
- JWT Authentication
- RESTful API
- Customer Management
- Point Account Management
- Point Transaction Ledger
- Point Earn / Redeem
- Reward Management
- Reward Redemption
- Tenant Data Isolation
- Filament v4 Admin Panel
- Livewire-powered Admin UI
- OpenAPI / Swagger API Documentation
- Feature & Unit Testing
- Activity Logging

---

## 🛠 Tech Stack

| Technology        | Version             |
| ----------------- | ------------------- |
| PHP               | 8.2+                |
| Laravel           | 12                  |
| Filament          | 4                   |
| Livewire          | 3                   |
| JWT Auth          | tymon/jwt-auth      |
| API Documentation | L5-Swagger          |
| Database          | MySQL 8             |
| Admin UI          | Filament + Livewire |

---

## 🏗 Architecture

The project uses a **Shared Database + Shared Tables + `tenant_id`** multi-tenant architecture.

```text
                         Laravel 12
                             │
              ┌──────────────┴──────────────┐
              │                             │
              ▼                             ▼
       Filament v4                    RESTful API
              │                             │
          Livewire                         JWT
              │                             │
              └──────────────┬──────────────┘
                             │
                             ▼
                      Tenant Context
                             │
                             ▼
                       Authorization
                             │
                             ▼
                         Services
                             │
                             ▼
                         Eloquent
                             │
                             ▼
                          MySQL 8
```

Both the API and Filament administration panel use the same application services and business rules.

This prevents business logic from being duplicated between the API and admin panel.

---

## 🏢 Multi-Tenancy

Tenant isolation is one of the core principles of this project.

The application uses:

```text
Shared Database
        +
Shared Tables
        +
tenant_id
```

Tenant-specific resources contain a `tenant_id` column.

Example:

```text
Tenant
 ├── Users
 ├── Customers
 │    └── Point Account
 │         └── Point Transactions
 ├── Point Rules
 ├── Rewards
 └── Reward Redemptions
```

A user belonging to Tenant A must never be able to access Tenant B's resources.

The tenant is resolved from the authenticated user and stored in a central `TenantContext`.

```text
JWT Authentication
        ↓
Authenticated User
        ↓
TenantResolver
        ↓
TenantContext
        ↓
Authorization
        ↓
Controller
        ↓
Service
        ↓
Database
```

Tenant IDs should not be trusted from client requests to determine the data scope.

---

## 🔐 Authentication

The API uses JWT authentication.

Authentication endpoints:

```http
POST /api/v1/auth/login
POST /api/v1/auth/logout
POST /api/v1/auth/refresh
GET  /api/v1/auth/me
```

Authenticated requests use:

```http
Authorization: Bearer {token}
```

---

## 🎯 Loyalty System

The loyalty system is based on a point ledger.

A customer's current balance is stored in `point_accounts`, while every point change is recorded in `point_transactions`.

```text
Customer
    │
    ▼
Point Account
    │
    ├── Earn
    ├── Redeem
    ├── Adjustment
    ├── Refund
    ├── Bonus
    └── Expire
            │
            ▼
    Point Transaction
```

The system does not rely only on the current balance.

Every point change creates a transaction record containing information such as:

- Tenant
- Customer
- Point Account
- Transaction Type
- Amount
- Balance Before
- Balance After
- Reference
- Description
- Operator
- Created Time

This provides an auditable history of point changes.

---

## 💳 Point Transactions

Supported transaction types include:

```text
earn
redeem
adjustment
refund
bonus
expire
```

Point mutations are executed inside database transactions.

For example:

```text
Point Earn

1. Lock Point Account
2. Read current balance
3. Calculate new balance
4. Update balance
5. Create Point Transaction
6. Commit
```

This helps prevent inconsistent balances during concurrent requests.

---

## 🎁 Rewards

Rewards can be configured for each tenant.

A reward can contain:

- Name
- Description
- Required Points
- Stock
- Status
- Start Time
- End Time

Reward redemption verifies:

1. Reward availability
2. Reward stock
3. Customer point balance
4. Point deduction
5. Transaction creation
6. Stock deduction
7. Redemption creation

These operations are executed atomically.

---

## 🖥 Admin Panel

The administration panel is built with:

- Filament v4
- Livewire

The admin panel provides management interfaces for:

```text
Dashboard
├── Tenants
├── Users
├── Customers
├── Point Accounts
├── Point Transactions
├── Point Rules
├── Rewards
└── Redemptions
```

Filament is responsible for administration and UI interaction.

Business logic remains inside application services so that both API and Filament use the same rules.

---

## 📡 API

API endpoints are versioned under:

```text
/api/v1
```

Main API groups:

```text
/api/v1/auth
/api/v1/customers
/api/v1/customers/{customer}/points
/api/v1/customers/{customer}/point-transactions
/api/v1/point-rules
/api/v1/rewards
/api/v1/rewards/{reward}/redeem
/api/v1/redemptions
```

The API uses a consistent response structure.

### Success

```json
{
    "success": true,
    "message": "Operation successful",
    "data": {}
}
```

### Error

```json
{
    "success": false,
    "message": "Insufficient points",
    "error_code": "INSUFFICIENT_POINTS",
    "errors": []
}
```

---

## 📚 API Documentation

Interactive API documentation is provided through L5-Swagger.

After starting the application, visit:

```text
/api/documentation
```

Swagger documentation covers the available API endpoints, request parameters, authentication, responses, and error codes.

---

## 📁 Project Structure

The application follows a domain-oriented structure while keeping Laravel conventions familiar.

```text
app/
├── Actions/
├── Exceptions/
├── Filament/
├── Http/
│   ├── Controllers/
│   │   └── Api/
│   │       └── V1/
│   ├── Requests/
│   │   └── Api/
│   │       └── V1/
│   └── Resources/
│       └── Api/
│           └── V1/
├── Models/
├── Policies/
├── Services/
│   ├── Tenant/
│   ├── Customer/
│   ├── Point/
│   ├── Reward/
│   └── Redemption/
└── Support/
    └── Tenancy/
        ├── TenantContext.php
        └── TenantResolver.php

database/
├── factories/
├── migrations/
└── seeders/

routes/
├── api.php
└── web.php

tests/
├── Feature/
│   ├── Auth/
│   ├── Tenant/
│   ├── Customer/
│   ├── Point/
│   └── Reward/
└── Unit/

docs/
├── architecture.md
├── multi-tenancy.md
├── point-system.md
└── api.md
```

---

## 🧩 Core Models

The main domain models are:

```text
Tenant
User
Customer
PointAccount
PointTransaction
PointRule
Reward
RewardRedemption
AuditLog
```

### Relationships

```text
Tenant
 ├── hasMany Users
 ├── hasMany Customers
 ├── hasMany Point Rules
 ├── hasMany Rewards
 └── hasMany Redemptions

Customer
 ├── belongsTo Tenant
 ├── hasOne PointAccount
 └── hasMany PointTransactions

PointAccount
 ├── belongsTo Customer
 └── hasMany PointTransactions

Reward
 └── hasMany Redemptions
```

---

## 🧠 Application Principles

### Thin Controllers

Controllers are responsible for handling HTTP requests and delegating work to services.

```text
Request
   ↓
Controller
   ↓
Service
   ↓
Model
```

Business logic should not be placed directly inside controllers.

### Shared Business Logic

API Controllers and Filament Actions should use the same services.

```text
API
 │
 └── PointService

Filament
 │
 └── PointService
```

This prevents different application entry points from implementing different business rules.

### Explicit Code

The project favors:

- Clear naming
- Small responsibilities
- Familiar Laravel conventions
- Explicit business rules
- Minimal abstraction
- Easy-to-follow dependencies

The goal is to make the codebase understandable to both developers and AI coding assistants.

---

## 🧪 Testing

The project uses Laravel's testing tools for automated verification.

Important test scenarios include:

- JWT authentication
- Tenant isolation
- Customer access control
- Point earning
- Point redemption
- Insufficient points
- Point transaction creation
- Point balance consistency
- Reward stock validation
- Cross-tenant access prevention

A critical security scenario is:

```text
Tenant A
   │
   └── Customer A

Tenant B
   │
   └── Customer B

Tenant A User
   │
   └── ❌ Cannot access Customer B
```

---

## 🚀 Installation

Clone the repository:

```bash
git clone <repository-url>

cd <project-directory>
```

Install PHP dependencies:

```bash
composer install
```

Create the environment file:

```bash
cp .env.example .env
```

Generate the application key:

```bash
php artisan key:generate
```

Configure the database in `.env`:

```env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=loyalty
DB_USERNAME=root
DB_PASSWORD=
```

Run migrations:

```bash
php artisan migrate
```

Generate JWT secret:

```bash
php artisan jwt:secret
```

Generate Swagger documentation:

```bash
php artisan l5-swagger:generate
```

Start the development server:

```bash
php artisan serve
```

The application will be available at:

```text
http://127.0.0.1:8000
```

Swagger:

```text
http://127.0.0.1:8000/api/documentation
```

---

## 🔄 Development Workflow

Recommended development order:

```text
1. Project Foundation
       ↓
2. JWT Authentication
       ↓
3. Tenant Context
       ↓
4. Tenant Isolation
       ↓
5. Customer Management
       ↓
6. Point Account
       ↓
7. Point Ledger
       ↓
8. Point Earn / Redeem
       ↓
9. Point Rules
       ↓
10. Rewards
       ↓
11. Redemptions
       ↓
12. Audit Logging
       ↓
13. Swagger Documentation
       ↓
14. Automated Tests
```

---

## 🗺 Roadmap

### Phase 1 — Foundation

- [x] Laravel 12
- [x] Filament v4
- [x] Livewire
- [x] JWT Authentication
- [x] L5-Swagger
- [ ] Tenant
- [ ] User
- [ ] Tenant Context
- [ ] Tenant Isolation

### Phase 2 — Customer

- [ ] Customer CRUD
- [ ] Customer authentication/access rules
- [ ] Customer point account

### Phase 3 — Points

- [ ] Point Account
- [ ] Point Transaction
- [ ] Earn Points
- [ ] Redeem Points
- [ ] Adjustment
- [ ] Refund
- [ ] Expiration
- [ ] Transaction history

### Phase 4 — Loyalty

- [ ] Point Rules
- [ ] Rewards
- [ ] Reward Stock
- [ ] Reward Redemption

### Phase 5 — Reliability

- [ ] Database transaction protection
- [ ] Row-level locking
- [ ] Idempotency
- [ ] Audit logging
- [ ] Additional security tests

### Phase 6 — Documentation

- [ ] Complete Swagger documentation
- [ ] Architecture documentation
- [ ] ERD
- [ ] API examples
- [ ] Deployment documentation

---

## 🔒 Security Principles

The project treats tenant isolation as a security boundary.

Important rules:

1. Never trust `tenant_id` supplied by the client.
2. Always resolve the current tenant from authenticated context.
3. Validate resource ownership.
4. Apply authorization policies.
5. Prevent cross-tenant queries.
6. Use database transactions for point mutations.
7. Use row locking for concurrent balance operations.
8. Keep point transactions auditable.

---

## 📌 Project Goals

This project is designed to demonstrate how to build a maintainable Laravel application with:

- Multi-Tenant Architecture
- JWT Authentication
- RESTful API Design
- Domain-oriented Services
- Filament Administration
- Livewire UI
- Point Ledger Architecture
- Transaction-safe Point Operations
- API Documentation
- Automated Testing

The primary goal is not to build every possible loyalty feature, but to provide a clean and extensible foundation that can be expanded as business requirements grow.

---

## 📄 License

This project is open-sourced under the [MIT License](LICENSE).
