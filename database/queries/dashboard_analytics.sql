-- ============================================================
-- ICGU Membership System — Real-Time Dashboard Analytics Query
-- PostgreSQL 16+
-- Returns a single JSON object consumable by the dashboard API.
-- Optimized for the partial indexes defined in the migration.
-- ============================================================

WITH

-- ----------------------------------------------------------------
-- CTE 1: Membership counts segmented by status code and member type
-- Uses: idx_members_active_partial, idx_members_type_status
-- ----------------------------------------------------------------
membership_summary AS (
    SELECT
        ls.code         AS status_code,
        ls.label        AS status_label,
        m.type          AS member_type,
        COUNT(m.id)     AS member_count
    FROM   members m
    JOIN   lookup_statuses ls ON ls.id = m.status_id
    WHERE  m.is_archived = false
    AND    m.deleted_at  IS NULL
    GROUP  BY ls.code, ls.label, m.type
    ORDER  BY ls.sort_order, m.type
),

-- ----------------------------------------------------------------
-- CTE 2: Active vs Expired headline totals
-- ----------------------------------------------------------------
headline_counts AS (
    SELECT
        COUNT(*) FILTER (WHERE ls.code = 'ACTIVE')  AS active_count,
        COUNT(*) FILTER (WHERE ls.code = 'EXPIRED') AS expired_count,
        COUNT(*) FILTER (WHERE ls.code = 'PENDING') AS pending_count,
        COUNT(*) FILTER (
            WHERE m.type = 'individual' AND ls.code = 'ACTIVE'
        )                                             AS active_individuals,
        COUNT(*) FILTER (
            WHERE m.type = 'corporate' AND ls.code = 'ACTIVE'
        )                                             AS active_corporates,
        COUNT(*) FILTER (
            WHERE m.registration_date >= DATE_TRUNC('month', CURRENT_DATE)
        )                                             AS registered_this_month
    FROM   members m
    JOIN   lookup_statuses ls ON ls.id = m.status_id
    WHERE  m.is_archived = false
    AND    m.deleted_at  IS NULL
),

-- ----------------------------------------------------------------
-- CTE 3: Month-on-Month registration growth (last 12 months)
-- ----------------------------------------------------------------
mom_growth AS (
    SELECT
        TO_CHAR(DATE_TRUNC('month', registration_date), 'YYYY-MM') AS month,
        COUNT(*) FILTER (WHERE type = 'individual')                AS individual_count,
        COUNT(*) FILTER (WHERE type = 'corporate')                 AS corporate_count,
        COUNT(*)                                                    AS total_count
    FROM   members
    WHERE  is_archived = false
    AND    deleted_at  IS NULL
    AND    registration_date >= CURRENT_DATE - INTERVAL '12 months'
    GROUP  BY DATE_TRUNC('month', registration_date)
    ORDER  BY DATE_TRUNC('month', registration_date)
),

-- ----------------------------------------------------------------
-- CTE 4: Outstanding balances per member (open invoices only)
-- Uses: idx_ledger_open_invoices, idx_ledger_member_type_status
-- ----------------------------------------------------------------
outstanding_per_member AS (
    SELECT
        fl.member_id,
        m.registration_number,
        CASE
            WHEN m.type = 'corporate' THEN m.company_name
            ELSE TRIM(COALESCE(m.title,'') || ' ' || COALESCE(m.first_name,'') || ' ' || COALESCE(m.last_name,''))
        END                                                              AS member_name,
        m.type                                                           AS member_type,
        ls_m.code                                                        AS membership_status,
        SUM(fl.amount)                                                   AS total_invoiced,
        SUM(fl.amount_settled)                                           AS total_settled,
        SUM(fl.amount - fl.amount_settled)                               AS outstanding_balance,
        COUNT(*) FILTER (WHERE ls_pay.code = 'PAY_OVERDUE')             AS overdue_invoice_count,
        MIN(fl.due_date) FILTER (
            WHERE fl.settled_at IS NULL
            AND   fl.amount_settled < fl.amount
        )                                                                AS earliest_due_date
    FROM   financial_ledger fl
    JOIN   members          m    ON m.id     = fl.member_id
    JOIN   lookup_statuses  ls_m ON ls_m.id  = m.status_id
    JOIN   lookup_statuses  ls_pay ON ls_pay.id = fl.status_id
    WHERE  fl.type        = 'invoice'
    AND    m.is_archived  = false
    AND    m.deleted_at   IS NULL
    GROUP  BY
        fl.member_id,
        m.registration_number,
        m.company_name,
        m.title,
        m.first_name,
        m.last_name,
        m.type,
        ls_m.code
    HAVING SUM(fl.amount - fl.amount_settled) > 0
    ORDER  BY outstanding_balance DESC
    LIMIT  100
),

-- ----------------------------------------------------------------
-- CTE 5: Collections due within 30 / 60 / 90 days
-- Uses: idx_ledger_due_date
-- ----------------------------------------------------------------
collections_pipeline AS (
    SELECT
        SUM(fl.amount - fl.amount_settled) FILTER (
            WHERE fl.due_date BETWEEN CURRENT_TIMESTAMP AND CURRENT_TIMESTAMP + INTERVAL '30 days'
        )                                                    AS due_30_days,
        SUM(fl.amount - fl.amount_settled) FILTER (
            WHERE fl.due_date BETWEEN CURRENT_TIMESTAMP AND CURRENT_TIMESTAMP + INTERVAL '60 days'
        )                                                    AS due_60_days,
        SUM(fl.amount - fl.amount_settled) FILTER (
            WHERE fl.due_date BETWEEN CURRENT_TIMESTAMP AND CURRENT_TIMESTAMP + INTERVAL '90 days'
        )                                                    AS due_90_days,
        SUM(fl.amount - fl.amount_settled) FILTER (
            WHERE fl.due_date < CURRENT_TIMESTAMP
            AND   fl.settled_at IS NULL
        )                                                    AS already_overdue
    FROM   financial_ledger fl
    WHERE  fl.type        = 'invoice'
    AND    fl.settled_at  IS NULL
    AND    fl.amount_settled < fl.amount
),

-- ----------------------------------------------------------------
-- CTE 6: YTD Revenue Performance partitioned by Fee Type and Month
-- Uses: idx_ledger_feetype_date
-- ----------------------------------------------------------------
ytd_revenue AS (
    SELECT
        fl.fee_type,
        TO_CHAR(DATE_TRUNC('month', fl.created_at), 'YYYY-MM') AS month,
        SUM(fl.amount) FILTER (WHERE fl.type = 'invoice')       AS invoiced_amount,
        SUM(fl.amount) FILTER (WHERE fl.type = 'payment')       AS collected_amount,
        SUM(fl.amount) FILTER (WHERE fl.type = 'waiver')        AS waived_amount,
        SUM(fl.amount) FILTER (WHERE fl.type = 'refund')        AS refunded_amount,
        ROUND(
            CASE
                WHEN SUM(fl.amount) FILTER (WHERE fl.type = 'invoice') > 0
                THEN (
                    SUM(fl.amount) FILTER (WHERE fl.type = 'payment')
                    /
                    SUM(fl.amount) FILTER (WHERE fl.type = 'invoice')
                ) * 100
                ELSE 0
            END,
            2
        )                                                        AS collection_rate_pct
    FROM   financial_ledger fl
    WHERE  EXTRACT(YEAR FROM fl.created_at) = EXTRACT(YEAR FROM CURRENT_DATE)
    GROUP  BY fl.fee_type, DATE_TRUNC('month', fl.created_at)
    ORDER  BY DATE_TRUNC('month', fl.created_at), fl.fee_type
),

-- ----------------------------------------------------------------
-- CTE 7: Overall YTD collection rate
-- ----------------------------------------------------------------
ytd_totals AS (
    SELECT
        COALESCE(SUM(amount) FILTER (WHERE type = 'invoice'), 0)  AS total_invoiced_ytd,
        COALESCE(SUM(amount) FILTER (WHERE type = 'payment'), 0)  AS total_collected_ytd,
        COALESCE(SUM(amount) FILTER (WHERE type = 'waiver'),  0)  AS total_waived_ytd,
        COALESCE(SUM(amount) FILTER (WHERE type = 'refund'),  0)  AS total_refunded_ytd,
        ROUND(
            CASE
                WHEN COALESCE(SUM(amount) FILTER (WHERE type = 'invoice'), 0) > 0
                THEN (
                    COALESCE(SUM(amount) FILTER (WHERE type = 'payment'), 0)
                    /
                    COALESCE(SUM(amount) FILTER (WHERE type = 'invoice'), 1)
                ) * 100
                ELSE 0
            END,
            2
        )                                                          AS ytd_collection_rate_pct
    FROM   financial_ledger
    WHERE  EXTRACT(YEAR FROM created_at) = EXTRACT(YEAR FROM CURRENT_DATE)
)

-- ----------------------------------------------------------------
-- FINAL: Compose full dashboard payload as JSON
-- ----------------------------------------------------------------
SELECT json_build_object(

    -- Headline Member Counts
    'headline', (SELECT row_to_json(hc) FROM headline_counts hc),

    -- Membership breakdown by status × type
    'membership_summary', (
        SELECT json_agg(row_to_json(ms))
        FROM   membership_summary ms
    ),

    -- Month-on-Month growth trend (last 12 months)
    'mom_growth', (
        SELECT json_agg(row_to_json(mg))
        FROM   mom_growth mg
    ),

    -- Top 100 members with outstanding balances
    'outstanding_balances', (
        SELECT json_agg(row_to_json(ob))
        FROM   outstanding_per_member ob
    ),

    -- Collections pipeline (amounts due in 30/60/90 days + already overdue)
    'collections_pipeline', (
        SELECT row_to_json(cp)
        FROM   collections_pipeline cp
    ),

    -- YTD revenue by fee type × month
    'ytd_revenue_by_fee_type', (
        SELECT json_agg(row_to_json(yr))
        FROM   ytd_revenue yr
    ),

    -- YTD totals & overall collection rate
    'ytd_totals', (
        SELECT row_to_json(yt)
        FROM   ytd_totals yt
    ),

    -- Metadata
    'currency',     'UGX',
    'report_year',  EXTRACT(YEAR FROM CURRENT_DATE)::int,
    'generated_at', NOW()

) AS dashboard_payload;
