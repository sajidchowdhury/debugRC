-- ============================================================
-- RC_ERP PostgreSQL Schema — Part 1: Auth + Master Data
-- Converted from MySQL dump osudlagb_remotecenter.sql
-- Phase 2.2 — Laravel baseline migration
-- ============================================================
-- Conversion rules applied:
--   int(11) → integer, bigint(20) → bigint, tinyint(1) → boolean, tinyint(4) → smallint
--   decimal(p,s) → numeric(p,s), float(20,2) → numeric(18,2) [FIX: was float for money!]
--   datetime → timestamp(0), enum(...) → varchar(50) CHECK (col IN (...))
--   AUTO_INCREMENT → GENERATED ALWAYS AS IDENTITY
--   backticks removed, ENGINE/CHARSET clauses dropped
--   0000-00-00 defaults removed (use NULL)
--   ON UPDATE CURRENT_TIMESTAMP → handled by Laravel timestamps + DB trigger
-- ============================================================

-- ============================================================
-- AUTH CORE (Phase 0: totp_secret/totp_enabled columns DROPPED)
-- ============================================================

CREATE TABLE branches (
    id integer GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    branch_code varchar(20) NOT NULL,
    branch_name varchar(100) NOT NULL,
    address text,
    phone varchar(30),
    email varchar(100),
    is_active boolean NOT NULL DEFAULT true,
    created_at timestamp(0) DEFAULT CURRENT_TIMESTAMP,
    updated_at timestamp(0) DEFAULT CURRENT_TIMESTAMP,
    deleted_at timestamp(0),
    deleted_by integer,
    CONSTRAINT branches_branch_code_unique UNIQUE (branch_code)
);

CREATE TABLE employees (
    id integer GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    employee_code varchar(30) NOT NULL,
    name varchar(100) NOT NULL,
    role varchar(30) NOT NULL CHECK (role IN ('admin','salesman','warehouse_manager','dispatcher','accountant','hr','manager','other','superadmin')),
    branch_id integer NOT NULL REFERENCES branches(id),
    phone varchar(30),
    email varchar(100),
    photo varchar(255),
    address text,
    salary numeric(12,2) DEFAULT 0,
    joining_date date,
    is_active boolean NOT NULL DEFAULT true,
    created_at timestamp(0) DEFAULT CURRENT_TIMESTAMP,
    updated_at timestamp(0) DEFAULT CURRENT_TIMESTAMP,
    deleted_at timestamp(0),
    deleted_by integer,
    CONSTRAINT employees_employee_code_unique UNIQUE (employee_code)
);
CREATE INDEX idx_employees_branch ON employees(branch_id);

-- Phase 0: totp_secret and totp_enabled columns are NOT included (dropped per migration 046).
CREATE TABLE users (
    id integer GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    employee_id integer NOT NULL REFERENCES employees(id),
    username varchar(50) NOT NULL,
    password_hash varchar(255) NOT NULL,
    is_active boolean NOT NULL DEFAULT true,
    last_login timestamp(0),
    last_login_ip varchar(45),
    failed_login_count integer NOT NULL DEFAULT 0,
    locked_until timestamp(0),
    credential_version integer NOT NULL DEFAULT 1,
    created_by integer,
    created_at timestamp(0) DEFAULT CURRENT_TIMESTAMP,
    updated_at timestamp(0) DEFAULT CURRENT_TIMESTAMP,
    deleted_at timestamp(0),
    deleted_by integer,
    CONSTRAINT users_username_unique UNIQUE (username),
    CONSTRAINT users_employee_id_unique UNIQUE (employee_id)
);
CREATE INDEX idx_users_employee ON users(employee_id);

CREATE TABLE menus (
    id integer GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    parent_id integer DEFAULT 0,
    menu_label varchar(100) NOT NULL,
    controller varchar(100),
    action varchar(50),
    icon varchar(50),
    sort_order integer DEFAULT 0,
    is_active boolean NOT NULL DEFAULT true,
    created_at timestamp(0) DEFAULT CURRENT_TIMESTAMP,
    updated_at timestamp(0) DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX idx_menus_parent ON menus(parent_id);
CREATE INDEX idx_menus_sort ON menus(sort_order);

CREATE TABLE user_menu_permissions (
    id integer GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    user_id integer NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    menu_id integer NOT NULL REFERENCES menus(id) ON DELETE CASCADE,
    can_view boolean NOT NULL DEFAULT false,
    can_edit boolean NOT NULL DEFAULT false,
    created_at timestamp(0) DEFAULT CURRENT_TIMESTAMP,
    updated_at timestamp(0) DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT user_menu_unique UNIQUE (user_id, menu_id)
);
CREATE INDEX idx_ump_user ON user_menu_permissions(user_id);
CREATE INDEX idx_ump_menu ON user_menu_permissions(menu_id);

-- ============================================================
-- MASTER DATA
-- ============================================================

CREATE TABLE product_categories (
    id integer GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    category_name varchar(100) NOT NULL,
    description text,
    is_active boolean NOT NULL DEFAULT true,
    created_at timestamp(0) DEFAULT CURRENT_TIMESTAMP,
    updated_at timestamp(0) DEFAULT CURRENT_TIMESTAMP,
    deleted_at timestamp(0),
    deleted_by integer
);

CREATE TABLE product_groups (
    id integer GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    group_name varchar(100) NOT NULL,
    description text,
    sort_order integer DEFAULT 0,
    is_active boolean NOT NULL DEFAULT true,
    created_at timestamp(0) DEFAULT CURRENT_TIMESTAMP,
    updated_at timestamp(0) DEFAULT CURRENT_TIMESTAMP,
    deleted_at timestamp(0),
    deleted_by integer
);

CREATE TABLE products (
    id integer GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    product_code varchar(50) NOT NULL,
    product_name varchar(200) NOT NULL,
    category_id integer REFERENCES product_categories(id) ON DELETE SET NULL,
    group_id integer REFERENCES product_groups(id) ON DELETE SET NULL,
    unit varchar(20) NOT NULL CHECK (unit IN ('Pcs','Carton','KG','Bag','Dobe','Set')),
    purchase_rate numeric(12,2) DEFAULT 0,
    sales_rate numeric(12,2) DEFAULT 0,
    min_stock numeric(12,4) DEFAULT 0,
    max_stock numeric(12,4) DEFAULT 0,
    reorder_level numeric(12,4) DEFAULT 0,
    product_image varchar(255),
    is_active boolean NOT NULL DEFAULT true,
    condition_state varchar(10) NOT NULL DEFAULT 'Good' CHECK (condition_state IN ('Good','Damage')),
    created_at timestamp(0) DEFAULT CURRENT_TIMESTAMP,
    updated_at timestamp(0) DEFAULT CURRENT_TIMESTAMP,
    deleted_at timestamp(0),
    deleted_by integer,
    CONSTRAINT products_product_code_unique UNIQUE (product_code)
);
CREATE INDEX idx_products_category ON products(category_id);
CREATE INDEX idx_products_group ON products(group_id);

CREATE TABLE product_price_history (
    id integer GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    product_id integer NOT NULL REFERENCES products(id) ON DELETE CASCADE,
    min_rate numeric(12,2) NOT NULL,
    max_rate numeric(12,2) NOT NULL,
    default_rate numeric(12,2) NOT NULL,
    effective_from date NOT NULL,
    effective_to date,
    created_by integer,
    created_at timestamp(0) DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT product_price_unique UNIQUE (product_id, effective_from)
);
CREATE INDEX idx_pph_product ON product_price_history(product_id);

CREATE TABLE customers (
    id integer GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    customer_code varchar(30) NOT NULL,
    customer_name varchar(200) NOT NULL,
    phone varchar(30),
    mobile varchar(30),
    email varchar(100),
    address text,
    branch_id integer REFERENCES branches(id),
    sales_person_id integer,
    credit_limit numeric(14,2) DEFAULT 0,
    opening_balance numeric(14,2) DEFAULT 0,
    balance_type varchar(10) DEFAULT 'debit' CHECK (balance_type IN ('debit','credit')),
    is_active boolean NOT NULL DEFAULT true,
    created_at timestamp(0) DEFAULT CURRENT_TIMESTAMP,
    updated_at timestamp(0) DEFAULT CURRENT_TIMESTAMP,
    deleted_at timestamp(0),
    deleted_by integer,
    CONSTRAINT customers_customer_code_unique UNIQUE (customer_code)
);
CREATE INDEX idx_customers_branch ON customers(branch_id);
CREATE INDEX idx_customers_salesperson ON customers(sales_person_id);

CREATE TABLE suppliers (
    id integer GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    supplier_code varchar(30) NOT NULL,
    supplier_name varchar(200) NOT NULL,
    phone varchar(30),
    mobile varchar(30),
    email varchar(100),
    address text,
    branch_id integer REFERENCES branches(id),
    contact_person varchar(100),
    opening_balance numeric(14,2) DEFAULT 0,
    balance_type varchar(10) DEFAULT 'credit' CHECK (balance_type IN ('debit','credit')),
    is_active boolean NOT NULL DEFAULT true,
    created_at timestamp(0) DEFAULT CURRENT_TIMESTAMP,
    updated_at timestamp(0) DEFAULT CURRENT_TIMESTAMP,
    deleted_at timestamp(0),
    deleted_by integer,
    CONSTRAINT suppliers_supplier_code_unique UNIQUE (supplier_code)
);

CREATE TABLE banks (
    id integer GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    bank_name varchar(100) NOT NULL,
    account_number varchar(50),
    account_holder varchar(100),
    branch_name varchar(100),
    -- FIX: was float(20,2) in MySQL — CRITICAL for money precision.
    balance numeric(18,2) DEFAULT 0,
    -- FIX: was int(11) storing YYYYMMDD in MySQL.
    updated_at date DEFAULT CURRENT_DATE,
    is_active boolean NOT NULL DEFAULT true,
    ledger_id integer,
    created_by integer,
    created_at timestamp(0) DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX idx_banks_ledger ON banks(ledger_id);

CREATE TABLE bank_ledger_mappings (
    id integer GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    bank_id integer NOT NULL UNIQUE,
    ledger_id integer NOT NULL,
    created_at timestamp(0) DEFAULT CURRENT_TIMESTAMP,
    updated_at timestamp(0) DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX idx_blm_ledger ON bank_ledger_mappings(ledger_id);

CREATE TABLE warehouses (
    id integer GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    warehouse_code varchar(30) NOT NULL,
    warehouse_name varchar(100) NOT NULL,
    branch_id integer NOT NULL REFERENCES branches(id),
    location text,
    is_active boolean NOT NULL DEFAULT true,
    created_at timestamp(0) DEFAULT CURRENT_TIMESTAMP,
    updated_at timestamp(0) DEFAULT CURRENT_TIMESTAMP,
    deleted_at timestamp(0),
    deleted_by integer,
    CONSTRAINT warehouses_warehouse_code_unique UNIQUE (warehouse_code)
);
CREATE INDEX idx_warehouses_branch ON warehouses(branch_id);
