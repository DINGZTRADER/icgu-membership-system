-- =============================================================================
-- PostgreSQL DDL Schema & Test Seed Data for ICGU Membership Management System
-- Generated from: 2026_01_01_000001_create_icgu_membership_schema.php
-- Matches DatabaseSeeder.php for initial seed dataset.
-- Suitable for execution in Supabase SQL Editor or raw PostgreSQL environment.
-- =============================================================================

BEGIN;

-- Fallback check/creation for standard Laravel 'users' table if not exists,
-- to satisfy foreign key constraints in audit, periods, ledger, etc.
CREATE TABLE IF NOT EXISTS users (
    id BIGSERIAL PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    email VARCHAR(255) NOT NULL UNIQUE,
    email_verified_at TIMESTAMP WITH TIME ZONE,
    password VARCHAR(255) NOT NULL,
    remember_token VARCHAR(100),
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP
);

-- =========================================================
-- TABLE 1: lookup_statuses
-- =========================================================
CREATE TABLE IF NOT EXISTS lookup_statuses (
    id BIGSERIAL PRIMARY KEY,
    code VARCHAR(50) NOT NULL UNIQUE,
    type VARCHAR(30) NOT NULL, -- 'membership' | 'payment' | 'communication'
    label VARCHAR(100) NOT NULL,
    description TEXT,
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    sort_order SMALLINT NOT NULL DEFAULT 0,
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_lookup_statuses_type_active ON lookup_statuses (type, is_active);

-- =========================================================
-- TABLE 2: members
-- =========================================================
CREATE TABLE IF NOT EXISTS members (
    id BIGSERIAL PRIMARY KEY,
    registration_number VARCHAR(20) NOT NULL UNIQUE, -- Format: ICGU/NNN/YYYY
    type VARCHAR(20) NOT NULL, -- 'individual' | 'corporate'
    title VARCHAR(20),
    first_name VARCHAR(100),
    last_name VARCHAR(100),
    national_id VARCHAR(50) UNIQUE,
    company_name VARCHAR(200),
    industry_code VARCHAR(10), -- ISIC Rev.4 classification code
    registration_cert VARCHAR(100), -- Company registration certificate number
    phone VARCHAR(30),
    organization VARCHAR(200), -- Employer for individuals; Parent group for corporates
    job_title VARCHAR(150),
    registration_date DATE NOT NULL,
    status_id BIGINT NOT NULL REFERENCES lookup_statuses(id) ON DELETE RESTRICT,
    is_archived BOOLEAN NOT NULL DEFAULT FALSE,
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    deleted_at TIMESTAMP WITH TIME ZONE
);

CREATE INDEX IF NOT EXISTS idx_members_type_status ON members (type, status_id);
CREATE INDEX IF NOT EXISTS idx_members_registration_date ON members (registration_date);
CREATE INDEX IF NOT EXISTS idx_members_archived ON members (is_archived);
CREATE INDEX IF NOT EXISTS idx_members_name_search ON members (last_name, first_name);

-- Partial index: only non-archived members (PostgreSQL-native)
CREATE INDEX IF NOT EXISTS idx_members_active_partial
ON members (status_id, registration_date)
WHERE is_archived = FALSE AND deleted_at IS NULL;

-- Check constraint: type must be valid
ALTER TABLE members DROP CONSTRAINT IF EXISTS chk_members_type;
ALTER TABLE members
ADD CONSTRAINT chk_members_type
CHECK (type IN ('individual', 'corporate'));

-- Check constraint: individuals must have a first/last name; corporates must have company_name
ALTER TABLE members DROP CONSTRAINT IF EXISTS chk_members_name_completeness;
ALTER TABLE members
ADD CONSTRAINT chk_members_name_completeness
CHECK (
    (type = 'individual' AND first_name IS NOT NULL AND last_name IS NOT NULL)
    OR
    (type = 'corporate' AND company_name IS NOT NULL)
);

-- =========================================================
-- TABLE 3: member_emails
-- =========================================================
CREATE TABLE IF NOT EXISTS member_emails (
    id BIGSERIAL PRIMARY KEY,
    member_id BIGINT NOT NULL REFERENCES members(id) ON DELETE CASCADE,
    email VARCHAR(254) NOT NULL,
    email_type VARCHAR(20) NOT NULL DEFAULT 'work', -- 'work' | 'personal' | 'billing'
    is_primary BOOLEAN NOT NULL DEFAULT FALSE,
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    verified_at TIMESTAMP WITH TIME ZONE,
    verification_token VARCHAR(100),
    verification_sent_at TIMESTAMP WITH TIME ZONE,
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT uq_member_emails_member_email UNIQUE (member_id, email)
);

CREATE INDEX IF NOT EXISTS idx_member_emails_email ON member_emails (email);
CREATE INDEX IF NOT EXISTS idx_member_emails_primary ON member_emails (member_id, is_primary);

-- Partial unique index: enforces only ONE primary email per member
DROP INDEX IF EXISTS uq_member_emails_one_primary;
CREATE UNIQUE INDEX uq_member_emails_one_primary
ON member_emails (member_id)
WHERE is_primary = TRUE AND is_active = TRUE;

-- =========================================================
-- TABLE 4: membership_periods
-- =========================================================
CREATE TABLE IF NOT EXISTS membership_periods (
    id BIGSERIAL PRIMARY KEY,
    member_id BIGINT NOT NULL REFERENCES members(id) ON DELETE CASCADE,
    start_date DATE NOT NULL,
    end_date DATE NOT NULL,
    target_year SMALLINT NOT NULL, -- The membership year this period represents, e.g. 2025
    is_backdated BOOLEAN NOT NULL DEFAULT FALSE,
    is_future BOOLEAN NOT NULL DEFAULT FALSE,
    notes TEXT,
    created_by BIGINT REFERENCES users(id) ON DELETE SET NULL,
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT uq_membership_periods_member_year UNIQUE (member_id, target_year)
);

ALTER TABLE membership_periods DROP CONSTRAINT IF EXISTS chk_period_dates;
ALTER TABLE membership_periods
ADD CONSTRAINT chk_period_dates
CHECK (end_date > start_date);

CREATE INDEX IF NOT EXISTS idx_membership_periods_year_end ON membership_periods (target_year, end_date);
CREATE INDEX IF NOT EXISTS idx_membership_periods_date_range ON membership_periods (start_date, end_date);

-- =========================================================
-- TABLE 5: financial_ledger
-- =========================================================
CREATE TABLE IF NOT EXISTS financial_ledger (
    id BIGSERIAL PRIMARY KEY,
    member_id BIGINT NOT NULL REFERENCES members(id) ON DELETE RESTRICT,
    period_id BIGINT REFERENCES membership_periods(id) ON DELETE SET NULL,
    status_id BIGINT NOT NULL REFERENCES lookup_statuses(id) ON DELETE RESTRICT,
    type VARCHAR(20) NOT NULL, -- 'invoice' | 'payment' | 'refund' | 'waiver'
    fee_type VARCHAR(30) NOT NULL, -- 'application' | 'annual_individual' | 'annual_corporate' | 'administrative' | 'levy'
    amount DECIMAL(15, 4) NOT NULL, -- Always positive. Direction determined by type.
    amount_settled DECIMAL(15, 4) NOT NULL DEFAULT 0.0000, -- Running settled amount for invoices.
    tx_reference VARCHAR(100) UNIQUE, -- External payment gateway or bank reference
    currency VARCHAR(3) NOT NULL DEFAULT 'UGX',
    parent_invoice_id BIGINT REFERENCES financial_ledger(id) ON DELETE SET NULL,
    notes TEXT,
    due_date TIMESTAMP WITH TIME ZONE,
    settled_at TIMESTAMP WITH TIME ZONE,
    created_by BIGINT REFERENCES users(id) ON DELETE SET NULL,
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP
);

ALTER TABLE financial_ledger DROP CONSTRAINT IF EXISTS chk_ledger_amount_positive;
ALTER TABLE financial_ledger ADD CONSTRAINT chk_ledger_amount_positive CHECK (amount >= 0);

ALTER TABLE financial_ledger DROP CONSTRAINT IF EXISTS chk_ledger_settled_positive;
ALTER TABLE financial_ledger ADD CONSTRAINT chk_ledger_settled_positive CHECK (amount_settled >= 0);

ALTER TABLE financial_ledger DROP CONSTRAINT IF EXISTS chk_ledger_settled_lte_amount;
ALTER TABLE financial_ledger ADD CONSTRAINT chk_ledger_settled_lte_amount CHECK (amount_settled <= amount);

ALTER TABLE financial_ledger DROP CONSTRAINT IF EXISTS chk_ledger_type;
ALTER TABLE financial_ledger ADD CONSTRAINT chk_ledger_type CHECK (type IN ('invoice','payment','refund','waiver'));

ALTER TABLE financial_ledger DROP CONSTRAINT IF EXISTS chk_ledger_fee_type;
ALTER TABLE financial_ledger ADD CONSTRAINT chk_ledger_fee_type CHECK (fee_type IN ('application','annual_individual','annual_corporate','administrative','levy'));

CREATE INDEX IF NOT EXISTS idx_ledger_member_type_status ON financial_ledger (member_id, type, status_id);
CREATE INDEX IF NOT EXISTS idx_ledger_period_type ON financial_ledger (period_id, type);
CREATE INDEX IF NOT EXISTS idx_ledger_due_date ON financial_ledger (due_date);
CREATE INDEX IF NOT EXISTS idx_ledger_feetype_date ON financial_ledger (fee_type, created_at);
CREATE INDEX IF NOT EXISTS idx_ledger_parent_invoice ON financial_ledger (parent_invoice_id);

-- Partial index: open invoices only
CREATE INDEX IF NOT EXISTS idx_ledger_open_invoices
ON financial_ledger (member_id, due_date, amount_settled)
WHERE type = 'invoice' AND settled_at IS NULL;

-- =========================================================
-- TABLE 6: member_status_history
-- =========================================================
CREATE TABLE IF NOT EXISTS member_status_history (
    id BIGSERIAL PRIMARY KEY,
    member_id BIGINT NOT NULL REFERENCES members(id) ON DELETE CASCADE,
    from_status_id BIGINT REFERENCES lookup_statuses(id) ON DELETE SET NULL,
    to_status_id BIGINT NOT NULL REFERENCES lookup_statuses(id) ON DELETE RESTRICT,
    reason_code VARCHAR(50),
    reason_notes TEXT,
    effective_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    actor_id BIGINT REFERENCES users(id) ON DELETE SET NULL,
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_status_history_member_time ON member_status_history (member_id, effective_at);
CREATE INDEX IF NOT EXISTS idx_status_history_to_status ON member_status_history (to_status_id);

-- =========================================================
-- TABLE 7: communication_logs
-- =========================================================
CREATE TABLE IF NOT EXISTS communication_logs (
    id BIGSERIAL PRIMARY KEY,
    member_id BIGINT NOT NULL REFERENCES members(id) ON DELETE CASCADE,
    campaign_ref VARCHAR(50),
    sequence VARCHAR(20) NOT NULL, -- 'first' | 'second' | 'final' | 'ad_hoc'
    channel VARCHAR(20) NOT NULL DEFAULT 'email', -- 'email' | 'sms'
    subject VARCHAR(255),
    status VARCHAR(30) NOT NULL, -- 'queued' | 'sent' | 'delivered' | 'failed' | 'opened' | 'bounced'
    recipient_email VARCHAR(254),
    sent_at TIMESTAMP WITH TIME ZONE,
    opened_at TIMESTAMP WITH TIME ZONE,
    tracking_token VARCHAR(100) UNIQUE,
    meta JSONB, -- Provider response payload, message IDs, bounce reason, etc.
    sent_by BIGINT REFERENCES users(id) ON DELETE SET NULL,
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_comm_logs_member_seq_status ON communication_logs (member_id, sequence, status);
CREATE INDEX IF NOT EXISTS idx_comm_logs_sent_at ON communication_logs (sent_at);
CREATE INDEX IF NOT EXISTS idx_comm_logs_campaign_ref ON communication_logs (campaign_ref);

-- GIN index for meta JSONB column
CREATE INDEX IF NOT EXISTS idx_comm_logs_meta_gin ON communication_logs USING GIN (meta);

-- Partial index: failed communications to facilitate retry queries
CREATE INDEX IF NOT EXISTS idx_comm_logs_failed_partial
ON communication_logs (member_id, sent_at)
WHERE status IN ('failed', 'bounced');

-- =========================================================
-- TABLE 8: audit_logs
-- =========================================================
CREATE TABLE IF NOT EXISTS audit_logs (
    id BIGSERIAL PRIMARY KEY,
    user_id BIGINT REFERENCES users(id) ON DELETE SET NULL,
    action VARCHAR(50) NOT NULL, -- 'created' | 'updated' | 'deleted' | 'login' | 'logout' | 'export' | 'status_changed' | 'payment_recorded'
    entity VARCHAR(150) NOT NULL, -- e.g., 'App\\Models\\Member'
    entity_id BIGINT,
    ip_address VARCHAR(45),
    user_agent TEXT,
    before_payload JSONB,
    after_payload JSONB,
    session_id VARCHAR(100),
    request_id VARCHAR(100),
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_audit_entity ON audit_logs (entity, entity_id);
CREATE INDEX IF NOT EXISTS idx_audit_user_time ON audit_logs (user_id, created_at);
CREATE INDEX IF NOT EXISTS idx_audit_action ON audit_logs (action);
CREATE INDEX IF NOT EXISTS idx_audit_created_at ON audit_logs (created_at);

CREATE INDEX IF NOT EXISTS idx_audit_before_gin ON audit_logs USING GIN (before_payload);
CREATE INDEX IF NOT EXISTS idx_audit_after_gin ON audit_logs USING GIN (after_payload);

-- =========================================================
-- TABLE 9: registration_sequences
-- =========================================================
CREATE TABLE IF NOT EXISTS registration_sequences (
    id BIGSERIAL PRIMARY KEY,
    year SMALLINT UNIQUE NOT NULL,
    last_sequence INTEGER DEFAULT 0 NOT NULL,
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP
);

-- =========================================================
-- SEED DATA: Core lookup statuses and initial registration sequence
-- =========================================================

-- --- Seed Lookup Statuses ---
INSERT INTO lookup_statuses (code, type, label, sort_order, is_active) VALUES
-- Membership Statuses
('PENDING', 'membership', 'Pending', 1, TRUE),
('ACTIVE', 'membership', 'Active', 2, TRUE),
('SUSPENDED', 'membership', 'Suspended', 3, TRUE),
('EXPIRED', 'membership', 'Expired', 4, TRUE),
('RESIGNED', 'membership', 'Resigned', 5, TRUE),
('ARCHIVED', 'membership', 'Archived', 6, TRUE),
-- Payment Statuses
('PAY_PENDING', 'payment', 'Pending', 1, TRUE),
('PAY_PAID', 'payment', 'Paid', 2, TRUE),
('PAY_PARTIAL', 'payment', 'Partially Paid', 3, TRUE),
('PAY_OVERDUE', 'payment', 'Overdue', 4, TRUE),
('PAY_WAIVED', 'payment', 'Waived', 5, TRUE),
('PAY_REFUNDED', 'payment', 'Refunded', 6, TRUE),
('PAY_CANCELLED', 'payment', 'Cancelled', 7, TRUE),
-- Communication Statuses
('COMM_QUEUED', 'communication', 'Queued', 1, TRUE),
('COMM_SENT', 'communication', 'Sent', 2, TRUE),
('COMM_DELIVERED', 'communication', 'Delivered', 3, TRUE),
('COMM_OPENED', 'communication', 'Opened', 4, TRUE),
('COMM_FAILED', 'communication', 'Failed', 5, TRUE),
('COMM_BOUNCED', 'communication', 'Bounced', 6, TRUE)
ON CONFLICT (code) DO NOTHING;

-- --- Seed Registration Sequences ---
INSERT INTO registration_sequences (year, last_sequence) VALUES
(EXTRACT(YEAR FROM CURRENT_DATE)::SMALLINT, 0)
ON CONFLICT (year) DO NOTHING;


-- =============================================================================
-- SEED DATA: Fictional/Anonymous Test Data
-- Matches DatabaseSeeder.php exactly. Compliant with GDPR & DPPA 2019.
-- =============================================================================

-- 1. Create Admin User (id = 1)
INSERT INTO users (id, name, email, password, created_at, updated_at)
VALUES (1, 'ICGU System Administrator', 'admin@icgu.prototype', '$2y$12$R.Snbk3rEex6rT42cMshUuf.iL/b5yYk9m.2YhKCSX788vB1rE9tG', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
ON CONFLICT (id) DO NOTHING;

SELECT setval(pg_get_serial_sequence('users', 'id'), COALESCE(MAX(id), 1)) FROM users;

-- 2. Create Members
-- Mr. Alpha Testman (id = 1, active status = 2, registration_number = ICGU/001/2024)
INSERT INTO members (id, registration_number, type, title, first_name, last_name, phone, organization, job_title, registration_date, status_id, is_archived, created_at, updated_at)
VALUES (1, 'ICGU/001/2024', 'individual', 'Mr', 'Alpha', 'Testman', '+256700000001', 'Fictional Enterprises Ltd', 'Chief Executive Officer', '2024-01-15', 2, FALSE, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
ON CONFLICT (id) DO NOTHING;

-- Dr. Beta Demouser (id = 2, expired status = 4, registration_number = ICGU/002/2023)
INSERT INTO members (id, registration_number, type, title, first_name, last_name, phone, organization, job_title, registration_date, status_id, is_archived, created_at, updated_at)
VALUES (2, 'ICGU/002/2023', 'individual', 'Dr', 'Beta', 'Demouser', '+256700000002', 'Prototype Holdings Inc', 'Board Secretary', '2023-06-20', 4, FALSE, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
ON CONFLICT (id) DO NOTHING;

-- Ms. Gamma Sampledata (id = 3, pending status = 1, registration_number = ICGU/003/2025)
INSERT INTO members (id, registration_number, type, title, first_name, last_name, phone, organization, job_title, registration_date, status_id, is_archived, created_at, updated_at)
VALUES (3, 'ICGU/003/2025', 'individual', 'Ms', 'Gamma', 'Sampledata', '+256700000003', 'Test Corp Uganda', 'Finance Director', '2025-03-01', 1, FALSE, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
ON CONFLICT (id) DO NOTHING;

-- Fictional Bank Uganda Limited (id = 4, active status = 2, registration_number = ICGU/004/2024)
INSERT INTO members (id, registration_number, type, company_name, industry_code, registration_cert, phone, organization, registration_date, status_id, is_archived, created_at, updated_at)
VALUES (4, 'ICGU/004/2024', 'corporate', 'Fictional Bank Uganda Limited', '6419', 'CRP/PROTO/0001/2024', '+256414000001', 'Fictional Bank Uganda Limited', '2024-02-10', 2, FALSE, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
ON CONFLICT (id) DO NOTHING;

-- Demo Insurance Co Uganda (id = 5, active status = 2, registration_number = ICGU/005/2023)
INSERT INTO members (id, registration_number, type, company_name, industry_code, registration_cert, phone, organization, registration_date, status_id, is_archived, created_at, updated_at)
VALUES (5, 'ICGU/005/2023', 'corporate', 'Demo Insurance Co Uganda', '6511', 'CRP/PROTO/0002/2023', '+256414000002', 'Demo Insurance Co Uganda', '2023-09-05', 2, FALSE, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
ON CONFLICT (id) DO NOTHING;

SELECT setval(pg_get_serial_sequence('members', 'id'), COALESCE(MAX(id), 1)) FROM members;

-- 3. Create Member Emails
INSERT INTO member_emails (member_id, email, email_type, is_primary, is_active, verified_at, created_at, updated_at)
VALUES
(1, 'alpha.testman@prototype.invalid', 'work', TRUE, TRUE, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
(2, 'beta.demouser@prototype.invalid', 'work', TRUE, TRUE, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
(3, 'gamma.sampledata@prototype.invalid', 'work', TRUE, TRUE, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
(4, 'secretary@fictionalbank.prototype.invalid', 'work', TRUE, TRUE, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
(5, 'corporate@demoinsurance.prototype.invalid', 'work', TRUE, TRUE, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
ON CONFLICT (member_id, email) DO NOTHING;

-- 4. Create Membership Periods
INSERT INTO membership_periods (id, member_id, start_date, end_date, target_year, is_backdated, is_future, created_by, created_at, updated_at)
VALUES
(1, 1, '2025-01-01', '2025-12-31', 2025, FALSE, FALSE, 1, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
(2, 2, '2024-01-01', '2024-12-31', 2024, FALSE, FALSE, 1, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
(3, 3, '2025-03-01', '2025-12-31', 2025, FALSE, FALSE, 1, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
(4, 4, '2025-01-01', '2025-12-31', 2025, FALSE, FALSE, 1, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
(5, 5, '2025-01-01', '2025-12-31', 2025, FALSE, FALSE, 1, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
ON CONFLICT (id) DO NOTHING;

SELECT setval(pg_get_serial_sequence('membership_periods', 'id'), COALESCE(MAX(id), 1)) FROM membership_periods;

-- 5. Create Invoices (Financial Ledger)
-- ID 1: Mr. Alpha Testman Invoice (amount = 500k, settled = 500k, status = paid (8))
INSERT INTO financial_ledger (id, member_id, period_id, status_id, type, fee_type, amount, amount_settled, tx_reference, currency, due_date, settled_at, created_by, created_at, updated_at)
VALUES (1, 1, 1, 8, 'invoice', 'annual_individual', 500000.0000, 500000.0000, 'PROTO-INV-ALPHA', 'UGX', '2025-01-31 00:00:00', CURRENT_TIMESTAMP, 1, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
ON CONFLICT (id) DO NOTHING;

-- ID 2: Dr. Beta Demouser Invoice (amount = 500k, settled = 0, status = overdue (10))
INSERT INTO financial_ledger (id, member_id, period_id, status_id, type, fee_type, amount, amount_settled, tx_reference, currency, due_date, settled_at, created_by, created_at, updated_at)
VALUES (2, 2, 2, 10, 'invoice', 'annual_individual', 500000.0000, 0.0000, 'PROTO-INV-BETA', 'UGX', '2024-01-31 00:00:00', NULL, 1, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
ON CONFLICT (id) DO NOTHING;

-- ID 3: Ms. Gamma Sampledata Invoice (amount = 500k, settled = 0, status = pending (7))
INSERT INTO financial_ledger (id, member_id, period_id, status_id, type, fee_type, amount, amount_settled, tx_reference, currency, due_date, settled_at, created_by, created_at, updated_at)
VALUES (3, 3, 3, 7, 'invoice', 'application', 500000.0000, 0.0000, 'PROTO-INV-GAMMA', 'UGX', '2025-03-31 00:00:00', NULL, 1, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
ON CONFLICT (id) DO NOTHING;

-- ID 4: Fictional Bank Invoice (amount = 2.5M, settled = 2.5M, status = paid (8))
INSERT INTO financial_ledger (id, member_id, period_id, status_id, type, fee_type, amount, amount_settled, tx_reference, currency, due_date, settled_at, created_by, created_at, updated_at)
VALUES (4, 4, 4, 8, 'invoice', 'annual_corporate', 2500000.0000, 2500000.0000, 'PROTO-INV-BANK', 'UGX', '2025-01-31 00:00:00', CURRENT_TIMESTAMP, 1, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
ON CONFLICT (id) DO NOTHING;

-- ID 5: Demo Insurance Invoice (amount = 2.5M, settled = 1.25M, status = overdue (10))
INSERT INTO financial_ledger (id, member_id, period_id, status_id, type, fee_type, amount, amount_settled, tx_reference, currency, due_date, settled_at, created_by, created_at, updated_at)
VALUES (5, 5, 5, 10, 'invoice', 'annual_corporate', 2500000.0000, 1250000.0000, 'PROTO-INV-INSURE', 'UGX', '2025-01-31 00:00:00', NULL, 1, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
ON CONFLICT (id) DO NOTHING;

-- 6. Payments
-- Payment for Mr. Alpha Testman (id = 6, parent invoice = 1)
INSERT INTO financial_ledger (id, member_id, period_id, status_id, type, fee_type, amount, amount_settled, parent_invoice_id, tx_reference, currency, settled_at, created_by, created_at, updated_at)
VALUES (6, 1, 1, 8, 'payment', 'annual_individual', 500000.0000, 500000.0000, 1, 'PROTO-PAY-ALPHA', 'UGX', CURRENT_TIMESTAMP, 1, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
ON CONFLICT (id) DO NOTHING;

-- Payment for Fictional Bank (id = 7, parent invoice = 4)
INSERT INTO financial_ledger (id, member_id, period_id, status_id, type, fee_type, amount, amount_settled, parent_invoice_id, tx_reference, currency, settled_at, created_by, created_at, updated_at)
VALUES (7, 4, 4, 8, 'payment', 'annual_corporate', 2500000.0000, 2500000.0000, 4, 'PROTO-PAY-BANK', 'UGX', CURRENT_TIMESTAMP, 1, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
ON CONFLICT (id) DO NOTHING;

-- Payment for Demo Insurance (id = 8, parent invoice = 5)
INSERT INTO financial_ledger (id, member_id, period_id, status_id, type, fee_type, amount, amount_settled, parent_invoice_id, tx_reference, currency, settled_at, created_by, created_at, updated_at)
VALUES (8, 5, 5, 8, 'payment', 'annual_corporate', 1250000.0000, 1250000.0000, 5, 'PROTO-PAY-INSURE', 'UGX', CURRENT_TIMESTAMP, 1, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
ON CONFLICT (id) DO NOTHING;

SELECT setval(pg_get_serial_sequence('financial_ledger', 'id'), COALESCE(MAX(id), 1)) FROM financial_ledger;

COMMIT;
