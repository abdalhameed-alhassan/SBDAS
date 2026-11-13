-- ============================================================
-- SBDAS — ملف قاعدة البيانات لنسخة النشر (Aiven MySQL / أي MySQL 8)
-- ============================================================
-- يُستورد على قاعدة فارغة موجودة مسبقاً (اسمها في DB_NAME، مثلاً sbdas).
-- لا يحتوي DROP DATABASE ولا CREATE DATABASE ولا USE، ولا يمسّ قواعد أخرى.
-- كلمات مرور المستخدمين: مشفّرة بقيمة عشوائية غير معروفة لأحد، فلا يمكن الدخول
-- بأسماء المستخدمين من الواجهة؛ الدخول يتم عبر زرّي الدخول التجريبي (DEMO_LOGIN=1)،
-- ولتفعيل حساب بكلمة مرور استعمل tools/reset_password.php.
-- يضيف جدول sessions (تخزين الجلسات في القاعدة لأن Vercel بلا ملفات دائمة).
-- ============================================================

SET NAMES utf8mb4;
SET time_zone = '+03:00';
SET FOREIGN_KEY_CHECKS = 0;


-- ============================================================
-- 1) الجداول
-- ============================================================

-- ── الجداول الأساسية: الفروع والمستخدمون والعملاء والحسابات ──

CREATE TABLE branches (
    branch_id    INT AUTO_INCREMENT PRIMARY KEY,
    branch_name  VARCHAR(100) NOT NULL,
    city         VARCHAR(60)  NOT NULL,
    state        VARCHAR(60)  NOT NULL,
    manager_name VARCHAR(100),
    phone        VARCHAR(20),
    address      VARCHAR(200),
    is_active    TINYINT(1) DEFAULT 1,
    created_at   DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE users (
    user_id       INT AUTO_INCREMENT PRIMARY KEY,
    branch_id     INT,
    full_name     VARCHAR(100) NOT NULL,
    username      VARCHAR(60)  NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    role          ENUM('admin','branch_employee','branch_manager','executive') NOT NULL,
    is_active     TINYINT(1) DEFAULT 1,
    created_at    DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (branch_id) REFERENCES branches(branch_id)
) ENGINE=InnoDB;

CREATE TABLE customers (
    customer_id INT AUTO_INCREMENT PRIMARY KEY,
    branch_id   INT NOT NULL,
    full_name   VARCHAR(100) NOT NULL,
    national_id VARCHAR(20)  NOT NULL UNIQUE,
    phone       VARCHAR(20),
    email       VARCHAR(100),
    address     TEXT,
    gender      ENUM('male','female') DEFAULT 'male',
    is_active   TINYINT(1) DEFAULT 1,
    created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (branch_id) REFERENCES branches(branch_id)
) ENGINE=InnoDB;

CREATE TABLE accounts (
    account_id   INT AUTO_INCREMENT PRIMARY KEY,
    customer_id  INT NOT NULL,
    branch_id    INT NOT NULL,
    account_type ENUM('savings','current','investment') NOT NULL DEFAULT 'savings',
    balance      DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    status       ENUM('active','frozen','closed') NOT NULL DEFAULT 'active',
    opened_at    DATETIME DEFAULT CURRENT_TIMESTAMP,
    closed_at    DATETIME NULL,
    FOREIGN KEY (customer_id) REFERENCES customers(customer_id),
    FOREIGN KEY (branch_id)   REFERENCES branches(branch_id)
) ENGINE=InnoDB;

-- ── الحركات المالية والتحويلات ──
-- channel: من أين جاءت العملية | sub_type: نوع مفصّل (cash/cheque/app_transfer/...)
CREATE TABLE transactions (
    txn_id       INT AUTO_INCREMENT PRIMARY KEY,
    account_id   INT NOT NULL,
    branch_id    INT NOT NULL,
    txn_type     ENUM('deposit','withdrawal','transfer_in','transfer_out') NOT NULL,
    sub_type     ENUM('cash','cheque','app_transfer','web_transfer','internal_transfer','bban','atm','pos') NOT NULL DEFAULT 'cash',
    channel      ENUM('branch','app','web','atm','pos') NOT NULL DEFAULT 'branch',
    amount       DECIMAL(15,2) NOT NULL,
    balance_after DECIMAL(15,2) NOT NULL,
    status       ENUM('completed','reversed','cancelled') NOT NULL DEFAULT 'completed',
    note         VARCHAR(255),
    created_at   DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (account_id) REFERENCES accounts(account_id),
    FOREIGN KEY (branch_id)  REFERENCES branches(branch_id)
) ENGINE=InnoDB;

CREATE TABLE internal_transfers (
    itransfer_id    INT AUTO_INCREMENT PRIMARY KEY,
    from_account_id INT NOT NULL,
    to_account_id   INT NOT NULL,
    from_branch_id  INT NOT NULL,
    to_branch_id    INT NOT NULL,
    amount          DECIMAL(15,2) NOT NULL,
    transfer_type   ENUM('same_branch','inter_branch') NOT NULL,
    channel         ENUM('branch','app','web') NOT NULL DEFAULT 'branch',
    status          ENUM('completed','reversed') DEFAULT 'completed',
    created_at      DATETIME NOT NULL,
    FOREIGN KEY (from_account_id) REFERENCES accounts(account_id),
    FOREIGN KEY (to_account_id)   REFERENCES accounts(account_id),
    FOREIGN KEY (from_branch_id)  REFERENCES branches(branch_id),
    FOREIGN KEY (to_branch_id)    REFERENCES branches(branch_id)
) ENGINE=InnoDB;

-- external_transfers: التحويلات مع البنوك الأخرى (BBAN)
CREATE TABLE external_transfers (
    ext_id        INT AUTO_INCREMENT PRIMARY KEY,
    account_id    INT NOT NULL,
    branch_id     INT NOT NULL,
    direction     ENUM('inbound','outbound') NOT NULL,
    external_bank VARCHAR(100) NOT NULL,
    bban          VARCHAR(30)  DEFAULT NULL,  -- رقم الحساب البنكي الأساسي السوداني
    amount        DECIMAL(15,2) NOT NULL,
    currency      VARCHAR(10) DEFAULT 'SDG',
    channel       ENUM('branch','app','bban') NOT NULL DEFAULT 'bban',
    status        ENUM('completed','pending','rejected') DEFAULT 'completed',
    created_at    DATETIME NOT NULL,
    FOREIGN KEY (account_id) REFERENCES accounts(account_id),
    FOREIGN KEY (branch_id)  REFERENCES branches(branch_id)
) ENGINE=InnoDB;

-- ── القروض والشكاوى والتنبيهات ──
CREATE TABLE loans (
    loan_id     INT AUTO_INCREMENT PRIMARY KEY,
    customer_id INT NOT NULL,
    branch_id   INT NOT NULL,
    amount      DECIMAL(15,2) NOT NULL,
    paid_amount DECIMAL(15,2) NOT NULL DEFAULT 0,
    status      ENUM('active','paid','defaulted') NOT NULL DEFAULT 'active',
    issued_at   DATE NOT NULL,
    due_at      DATE NOT NULL,
    FOREIGN KEY (customer_id) REFERENCES customers(customer_id),
    FOREIGN KEY (branch_id)   REFERENCES branches(branch_id)
) ENGINE=InnoDB;

CREATE TABLE complaints (
    complaint_id INT AUTO_INCREMENT PRIMARY KEY,
    customer_id  INT NOT NULL,
    branch_id    INT NOT NULL,
    subject      VARCHAR(200) NOT NULL,
    status       ENUM('open','in_progress','resolved','closed') DEFAULT 'open',
    opened_at    DATETIME DEFAULT CURRENT_TIMESTAMP,
    resolved_at  DATETIME NULL,
    FOREIGN KEY (customer_id) REFERENCES customers(customer_id),
    FOREIGN KEY (branch_id)   REFERENCES branches(branch_id)
) ENGINE=InnoDB;

CREATE TABLE notifications (
    notif_id  INT AUTO_INCREMENT PRIMARY KEY,
    branch_id INT,
    type      ENUM('warning','alert','info') DEFAULT 'info',
    title     VARCHAR(200) NOT NULL,
    message   TEXT NOT NULL,
    is_read   TINYINT(1) DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (branch_id) REFERENCES branches(branch_id)
) ENGINE=InnoDB;

-- ── القنوات الرقمية: مستخدمو التطبيق وجلساته ──
CREATE TABLE app_users (
    app_user_id   INT AUTO_INCREMENT PRIMARY KEY,
    customer_id   INT NOT NULL,
    device_type   ENUM('android','ios','web') NOT NULL DEFAULT 'android',
    registered_at DATETIME NOT NULL,
    last_login    DATETIME,
    is_active     TINYINT(1) DEFAULT 1,
    FOREIGN KEY (customer_id) REFERENCES customers(customer_id)
) ENGINE=InnoDB;

CREATE TABLE app_sessions (
    session_id  INT AUTO_INCREMENT PRIMARY KEY,
    app_user_id INT NOT NULL,
    branch_id   INT NOT NULL,
    action_type ENUM('login','view_balance','transfer','payment','deposit_request','loan_inquiry','complaint') NOT NULL,
    amount      DECIMAL(15,2) DEFAULT NULL,
    created_at  DATETIME NOT NULL,
    FOREIGN KEY (app_user_id) REFERENCES app_users(app_user_id),
    FOREIGN KEY (branch_id)   REFERENCES branches(branch_id)
) ENGINE=InnoDB;

SET FOREIGN_KEY_CHECKS = 1;

-- ============================================================
-- 2) الفهارس (لتسريع الفلترة بالتاريخ والفرع في صفحات التقارير)
-- ============================================================
ALTER TABLE transactions
    ADD INDEX idx_txn_branch_date  (branch_id, created_at),
    ADD INDEX idx_txn_account_date (account_id, created_at),
    ADD INDEX idx_txn_status_type  (status, txn_type);
ALTER TABLE customers          ADD INDEX idx_cust_branch_date      (branch_id, created_at);
ALTER TABLE loans              ADD INDEX idx_loan_branch_date      (branch_id, issued_at);
ALTER TABLE complaints         ADD INDEX idx_complaint_branch_date (branch_id, opened_at);
ALTER TABLE app_sessions       ADD INDEX idx_session_date          (created_at);
ALTER TABLE internal_transfers ADD INDEX idx_itransfer_date        (created_at);
ALTER TABLE external_transfers ADD INDEX idx_extransfer_date       (created_at);

-- ============================================================
-- 3) الـ Views — الصفحات تقرأ من هنا ولا تجمّع من الجداول الخام
-- ============================================================

-- ── ملخص الفروع (يُستخدم في لوحات الإدارة العليا ومدير الفرع) ──
-- ملاحظة تصميم: كل مقياس يُحسب في استعلام فرعي مجمَّع على مستوى الفرع
-- أولاً، ثم تُربط النتائج الصغيرة. ربط الجداول الخام ببعضها مباشرة
-- (عملاء × حسابات × عمليات × قروض × شكاوى) يولّد ضرباً ديكارتياً
-- ويعلّق الاستعلام مع البيانات الكبيرة.
CREATE OR REPLACE VIEW vw_branch_summary AS
SELECT
    b.branch_id, b.branch_name, b.city, b.state,
    COALESCE(cst.total_customers, 0)     AS total_customers,
    COALESCE(acc.total_accounts, 0)      AS total_accounts,
    COALESCE(txn.total_deposits, 0)      AS total_deposits,
    COALESCE(txn.total_withdrawals, 0)   AS total_withdrawals,
    COALESCE(txn.total_transfers, 0)     AS total_transfers,
    COALESCE(txn.total_txn_count, 0)     AS total_txn_count,
    COALESCE(ln.active_loans, 0)         AS active_loans,
    COALESCE(ln.defaulted_loans, 0)      AS defaulted_loans,
    COALESCE(cmp.open_complaints, 0)     AS open_complaints
FROM branches b
LEFT JOIN (
    SELECT branch_id, COUNT(*) AS total_customers
    FROM customers WHERE is_active=1 GROUP BY branch_id
) cst ON cst.branch_id = b.branch_id
LEFT JOIN (
    SELECT branch_id, COUNT(*) AS total_accounts
    FROM accounts GROUP BY branch_id
) acc ON acc.branch_id = b.branch_id
LEFT JOIN (
    SELECT branch_id,
        SUM(CASE WHEN txn_type='deposit'    THEN amount END) AS total_deposits,
        SUM(CASE WHEN txn_type='withdrawal' THEN amount END) AS total_withdrawals,
        SUM(CASE WHEN txn_type IN('transfer_in','transfer_out') THEN amount END) AS total_transfers,
        COUNT(*) AS total_txn_count
    FROM transactions WHERE status='completed' GROUP BY branch_id
) txn ON txn.branch_id = b.branch_id
LEFT JOIN (
    SELECT branch_id,
        COUNT(CASE WHEN status='active'    THEN 1 END) AS active_loans,
        COUNT(CASE WHEN status='defaulted' THEN 1 END) AS defaulted_loans
    FROM loans GROUP BY branch_id
) ln ON ln.branch_id = b.branch_id
LEFT JOIN (
    SELECT branch_id, COUNT(*) AS open_complaints
    FROM complaints WHERE status IN('open','in_progress') GROUP BY branch_id
) cmp ON cmp.branch_id = b.branch_id
WHERE b.is_active=1;

-- ── تفصيل الحركات حسب النوع لكل فرع ──
-- تفصيل السحوبات حسب النوع لكل فرع
CREATE OR REPLACE VIEW vw_branch_withdrawal_breakdown AS
SELECT
    b.branch_id, b.branch_name,
    COALESCE(SUM(CASE WHEN t.sub_type='cash'         THEN t.amount END),0) AS wd_cash,
    COALESCE(SUM(CASE WHEN t.sub_type='cheque'       THEN t.amount END),0) AS wd_cheque,
    COALESCE(SUM(CASE WHEN t.sub_type='app_transfer' THEN t.amount END),0) AS wd_app,
    COALESCE(SUM(CASE WHEN t.sub_type='atm'          THEN t.amount END),0) AS wd_atm,
    COALESCE(SUM(CASE WHEN t.sub_type='pos'          THEN t.amount END),0) AS wd_pos,
    COALESCE(SUM(CASE WHEN t.sub_type='internal_transfer' THEN t.amount END),0) AS wd_internal,
    COUNT(CASE WHEN t.sub_type='cash'         THEN 1 END) AS wd_cash_count,
    COUNT(CASE WHEN t.sub_type='cheque'       THEN 1 END) AS wd_cheque_count,
    COUNT(CASE WHEN t.sub_type='app_transfer' THEN 1 END) AS wd_app_count,
    COUNT(CASE WHEN t.sub_type='atm'          THEN 1 END) AS wd_atm_count,
    COUNT(CASE WHEN t.sub_type='pos'          THEN 1 END) AS wd_pos_count
FROM branches b
LEFT JOIN transactions t ON t.branch_id=b.branch_id
    AND t.txn_type='withdrawal' AND t.status='completed'
WHERE b.is_active=1
GROUP BY b.branch_id, b.branch_name;

-- تفصيل الإيداعات حسب النوع لكل فرع
CREATE OR REPLACE VIEW vw_branch_deposit_breakdown AS
SELECT
    b.branch_id, b.branch_name,
    COALESCE(SUM(CASE WHEN t.sub_type='cash'         THEN t.amount END),0) AS dep_cash,
    COALESCE(SUM(CASE WHEN t.sub_type='cheque'       THEN t.amount END),0) AS dep_cheque,
    COALESCE(SUM(CASE WHEN t.sub_type='app_transfer' THEN t.amount END),0) AS dep_app,
    COALESCE(SUM(CASE WHEN t.sub_type='bban'         THEN t.amount END),0) AS dep_bban,
    COUNT(CASE WHEN t.sub_type='cash'   THEN 1 END) AS dep_cash_count,
    COUNT(CASE WHEN t.sub_type='cheque' THEN 1 END) AS dep_cheque_count,
    COUNT(CASE WHEN t.sub_type='app_transfer' THEN 1 END) AS dep_app_count,
    COUNT(CASE WHEN t.sub_type='bban'   THEN 1 END) AS dep_bban_count
FROM branches b
LEFT JOIN transactions t ON t.branch_id=b.branch_id
    AND t.txn_type='deposit' AND t.status='completed'
WHERE b.is_active=1
GROUP BY b.branch_id, b.branch_name;

-- تفصيل التحويلات حسب النوع
CREATE OR REPLACE VIEW vw_branch_transfer_breakdown AS
SELECT
    b.branch_id, b.branch_name,
    COALESCE(SUM(CASE WHEN t.sub_type='app_transfer'      THEN t.amount END),0) AS tr_app,
    COALESCE(SUM(CASE WHEN t.sub_type='internal_transfer' THEN t.amount END),0) AS tr_internal,
    COALESCE(SUM(CASE WHEN t.sub_type='bban'              THEN t.amount END),0) AS tr_bban,
    COUNT(CASE WHEN t.sub_type='app_transfer'      THEN 1 END) AS tr_app_count,
    COUNT(CASE WHEN t.sub_type='internal_transfer' THEN 1 END) AS tr_internal_count,
    COUNT(CASE WHEN t.sub_type='bban'              THEN 1 END) AS tr_bban_count
FROM branches b
LEFT JOIN transactions t ON t.branch_id=b.branch_id
    AND t.txn_type IN('transfer_in','transfer_out') AND t.status='completed'
WHERE b.is_active=1
GROUP BY b.branch_id, b.branch_name;

-- ── أكثر الحسابات تحويلاً ونشاطاً ──
-- (للبيانات الضخمة: استخدمي bigdata_tuning.sql لتحويلهما إلى جدول مجمَّع جاهز)
CREATE OR REPLACE VIEW vw_top_accounts_by_transfers AS
SELECT a.account_id, cu.full_name AS customer_name, b.branch_name,
    COUNT(t.txn_id) AS transfer_count,
    SUM(t.amount)   AS total_transferred
FROM accounts a
JOIN customers cu ON cu.customer_id=a.customer_id
JOIN branches  b  ON b.branch_id=a.branch_id
JOIN transactions t ON t.account_id=a.account_id
    AND t.txn_type IN('transfer_in','transfer_out') AND t.status='completed'
GROUP BY a.account_id,cu.full_name,b.branch_name
ORDER BY total_transferred DESC LIMIT 10;

CREATE OR REPLACE VIEW vw_top_accounts_by_activity AS
SELECT a.account_id, cu.full_name AS customer_name, b.branch_name,
    COUNT(t.txn_id) AS total_operations,
    SUM(t.amount)   AS total_volume,
    SUM(CASE WHEN t.txn_type='deposit'    THEN t.amount END) AS vol_deposit,
    SUM(CASE WHEN t.txn_type='withdrawal' THEN t.amount END) AS vol_withdrawal,
    SUM(CASE WHEN t.txn_type IN('transfer_in','transfer_out') THEN t.amount END) AS vol_transfer,
    COUNT(CASE WHEN t.txn_type='deposit'    THEN 1 END) AS cnt_deposit,
    COUNT(CASE WHEN t.txn_type='withdrawal' THEN 1 END) AS cnt_withdrawal,
    COUNT(CASE WHEN t.txn_type IN('transfer_in','transfer_out') THEN 1 END) AS cnt_transfer
FROM accounts a
JOIN customers cu ON cu.customer_id=a.customer_id
JOIN branches  b  ON b.branch_id=a.branch_id
JOIN transactions t ON t.account_id=a.account_id AND t.status='completed'
GROUP BY a.account_id,cu.full_name,b.branch_name
ORDER BY total_volume DESC LIMIT 10;

-- ── القنوات الرقمية والتحويلات الداخلية والخارجية ──
CREATE OR REPLACE VIEW vw_app_summary AS
SELECT
    COUNT(DISTINCT au.app_user_id) AS total_app_users,
    COUNT(DISTINCT CASE WHEN au.is_active=1 THEN au.app_user_id END) AS active_app_users,
    COUNT(DISTINCT CASE WHEN au.device_type='android' THEN au.app_user_id END) AS android_users,
    COUNT(DISTINCT CASE WHEN au.device_type='ios'     THEN au.app_user_id END) AS ios_users,
    COUNT(DISTINCT CASE WHEN au.device_type='web'     THEN au.app_user_id END) AS web_users,
    COUNT(DISTINCT CASE WHEN au.last_login >= DATE_SUB(NOW(),INTERVAL 30 DAY) THEN au.app_user_id END) AS active_last_30d,
    COUNT(s.session_id) AS total_sessions,
    COUNT(CASE WHEN s.action_type='transfer' THEN 1 END) AS app_transfers,
    COALESCE(SUM(CASE WHEN s.action_type='transfer' THEN s.amount END),0) AS app_transfer_volume
FROM app_users au
LEFT JOIN app_sessions s ON s.app_user_id=au.app_user_id;

CREATE OR REPLACE VIEW vw_channel_comparison AS
SELECT 'تطبيق جوال / ويب' AS channel,
    COUNT(*) AS operation_count,
    COALESCE(SUM(amount),0) AS total_volume
FROM app_sessions WHERE action_type IN('transfer','payment','deposit_request')
UNION ALL
SELECT 'فروع مباشرة', COUNT(*), COALESCE(SUM(amount),0)
FROM transactions
WHERE status='completed' AND txn_type IN('deposit','withdrawal','transfer_out');

CREATE OR REPLACE VIEW vw_app_monthly AS
SELECT DATE_FORMAT(created_at,'%Y-%m') AS month,
    COUNT(*) AS sessions,
    COUNT(DISTINCT app_user_id) AS unique_users,
    COUNT(CASE WHEN action_type='transfer' THEN 1 END) AS transfers
FROM app_sessions
GROUP BY DATE_FORMAT(created_at,'%Y-%m');

CREATE OR REPLACE VIEW vw_internal_transfer_summary AS
SELECT
    transfer_type,
    channel,
    COUNT(*)     AS count,
    SUM(amount)  AS total_amount,
    AVG(amount)  AS avg_amount
FROM internal_transfers
WHERE status='completed'
GROUP BY transfer_type, channel;

-- ── نمو العملاء الشهري ──
CREATE OR REPLACE VIEW vw_monthly_growth AS
SELECT
    DATE_FORMAT(created_at,'%Y-%m') AS month,
    COUNT(*) AS new_customers
FROM customers
WHERE is_active=1
GROUP BY DATE_FORMAT(created_at,'%Y-%m');

CREATE OR REPLACE VIEW vw_external_bank_summary AS
SELECT external_bank,
    SUM(CASE WHEN direction='inbound'  THEN amount ELSE 0 END) AS total_inbound,
    SUM(CASE WHEN direction='outbound' THEN amount ELSE 0 END) AS total_outbound,
    SUM(CASE WHEN direction='inbound'  THEN amount ELSE 0 END)
    - SUM(CASE WHEN direction='outbound' THEN amount ELSE 0 END) AS net_balance,
    COUNT(CASE WHEN direction='inbound'  THEN 1 END) AS inbound_count,
    COUNT(CASE WHEN direction='outbound' THEN 1 END) AS outbound_count
FROM external_transfers WHERE status='completed'
GROUP BY external_bank;

CREATE OR REPLACE VIEW vw_external_flow_monthly AS
SELECT DATE_FORMAT(created_at,'%Y-%m') AS month,
    SUM(CASE WHEN direction='inbound'  THEN amount ELSE 0 END) AS inbound,
    SUM(CASE WHEN direction='outbound' THEN amount ELSE 0 END) AS outbound
FROM external_transfers WHERE status='completed'
GROUP BY DATE_FORMAT(created_at,'%Y-%m');

CREATE OR REPLACE VIEW vw_inter_branch_flow AS
SELECT b.branch_name,
    COALESCE(SUM(CASE WHEN it.from_branch_id=b.branch_id THEN it.amount END),0) AS sent,
    COALESCE(SUM(CASE WHEN it.to_branch_id=b.branch_id   THEN it.amount END),0) AS received,
    COALESCE(SUM(CASE WHEN it.to_branch_id=b.branch_id   THEN it.amount END),0)
    - COALESCE(SUM(CASE WHEN it.from_branch_id=b.branch_id THEN it.amount END),0) AS net_flow
FROM branches b
LEFT JOIN internal_transfers it
    ON (it.from_branch_id=b.branch_id OR it.to_branch_id=b.branch_id)
    AND it.transfer_type='inter_branch' AND it.status='completed'
WHERE b.is_active=1
GROUP BY b.branch_id,b.branch_name;

-- ============================================================
-- 4) البيانات التجريبية
-- بنك متوسط الحجم: 8 فروع، 80 عميلاً، مبالغ بالجنيه السوداني
-- (سعر الدولار المفترض 5500 جنيه). بيانات منطقية ومترابطة لأغراض
-- الاختبار والمحاكاة وليست بيانات بنك حقيقي.
-- ============================================================

INSERT INTO branches (branch_name,city,state,manager_name,phone,address) VALUES
('الفرع الرئيسي',      'الخرطوم',       'الخرطوم',     'المهندس أحمد محمد النور',  '0912345678','شارع الجمهورية، المركز التجاري'),
('فرع أم درمان',       'أم درمان',      'الخرطوم',     'الأستاذة فاطمة عبدالله',   '0923456789','السوق الكبير، شارع النيل'),
('فرع الخرطوم بحري',  'الخرطوم بحري', 'الخرطوم',     'المهندس عمر إبراهيم حسن',  '0934567890','الحي الصناعي، شارع المطار'),
('فرع بورتسودان',      'بورتسودان',    'البحر الأحمر', 'الأستاذة سارة يوسف علي',   '0945678901','شارع الميناء التجاري'),
('فرع ود مدني',        'ود مدني',       'الجزيرة',     'الأستاذ خالد حسن عبدالله', '0956789012','شارع الأربعين'),
('فرع الأبيض',         'الأبيض',        'شمال كردفان', 'الأستاذ محمود يوسف',        '0967890123','السوق المركزي'),
('فرع كسلا',           'كسلا',          'كسلا',        'الأستاذة هدى أحمد',         '0978901234','شارع الخريجين'),
('فرع عطبرة',          'عطبرة',         'نهر النيل',   'الأستاذ الصادق علي',        '0989012345','شارع المحطة');

-- كلمة المرور: قيمة عشوائية غير معروفة (انظر رأس الملف)
INSERT INTO users (branch_id,full_name,username,password_hash,role) VALUES
(1,'مدير النظام',              'admin',    '$2y$10$4fYtkNbYqO4HTyZCHERKCuKD4Psj9q6Si56SASH1ytfXD5NkBGQIW','admin'),
(1,'الإدارة العليا',           'executive','$2y$10$4fYtkNbYqO4HTyZCHERKCuKD4Psj9q6Si56SASH1ytfXD5NkBGQIW','executive'),
(1,'مدير الفرع الرئيسي',      'manager1', '$2y$10$4fYtkNbYqO4HTyZCHERKCuKD4Psj9q6Si56SASH1ytfXD5NkBGQIW','branch_manager'),
(2,'مدير فرع أم درمان',       'manager2', '$2y$10$4fYtkNbYqO4HTyZCHERKCuKD4Psj9q6Si56SASH1ytfXD5NkBGQIW','branch_manager'),
(3,'مدير فرع الخرطوم بحري',   'manager3', '$2y$10$4fYtkNbYqO4HTyZCHERKCuKD4Psj9q6Si56SASH1ytfXD5NkBGQIW','branch_manager'),
(4,'مدير فرع بورتسودان',      'manager4', '$2y$10$4fYtkNbYqO4HTyZCHERKCuKD4Psj9q6Si56SASH1ytfXD5NkBGQIW','branch_manager'),
(5,'مدير فرع ود مدني',        'manager5', '$2y$10$4fYtkNbYqO4HTyZCHERKCuKD4Psj9q6Si56SASH1ytfXD5NkBGQIW','branch_manager'),
(6,'مدير فرع الأبيض',         'manager6', '$2y$10$4fYtkNbYqO4HTyZCHERKCuKD4Psj9q6Si56SASH1ytfXD5NkBGQIW','branch_manager'),
(7,'مدير فرع كسلا',           'manager7', '$2y$10$4fYtkNbYqO4HTyZCHERKCuKD4Psj9q6Si56SASH1ytfXD5NkBGQIW','branch_manager'),
(8,'مدير فرع عطبرة',          'manager8', '$2y$10$4fYtkNbYqO4HTyZCHERKCuKD4Psj9q6Si56SASH1ytfXD5NkBGQIW','branch_manager'),
(1,'موظف الفرع الرئيسي',      'emp1',     '$2y$10$4fYtkNbYqO4HTyZCHERKCuKD4Psj9q6Si56SASH1ytfXD5NkBGQIW','branch_employee'),
(2,'موظف فرع أم درمان',       'emp2',     '$2y$10$4fYtkNbYqO4HTyZCHERKCuKD4Psj9q6Si56SASH1ytfXD5NkBGQIW','branch_employee');

-- ============================================================
-- 80 عميل موزعين على 8 فروع (واقعي لبنك سوداني متوسط)
-- الفرع الرئيسي وأم درمان الأكبر
-- ============================================================
INSERT INTO customers (branch_id,full_name,national_id,phone,gender,created_at) VALUES
-- فرع 1: الفرع الرئيسي (20 عميل)
(1,'محمد أحمد إبراهيم النور',   '11100001','0911000001','male',  '2023-01-15 09:00:00'),
(1,'فاطمة عبدالله محمد',        '11100002','0911000002','female','2023-02-10 10:00:00'),
(1,'عمر خالد حسن',              '11100003','0911000003','male',  '2023-03-05 09:00:00'),
(1,'زينب يوسف علي',             '11100004','0911000004','female','2023-04-20 10:00:00'),
(1,'طارق محمود عثمان',          '11100005','0911000005','male',  '2023-05-12 09:00:00'),
(1,'نادية إبراهيم أحمد',        '11100006','0911000006','female','2023-06-08 10:00:00'),
(1,'الصادق علي محمد',           '11100007','0911000007','male',  '2023-07-03 09:00:00'),
(1,'آمنة حسن عبدالله',          '11100008','0911000008','female','2023-08-15 10:00:00'),
(1,'يوسف عمر الطيب',            '11100009','0911000009','male',  '2023-09-22 09:00:00'),
(1,'سارة محمد إبراهيم',         '11100010','0911000010','female','2023-10-10 10:00:00'),
(1,'خالد عبدالرحمن موسى',       '11100011','0911000011','male',  '2023-11-05 09:00:00'),
(1,'مريم أحمد حسين',            '11100012','0911000012','female','2023-12-18 10:00:00'),
(1,'إبراهيم يوسف بكر',          '11100013','0911000013','male',  '2024-01-25 09:00:00'),
(1,'هدى خالد عمر',              '11100014','0911000014','female','2024-02-14 10:00:00'),
(1,'عبدالله أحمد النور',         '11100015','0911000015','male',  '2024-03-08 09:00:00'),
(1,'رقية محمد علي',             '11100016','0911000016','female','2024-04-19 10:00:00'),
(1,'التجاني عبدالله حسن',       '11100017','0911000017','male',  '2024-05-30 09:00:00'),
(1,'أسماء إبراهيم يوسف',        '11100018','0911000018','female','2024-06-15 10:00:00'),
(1,'محمود خالد الصادق',         '11100019','0911000019','male',  '2024-09-10 09:00:00'),
(1,'حنان عمر عبدالله',          '11100020','0911000020','female','2025-01-20 10:00:00'),
-- فرع 2: أم درمان (18 عميل)
(2,'عبدالله حسين محمد',         '11200001','0922000001','male',  '2023-02-01 09:00:00'),
(2,'مريم عثمان أحمد',           '11200002','0922000002','female','2023-03-15 10:00:00'),
(2,'خالد يوسف النور',           '11200003','0922000003','male',  '2023-04-10 09:00:00'),
(2,'أسماء بكر إبراهيم',         '11200004','0922000004','female','2023-05-25 10:00:00'),
(2,'سليمان أحمد خليل',          '11200005','0922000005','male',  '2023-07-08 09:00:00'),
(2,'حنان عبدالله موسى',         '11200006','0922000006','female','2023-08-20 10:00:00'),
(2,'نور الدين محمد علي',        '11200007','0922000007','male',  '2023-10-05 09:00:00'),
(2,'سمية خالد حسن',             '11200008','0922000008','female','2023-11-18 10:00:00'),
(2,'أحمد الطيب عمر',            '11200009','0922000009','male',  '2024-01-12 09:00:00'),
(2,'فدوى يوسف أحمد',            '11200010','0922000010','female','2024-03-05 10:00:00'),
(2,'عزيز محمد إبراهيم',         '11200011','0922000011','male',  '2024-05-20 09:00:00'),
(2,'إيمان الطيب خالد',          '11200012','0922000012','female','2024-07-15 10:00:00'),
(2,'الفاتح أحمد عبدالله',       '11200013','0922000013','male',  '2024-09-01 09:00:00'),
(2,'بشرى عمر محمد',             '11200014','0922000014','female','2024-10-18 10:00:00'),
(2,'ماهر خالد النور',           '11200015','0922000015','male',  '2024-11-25 09:00:00'),
(2,'وفاء إبراهيم حسين',         '11200016','0922000016','female','2025-01-08 10:00:00'),
(2,'الهادي محمد أحمد',          '11200017','0922000017','male',  '2025-03-15 09:00:00'),
(2,'دينا عبدالله علي',          '11200018','0922000018','female','2025-05-20 10:00:00'),
-- فرع 3: الخرطوم بحري (12 عميل)
(3,'إبراهيم موسى حسن',          '11300001','0933000001','male',  '2023-03-10 09:00:00'),
(3,'رقية حسن أحمد',             '11300002','0933000002','female','2023-05-22 10:00:00'),
(3,'نور الهدى عمر',             '11300003','0933000003','female','2023-08-14 09:00:00'),
(3,'عائشة علي محمد',            '11300004','0933000004','female','2023-11-05 10:00:00'),
(3,'مصطفى أحمد يوسف',           '11300005','0933000005','male',  '2024-01-20 09:00:00'),
(3,'سميرة خالد إبراهيم',        '11300006','0933000006','female','2024-04-08 10:00:00'),
(3,'الأمين محمد حسن',           '11300007','0933000007','male',  '2024-06-15 09:00:00'),
(3,'زهرة يوسف عبدالله',         '11300008','0933000008','female','2024-08-25 10:00:00'),
(3,'كمال أحمد عمر',             '11300009','0933000009','male',  '2024-10-10 09:00:00'),
(3,'نجوى إبراهيم محمد',         '11300010','0933000010','female','2025-01-15 10:00:00'),
(3,'ياسر خالد النور',           '11300011','0933000011','male',  '2025-03-20 09:00:00'),
(3,'لمياء عبدالله أحمد',        '11300012','0933000012','female','2025-05-05 10:00:00'),
-- فرع 4: بورتسودان (10 عملاء)
(4,'محمود عبدالله يوسف',        '11400001','0944000001','male',  '2023-04-05 09:00:00'),
(4,'نجاة يوسف محمد',            '11400002','0944000002','female','2023-07-18 10:00:00'),
(4,'الصادق حسين أحمد',          '11400003','0944000003','male',  '2023-10-22 09:00:00'),
(4,'فريدة إبراهيم خالد',        '11400004','0944000004','female','2024-02-10 10:00:00'),
(4,'عادل محمد عمر',             '11400005','0944000005','male',  '2024-05-15 09:00:00'),
(4,'سهير أحمد النور',           '11400006','0944000006','female','2024-08-20 10:00:00'),
(4,'بكري خالد حسن',             '11400007','0944000007','male',  '2024-11-05 09:00:00'),
(4,'أمل يوسف إبراهيم',          '11400008','0944000008','female','2025-02-18 10:00:00'),
(4,'الحسن محمد أحمد',           '11400009','0944000009','male',  '2025-04-10 09:00:00'),
(4,'مها عبدالله محمد',          '11400010','0944000010','female','2025-06-01 10:00:00'),
-- فرع 5: ود مدني (8 عملاء)
(5,'بشرى عمر الطيب',            '11500001','0955000001','female','2023-05-12 09:00:00'),
(5,'التجاني علي أحمد',          '11500002','0955000002','male',  '2023-09-05 10:00:00'),
(5,'هدى أحمد إبراهيم',          '11500003','0955000003','female','2024-01-18 09:00:00'),
(5,'المصطفى عبدالرحمن',         '11500004','0955000004','male',  '2024-04-22 10:00:00'),
(5,'زهرة حسن محمد',             '11500005','0955000005','female','2024-07-10 09:00:00'),
(5,'أنور خالد يوسف',            '11500006','0955000006','male',  '2024-10-15 10:00:00'),
(5,'إيمان الطيب عمر',           '11500007','0955000007','female','2025-02-05 09:00:00'),
(5,'سامي إبراهيم أحمد',         '11500008','0955000008','male',  '2025-05-18 10:00:00'),
-- فرع 6: الأبيض (6 عملاء)
(6,'عوض الله محمد أحمد',        '11600001','0966000001','male',  '2023-06-08 09:00:00'),
(6,'منى خالد عبدالله',          '11600002','0966000002','female','2023-10-20 10:00:00'),
(6,'حمزة يوسف النور',           '11600003','0966000003','male',  '2024-03-15 09:00:00'),
(6,'سلوى أحمد إبراهيم',         '11600004','0966000004','female','2024-08-10 10:00:00'),
(6,'مجدي عبدالله حسن',          '11600005','0966000005','male',  '2025-01-22 09:00:00'),
(6,'نوال محمد علي',             '11600006','0966000006','female','2025-05-08 10:00:00'),
-- فرع 7: كسلا (4 عملاء)
(7,'حسين أحمد محمد',            '11700001','0977000001','male',  '2023-08-15 09:00:00'),
(7,'عزة إبراهيم يوسف',          '11700002','0977000002','female','2024-02-22 10:00:00'),
(7,'أسامة خالد عمر',            '11700003','0977000003','male',  '2024-09-10 09:00:00'),
(7,'نهى عبدالله أحمد',          '11700004','0977000004','female','2025-03-18 10:00:00'),
-- فرع 8: عطبرة (2 عميل)
(8,'الزين محمد النور',           '11800001','0988000001','male',  '2024-01-10 09:00:00'),
(8,'سناء أحمد خالد',            '11800002','0988000002','female','2024-11-25 10:00:00');

-- ============================================================
-- حسابات: متوسط 1.4 حساب لكل عميل
-- الأرصدة بالجنيه السوداني (دولار 5500 جنيه)
-- متوسط رصيد حساب توفير: 150,000-800,000 جنيه (~27-145 دولار)
-- حسابات الاستثمار والتجاري أعلى بكثير
-- ============================================================
INSERT INTO accounts (customer_id,branch_id,account_type,balance,status,opened_at) VALUES
-- فرع 1
(1,1,'current',    1850000.00,'active','2023-01-15 09:00:00'),
(1,1,'savings',     420000.00,'active','2023-06-01 09:00:00'),
(2,1,'savings',     280000.00,'active','2023-02-10 09:00:00'),
(3,1,'current',     950000.00,'active','2023-03-05 09:00:00'),
(4,1,'investment', 3200000.00,'active','2023-04-20 09:00:00'),
(5,1,'savings',     175000.00,'active','2023-05-12 09:00:00'),
(6,1,'current',     620000.00,'active','2023-06-08 09:00:00'),
(7,1,'savings',     390000.00,'active','2023-07-03 09:00:00'),
(7,1,'investment', 2100000.00,'active','2024-01-01 09:00:00'),
(8,1,'savings',     145000.00,'active','2023-08-15 09:00:00'),
(9,1,'current',    1250000.00,'active','2023-09-22 09:00:00'),
(10,1,'savings',    320000.00,'active','2023-10-10 09:00:00'),
(11,1,'current',    780000.00,'active','2023-11-05 09:00:00'),
(12,1,'savings',    210000.00,'active','2023-12-18 09:00:00'),
(13,1,'investment',4500000.00,'active','2024-01-25 09:00:00'),
(14,1,'savings',    165000.00,'active','2024-02-14 09:00:00'),
(15,1,'current',    890000.00,'active','2024-03-08 09:00:00'),
(16,1,'savings',    240000.00,'active','2024-04-19 09:00:00'),
(17,1,'current',   1100000.00,'active','2024-05-30 09:00:00'),
(18,1,'savings',    195000.00,'active','2024-06-15 09:00:00'),
(19,1,'investment',2800000.00,'active','2024-09-10 09:00:00'),
(20,1,'savings',    130000.00,'active','2025-01-20 09:00:00'),
-- فرع 2
(21,2,'savings',    350000.00,'active','2023-02-01 09:00:00'),
(22,2,'current',   1150000.00,'active','2023-03-15 09:00:00'),
(23,2,'savings',    290000.00,'active','2023-04-10 09:00:00'),
(24,2,'investment',3800000.00,'active','2023-05-25 09:00:00'),
(25,2,'savings',    180000.00,'active','2023-07-08 09:00:00'),
(26,2,'current',    720000.00,'active','2023-08-20 09:00:00'),
(27,2,'savings',    415000.00,'active','2023-10-05 09:00:00'),
(28,2,'current',    980000.00,'active','2023-11-18 09:00:00'),
(29,2,'savings',    225000.00,'active','2024-01-12 09:00:00'),
(30,2,'investment',2500000.00,'active','2024-03-05 09:00:00'),
(31,2,'savings',    155000.00,'active','2024-05-20 09:00:00'),
(32,2,'current',    840000.00,'active','2024-07-15 09:00:00'),
(33,2,'savings',    270000.00,'active','2024-09-01 09:00:00'),
(34,2,'savings',    190000.00,'active','2024-10-18 09:00:00'),
(35,2,'current',    560000.00,'active','2024-11-25 09:00:00'),
(36,2,'savings',    120000.00,'active','2025-01-08 09:00:00'),
(37,2,'savings',    380000.00,'active','2025-03-15 09:00:00'),
(38,2,'savings',     95000.00,'active','2025-05-20 09:00:00'),
-- فرع 3
(39,3,'savings',    310000.00,'active','2023-03-10 09:00:00'),
(40,3,'current',    870000.00,'active','2023-05-22 09:00:00'),
(41,3,'savings',    205000.00,'active','2023-08-14 09:00:00'),
(42,3,'investment',1950000.00,'active','2023-11-05 09:00:00'),
(43,3,'current',    650000.00,'active','2024-01-20 09:00:00'),
(44,3,'savings',    175000.00,'active','2024-04-08 09:00:00'),
(45,3,'savings',    430000.00,'active','2024-06-15 09:00:00'),
(46,3,'current',    920000.00,'active','2024-08-25 09:00:00'),
(47,3,'savings',    260000.00,'active','2024-10-10 09:00:00'),
(48,3,'savings',    140000.00,'active','2025-01-15 09:00:00'),
(49,3,'savings',    320000.00,'active','2025-03-20 09:00:00'),
(50,3,'savings',    180000.00,'active','2025-05-05 09:00:00'),
-- فرع 4
(51,4,'savings',    280000.00,'active','2023-04-05 09:00:00'),
(52,4,'current',    750000.00,'active','2023-07-18 09:00:00'),
(53,4,'savings',    195000.00,'active','2023-10-22 09:00:00'),
(54,4,'investment',1600000.00,'active','2024-02-10 09:00:00'),
(55,4,'current',    480000.00,'active','2024-05-15 09:00:00'),
(56,4,'savings',    155000.00,'active','2024-08-20 09:00:00'),
(57,4,'savings',    220000.00,'active','2024-11-05 09:00:00'),
(57,4,'current',    390000.00,'frozen','2025-01-01 09:00:00'),
(58,4,'savings',    130000.00,'active','2025-02-18 09:00:00'),
(59,4,'savings',    175000.00,'active','2025-04-10 09:00:00'),
(60,4,'savings',     85000.00,'active','2025-06-01 09:00:00'),
-- فرع 5
(61,5,'savings',    245000.00,'active','2023-05-12 09:00:00'),
(62,5,'current',    680000.00,'active','2023-09-05 09:00:00'),
(63,5,'savings',    190000.00,'active','2024-01-18 09:00:00'),
(64,5,'investment',1400000.00,'active','2024-04-22 09:00:00'),
(65,5,'savings',    165000.00,'active','2024-07-10 09:00:00'),
(66,5,'current',    520000.00,'active','2024-10-15 09:00:00'),
(67,5,'savings',    140000.00,'active','2025-02-05 09:00:00'),
(68,5,'savings',    110000.00,'active','2025-05-18 09:00:00'),
-- فرع 6
(69,6,'savings',    220000.00,'active','2023-06-08 09:00:00'),
(70,6,'current',    580000.00,'active','2023-10-20 09:00:00'),
(71,6,'savings',    175000.00,'active','2024-03-15 09:00:00'),
(72,6,'savings',    145000.00,'active','2024-08-10 09:00:00'),
(73,6,'savings',    195000.00,'active','2025-01-22 09:00:00'),
(74,6,'savings',    120000.00,'active','2025-05-08 09:00:00'),
-- فرع 7
(75,7,'savings',    185000.00,'active','2023-08-15 09:00:00'),
(76,7,'current',    420000.00,'active','2024-02-22 09:00:00'),
(77,7,'savings',    160000.00,'active','2024-09-10 09:00:00'),
(78,7,'savings',     95000.00,'active','2025-03-18 09:00:00'),
-- فرع 8
(79,8,'savings',    210000.00,'active','2024-01-10 09:00:00'),
(80,8,'savings',    145000.00,'active','2024-11-25 09:00:00');

-- ============================================================
-- معاملات مالية واقعية (مبالغ بالجنيه السوداني)
-- sub_type يعكس طريقة التنفيذ الفعلية
-- ============================================================
INSERT INTO transactions (account_id,branch_id,txn_type,sub_type,channel,amount,balance_after,created_at) VALUES
-- فرع 1 - حسابات كبيرة
(1,1,'deposit','cash','branch',500000.00,2000000.00,'2025-08-10 09:00:00'),
(1,1,'withdrawal','cheque','branch',200000.00,1800000.00,'2025-09-05 10:00:00'),
(1,1,'transfer_out','bban','branch',150000.00,1650000.00,'2025-10-12 11:00:00'),
(1,1,'deposit','bban','branch',350000.00,2000000.00,'2025-11-08 09:00:00'),
(1,1,'withdrawal','cash','branch',100000.00,1900000.00,'2025-12-15 10:00:00'),
(1,1,'deposit','cheque','branch',250000.00,2150000.00,'2026-01-20 09:00:00'),
(1,1,'transfer_out','bban','branch',500000.00,1650000.00,'2026-02-10 10:00:00'),
(1,1,'deposit','bban','branch',700000.00,2350000.00,'2026-03-05 09:00:00'),
(1,1,'withdrawal','atm','atm',50000.00,2300000.00,'2026-04-08 14:00:00'),
(1,1,'deposit','cash','branch',400000.00,2700000.00,'2026-05-12 09:00:00'),
(1,1,'withdrawal','cheque','branch',800000.00,1900000.00,'2026-06-03 10:00:00'),
(2,1,'deposit','cash','branch',80000.00,280000.00,'2025-09-15 09:00:00'),
(2,1,'withdrawal','cash','branch',30000.00,250000.00,'2025-11-20 10:00:00'),
(2,1,'deposit','app_transfer','app',50000.00,300000.00,'2026-01-10 08:00:00'),
(2,1,'withdrawal','atm','atm',20000.00,280000.00,'2026-03-18 15:00:00'),
(2,1,'deposit','cash','branch',60000.00,340000.00,'2026-05-05 09:00:00'),
(4,1,'deposit','cash','branch',1000000.00,3500000.00,'2025-08-01 09:00:00'),
(4,1,'transfer_out','bban','branch',300000.00,3200000.00,'2025-10-05 10:00:00'),
(4,1,'deposit','bban','branch',800000.00,4000000.00,'2026-01-15 09:00:00'),
(4,1,'withdrawal','cheque','branch',500000.00,3500000.00,'2026-03-10 10:00:00'),
(4,1,'deposit','bban','branch',600000.00,4100000.00,'2026-05-20 09:00:00'),
(6,1,'deposit','cash','branch',150000.00,620000.00,'2025-09-10 09:00:00'),
(6,1,'withdrawal','pos','pos',25000.00,595000.00,'2025-11-05 16:00:00'),
(6,1,'deposit','app_transfer','app',80000.00,675000.00,'2026-01-25 08:00:00'),
(6,1,'withdrawal','cash','branch',100000.00,575000.00,'2026-04-12 10:00:00'),
(9,1,'deposit','bban','branch',400000.00,1250000.00,'2025-10-01 09:00:00'),
(9,1,'transfer_out','bban','branch',200000.00,1050000.00,'2026-01-08 10:00:00'),
(9,1,'deposit','bban','branch',300000.00,1350000.00,'2026-04-20 09:00:00'),
(9,1,'withdrawal','cheque','branch',100000.00,1250000.00,'2026-06-10 10:00:00'),
(13,1,'deposit','bban','branch',2000000.00,4500000.00,'2025-08-15 09:00:00'),
(13,1,'transfer_out','bban','branch',1000000.00,3500000.00,'2025-11-01 10:00:00'),
(13,1,'deposit','bban','branch',1500000.00,5000000.00,'2026-02-15 09:00:00'),
(13,1,'withdrawal','cheque','branch',800000.00,4200000.00,'2026-05-08 10:00:00'),
-- فرع 2
(22,2,'deposit','cash','branch',300000.00,1150000.00,'2025-09-01 09:00:00'),
(22,2,'transfer_out','bban','branch',200000.00,950000.00,'2025-11-15 10:00:00'),
(22,2,'deposit','bban','branch',500000.00,1450000.00,'2026-02-01 09:00:00'),
(22,2,'withdrawal','cheque','branch',300000.00,1150000.00,'2026-04-20 10:00:00'),
(22,2,'deposit','bban','branch',400000.00,1550000.00,'2026-06-05 09:00:00'),
(24,2,'deposit','bban','branch',1500000.00,3800000.00,'2025-08-20 09:00:00'),
(24,2,'transfer_out','bban','branch',800000.00,3000000.00,'2025-11-10 10:00:00'),
(24,2,'deposit','bban','branch',1000000.00,4000000.00,'2026-02-20 09:00:00'),
(24,2,'withdrawal','cheque','branch',600000.00,3400000.00,'2026-05-15 10:00:00'),
(26,2,'deposit','cash','branch',200000.00,720000.00,'2025-10-05 09:00:00'),
(26,2,'withdrawal','atm','atm',50000.00,670000.00,'2025-12-10 14:00:00'),
(26,2,'deposit','app_transfer','app',100000.00,770000.00,'2026-03-01 08:00:00'),
(28,2,'deposit','cash','branch',250000.00,980000.00,'2025-09-15 09:00:00'),
(28,2,'withdrawal','cheque','branch',150000.00,830000.00,'2025-12-05 10:00:00'),
(28,2,'deposit','bban','branch',300000.00,1130000.00,'2026-03-20 09:00:00'),
(28,2,'transfer_out','bban','branch',200000.00,930000.00,'2026-06-01 10:00:00'),
(30,2,'deposit','bban','branch',800000.00,2500000.00,'2025-10-10 09:00:00'),
(30,2,'transfer_out','bban','branch',500000.00,2000000.00,'2026-01-20 10:00:00'),
(30,2,'deposit','bban','branch',700000.00,2700000.00,'2026-04-10 09:00:00'),
-- فرع 3
(39,3,'deposit','cash','branch',100000.00,310000.00,'2025-09-20 09:00:00'),
(39,3,'withdrawal','cash','branch',50000.00,260000.00,'2025-12-15 10:00:00'),
(39,3,'deposit','app_transfer','app',80000.00,340000.00,'2026-03-10 08:00:00'),
(40,3,'deposit','cash','branch',200000.00,870000.00,'2025-10-08 09:00:00'),
(40,3,'transfer_out','bban','branch',150000.00,720000.00,'2026-01-12 10:00:00'),
(40,3,'deposit','bban','branch',250000.00,970000.00,'2026-04-15 09:00:00'),
(40,3,'withdrawal','cheque','branch',100000.00,870000.00,'2026-06-08 10:00:00'),
(42,3,'deposit','bban','branch',500000.00,1950000.00,'2025-11-01 09:00:00'),
(42,3,'transfer_out','bban','branch',300000.00,1650000.00,'2026-02-05 10:00:00'),
(42,3,'deposit','bban','branch',400000.00,2050000.00,'2026-05-10 09:00:00'),
(45,3,'deposit','cash','branch',120000.00,430000.00,'2025-10-20 09:00:00'),
(45,3,'withdrawal','atm','atm',30000.00,400000.00,'2025-12-25 15:00:00'),
(45,3,'deposit','app_transfer','app',60000.00,460000.00,'2026-03-18 08:00:00'),
(46,3,'deposit','cash','branch',250000.00,920000.00,'2025-11-10 09:00:00'),
(46,3,'withdrawal','cheque','branch',150000.00,770000.00,'2026-02-18 10:00:00'),
(46,3,'deposit','bban','branch',300000.00,1070000.00,'2026-05-22 09:00:00'),
-- فرع 4
(51,4,'deposit','cash','branch',80000.00,280000.00,'2025-10-05 09:00:00'),
(51,4,'withdrawal','cash','branch',40000.00,240000.00,'2025-12-20 10:00:00'),
(51,4,'deposit','app_transfer','app',60000.00,300000.00,'2026-03-08 08:00:00'),
(52,4,'deposit','cash','branch',200000.00,750000.00,'2025-09-25 09:00:00'),
(52,4,'withdrawal','cheque','branch',100000.00,650000.00,'2025-12-10 10:00:00'),
(52,4,'deposit','bban','branch',200000.00,850000.00,'2026-03-25 09:00:00'),
(52,4,'transfer_out','bban','branch',150000.00,700000.00,'2026-06-05 10:00:00'),
(54,4,'deposit','bban','branch',400000.00,1600000.00,'2025-11-15 09:00:00'),
(54,4,'transfer_out','bban','branch',200000.00,1400000.00,'2026-02-22 10:00:00'),
(54,4,'deposit','bban','branch',300000.00,1700000.00,'2026-05-18 09:00:00'),
-- فرع 5
(61,5,'deposit','cash','branch',80000.00,245000.00,'2025-11-01 09:00:00'),
(61,5,'withdrawal','cash','branch',35000.00,210000.00,'2026-01-15 10:00:00'),
(61,5,'deposit','app_transfer','app',50000.00,260000.00,'2026-04-08 08:00:00'),
(62,5,'deposit','cash','branch',150000.00,680000.00,'2025-10-18 09:00:00'),
(62,5,'withdrawal','cheque','branch',80000.00,600000.00,'2026-01-05 10:00:00'),
(62,5,'deposit','bban','branch',150000.00,750000.00,'2026-04-20 09:00:00'),
(64,5,'deposit','bban','branch',300000.00,1400000.00,'2025-11-20 09:00:00'),
(64,5,'transfer_out','bban','branch',150000.00,1250000.00,'2026-02-28 10:00:00'),
(64,5,'deposit','bban','branch',200000.00,1450000.00,'2026-05-25 09:00:00'),
-- فروع 6،7،8
(69,6,'deposit','cash','branch',60000.00,220000.00,'2025-12-01 09:00:00'),
(69,6,'withdrawal','cash','branch',25000.00,195000.00,'2026-02-10 10:00:00'),
(70,6,'deposit','cash','branch',150000.00,580000.00,'2025-11-15 09:00:00'),
(70,6,'withdrawal','cheque','branch',80000.00,500000.00,'2026-03-05 10:00:00'),
(70,6,'deposit','bban','branch',100000.00,600000.00,'2026-05-20 09:00:00'),
(75,7,'deposit','cash','branch',50000.00,185000.00,'2026-01-08 09:00:00'),
(75,7,'withdrawal','cash','branch',20000.00,165000.00,'2026-03-15 10:00:00'),
(76,7,'deposit','cash','branch',100000.00,420000.00,'2025-12-10 09:00:00'),
(76,7,'withdrawal','cheque','branch',60000.00,360000.00,'2026-02-25 10:00:00'),
(79,8,'deposit','cash','branch',60000.00,210000.00,'2026-01-20 09:00:00'),
(79,8,'withdrawal','cash','branch',25000.00,185000.00,'2026-04-05 10:00:00'),
(80,8,'deposit','cash','branch',40000.00,145000.00,'2026-02-15 09:00:00');

-- قروض
INSERT INTO loans (customer_id,branch_id,amount,paid_amount,status,issued_at,due_at) VALUES
(1,1,2750000.00,1375000.00,'active',   '2025-01-01','2027-01-01'),
(3,1,550000.00, 550000.00, 'paid',     '2024-06-01','2025-06-01'),
(7,1,1650000.00,412500.00, 'active',   '2025-04-01','2027-04-01'),
(13,1,5500000.00,0.00,     'active',   '2025-06-01','2028-06-01'),
(21,2,1100000.00,275000.00,'active',   '2025-02-01','2027-02-01'),
(24,2,2200000.00,0.00,     'defaulted','2024-01-01','2025-01-01'),
(27,2,825000.00, 825000.00,'paid',     '2024-03-01','2025-03-01'),
(30,2,3300000.00,825000.00,'active',   '2025-05-01','2028-05-01'),
(39,3,660000.00, 330000.00,'active',   '2025-03-01','2026-09-01'),
(42,3,1650000.00,0.00,     'defaulted','2024-06-01','2025-06-01'),
(45,3,550000.00, 550000.00,'paid',     '2024-01-01','2025-01-01'),
(51,4,825000.00, 206250.00,'active',   '2025-04-01','2027-04-01'),
(54,4,1375000.00,0.00,     'defaulted','2024-09-01','2025-09-01'),
(61,5,550000.00, 275000.00,'active',   '2025-06-01','2026-12-01'),
(62,5,1100000.00,275000.00,'active',   '2025-03-01','2027-03-01'),
(64,5,1925000.00,0.00,     'defaulted','2024-08-01','2025-08-01'),
(69,6,440000.00, 220000.00,'active',   '2025-05-01','2026-11-01'),
(75,7,330000.00, 82500.00, 'active',   '2025-07-01','2026-07-01');

-- شكاوى
INSERT INTO complaints (customer_id,branch_id,subject,status,opened_at,resolved_at) VALUES
(2,1,'تأخر في معالجة التحويل البنكي','resolved','2026-01-10 09:00:00','2026-01-15 14:00:00'),
(5,1,'خطأ في الرصيد المعروض في التطبيق','closed','2026-02-05 09:00:00','2026-02-08 14:00:00'),
(10,1,'تأخر وصول تحويل BBAN','in_progress','2026-04-15 09:00:00',NULL),
(15,1,'رسوم خصم غير مبررة','open','2026-05-20 09:00:00',NULL),
(21,2,'صعوبة الوصول لخدمة التطبيق','in_progress','2026-03-10 09:00:00',NULL),
(25,2,'رسوم حساب زائدة','open','2026-04-25 09:00:00',NULL),
(27,2,'تأخر إصدار بطاقة الصرف','open','2026-05-15 09:00:00',NULL),
(33,2,'خطأ في كشف الحساب','resolved','2026-02-20 09:00:00','2026-02-28 14:00:00'),
(40,3,'تأخر صرف القرض','open','2026-04-08 09:00:00',NULL),
(42,3,'مشكلة في تحويل بنكي','in_progress','2026-05-01 09:00:00',NULL),
(52,4,'مشكلة في الصرف الآلي','resolved','2026-03-05 09:00:00','2026-03-10 14:00:00'),
(57,4,'تجميد الحساب بدون إشعار','open','2026-04-18 09:00:00',NULL),
(62,5,'تأخر التحويل الداخلي','open','2026-05-10 09:00:00',NULL),
(70,6,'خطأ في بيانات العميل','resolved','2026-03-15 09:00:00','2026-03-20 14:00:00'),
(75,7,'صعوبة في سداد القرض إلكترونياً','open','2026-05-25 09:00:00',NULL);

-- تنبيهات
INSERT INTO notifications (branch_id,type,title,message) VALUES
(2,'warning','ارتفاع الشكاوى في فرع أم درمان','3 شكاوى مفتوحة هذا الشهر، أعلى من المعدل المقبول.'),
(4,'alert','حساب مجمّد دون معالجة','الحساب رقم 58 مجمّد منذ أكثر من 30 يوماً.'),
(NULL,'warning','4 قروض متعثرة','يُنصح بمراجعة سياسة الإقراض وتفعيل إجراءات التحصيل.'),
(3,'info','نمو ملحوظ في فرع بحري','زيادة 18% في عدد العملاء خلال الربع الأخير.'),
(5,'alert','ارتفاع السحوبات النقدية','سجّل فرع ود مدني ارتفاعاً غير معتاد في السحوبات النقدية.'),
(NULL,'info','تفعيل خدمة BBAN','ارتفاع ملحوظ في استخدام تحويلات BBAN خلال الشهر الماضي. ينصح بتوسيع الطاقة الاستيعابية.');

-- مستخدمو التطبيق (60% من العملاء)
INSERT INTO app_users (customer_id,device_type,registered_at,last_login,is_active) VALUES
(1,'android','2024-01-15 10:00:00','2026-06-29 08:00:00',1),
(2,'ios',    '2024-02-10 11:00:00','2026-06-28 14:00:00',1),
(3,'android','2024-03-05 09:00:00','2026-06-25 09:00:00',1),
(4,'web',    '2024-04-20 10:00:00','2026-06-27 11:00:00',1),
(5,'ios',    '2024-05-12 12:00:00','2026-05-10 10:00:00',0),
(7,'android','2024-06-03 08:00:00','2026-06-29 07:00:00',1),
(8,'ios',    '2024-08-15 10:00:00','2026-06-28 16:00:00',1),
(9,'web',    '2024-09-22 09:00:00','2026-06-20 12:00:00',1),
(11,'android','2024-11-05 10:00:00','2026-06-15 09:00:00',1),
(13,'ios',   '2025-01-25 09:00:00','2026-06-29 08:00:00',1),
(15,'android','2025-03-08 10:00:00','2026-06-22 10:00:00',1),
(17,'web',   '2025-05-30 11:00:00','2026-06-18 11:00:00',1),
(19,'ios',   '2025-09-10 08:00:00','2026-06-29 10:00:00',1),
(21,'android','2024-02-01 09:00:00','2026-06-28 07:00:00',1),
(22,'ios',   '2024-03-15 10:00:00','2026-06-29 15:00:00',1),
(24,'android','2024-05-25 08:00:00','2026-06-27 11:00:00',1),
(26,'web',   '2024-08-20 11:00:00','2026-06-20 12:00:00',1),
(28,'android','2024-11-18 10:00:00','2026-06-25 15:00:00',1),
(30,'ios',   '2025-03-05 09:00:00','2026-06-28 13:00:00',1),
(33,'android','2025-07-01 09:00:00','2026-06-10 10:00:00',1),
(35,'web',   '2025-11-25 10:00:00','2026-06-05 09:00:00',1),
(37,'ios',   '2026-03-15 11:00:00','2026-06-29 08:00:00',1),
(39,'android','2024-03-10 08:00:00','2026-06-27 11:00:00',1),
(40,'ios',   '2024-05-22 10:00:00','2026-06-28 14:00:00',1),
(42,'android','2024-08-14 09:00:00','2026-06-15 09:00:00',1),
(45,'web',   '2024-11-05 10:00:00','2026-06-22 10:00:00',1),
(47,'ios',   '2025-04-08 11:00:00','2026-06-18 11:00:00',1),
(49,'android','2025-09-20 09:00:00','2026-06-29 10:00:00',1),
(51,'android','2024-04-05 08:00:00','2026-06-25 07:00:00',1),
(52,'ios',   '2024-07-18 10:00:00','2026-06-28 15:00:00',1),
(54,'android','2024-10-22 09:00:00','2026-06-20 11:00:00',1),
(56,'web',   '2025-02-10 11:00:00','2026-06-15 12:00:00',0),
(58,'ios',   '2025-08-18 09:00:00','2026-06-10 09:00:00',1),
(61,'android','2024-05-12 08:00:00','2026-06-29 07:00:00',1),
(62,'ios',   '2024-09-05 10:00:00','2026-06-28 14:00:00',1),
(64,'android','2025-01-18 09:00:00','2026-06-22 10:00:00',1),
(66,'web',   '2025-04-22 11:00:00','2026-06-18 11:00:00',1),
(68,'ios',   '2025-11-18 12:00:00','2026-05-20 10:00:00',0),
(69,'android','2024-06-08 08:00:00','2026-06-25 07:00:00',1),
(71,'ios',   '2024-12-15 10:00:00','2026-06-20 14:00:00',1),
(75,'android','2024-08-15 09:00:00','2026-06-28 11:00:00',1),
(76,'ios',   '2025-02-22 10:00:00','2026-06-15 15:00:00',1),
(79,'android','2025-01-10 09:00:00','2026-06-22 11:00:00',1);

-- جلسات التطبيق — تُدرج بـ subquery لتجنب مشكلة الـ FK
-- app_user_id يُحسب تلقائياً من customer_id

INSERT INTO app_sessions (app_user_id,branch_id,action_type,amount,created_at)
SELECT au.app_user_id,1,'login',NULL,'2026-01-05 08:00:00' FROM app_users au WHERE au.customer_id=1 UNION ALL
SELECT au.app_user_id,1,'view_balance',NULL,'2026-01-05 08:01:00' FROM app_users au WHERE au.customer_id=1 UNION ALL
SELECT au.app_user_id,1,'transfer',150000.00,'2026-01-05 08:10:00' FROM app_users au WHERE au.customer_id=1 UNION ALL
SELECT au.app_user_id,1,'login',NULL,'2026-01-08 09:00:00' FROM app_users au WHERE au.customer_id=2 UNION ALL
SELECT au.app_user_id,1,'payment',25000.00,'2026-01-08 09:10:00' FROM app_users au WHERE au.customer_id=2 UNION ALL
SELECT au.app_user_id,1,'login',NULL,'2026-01-12 10:00:00' FROM app_users au WHERE au.customer_id=3 UNION ALL
SELECT au.app_user_id,1,'loan_inquiry',NULL,'2026-01-12 10:05:00' FROM app_users au WHERE au.customer_id=3 UNION ALL
SELECT au.app_user_id,1,'login',NULL,'2026-01-15 11:00:00' FROM app_users au WHERE au.customer_id=4 UNION ALL
SELECT au.app_user_id,1,'transfer',300000.00,'2026-01-15 11:10:00' FROM app_users au WHERE au.customer_id=4 UNION ALL
SELECT au.app_user_id,2,'login',NULL,'2026-01-10 09:00:00' FROM app_users au WHERE au.customer_id=21 UNION ALL
SELECT au.app_user_id,2,'transfer',200000.00,'2026-01-10 09:10:00' FROM app_users au WHERE au.customer_id=21 UNION ALL
SELECT au.app_user_id,2,'login',NULL,'2026-01-18 10:00:00' FROM app_users au WHERE au.customer_id=22 UNION ALL
SELECT au.app_user_id,2,'view_balance',NULL,'2026-01-18 10:01:00' FROM app_users au WHERE au.customer_id=22 UNION ALL
SELECT au.app_user_id,2,'login',NULL,'2026-01-20 09:00:00' FROM app_users au WHERE au.customer_id=24 UNION ALL
SELECT au.app_user_id,2,'deposit_request',500000.00,'2026-01-20 09:15:00' FROM app_users au WHERE au.customer_id=24 UNION ALL
SELECT au.app_user_id,3,'login',NULL,'2026-01-08 09:00:00' FROM app_users au WHERE au.customer_id=39 UNION ALL
SELECT au.app_user_id,3,'transfer',120000.00,'2026-01-08 09:10:00' FROM app_users au WHERE au.customer_id=39 UNION ALL
SELECT au.app_user_id,3,'login',NULL,'2026-01-15 10:00:00' FROM app_users au WHERE au.customer_id=40 UNION ALL
SELECT au.app_user_id,3,'view_balance',NULL,'2026-01-15 10:01:00' FROM app_users au WHERE au.customer_id=40 UNION ALL
SELECT au.app_user_id,4,'login',NULL,'2026-01-12 08:30:00' FROM app_users au WHERE au.customer_id=51 UNION ALL
SELECT au.app_user_id,4,'transfer',80000.00,'2026-01-12 08:45:00' FROM app_users au WHERE au.customer_id=51 UNION ALL
SELECT au.app_user_id,5,'login',NULL,'2026-01-10 10:00:00' FROM app_users au WHERE au.customer_id=61 UNION ALL
SELECT au.app_user_id,5,'transfer',100000.00,'2026-01-10 10:15:00' FROM app_users au WHERE au.customer_id=61;

INSERT INTO app_sessions (app_user_id,branch_id,action_type,amount,created_at)
SELECT au.app_user_id,1,'login',NULL,'2026-02-03 08:00:00' FROM app_users au WHERE au.customer_id=1 UNION ALL
SELECT au.app_user_id,1,'transfer',200000.00,'2026-02-03 08:10:00' FROM app_users au WHERE au.customer_id=1 UNION ALL
SELECT au.app_user_id,1,'login',NULL,'2026-02-10 09:00:00' FROM app_users au WHERE au.customer_id=7 UNION ALL
SELECT au.app_user_id,1,'view_balance',NULL,'2026-02-10 09:01:00' FROM app_users au WHERE au.customer_id=7 UNION ALL
SELECT au.app_user_id,1,'transfer',450000.00,'2026-02-10 09:10:00' FROM app_users au WHERE au.customer_id=7 UNION ALL
SELECT au.app_user_id,2,'login',NULL,'2026-02-08 09:00:00' FROM app_users au WHERE au.customer_id=21 UNION ALL
SELECT au.app_user_id,2,'transfer',250000.00,'2026-02-08 09:10:00' FROM app_users au WHERE au.customer_id=21 UNION ALL
SELECT au.app_user_id,2,'login',NULL,'2026-02-15 10:00:00' FROM app_users au WHERE au.customer_id=24 UNION ALL
SELECT au.app_user_id,2,'deposit_request',800000.00,'2026-02-15 10:15:00' FROM app_users au WHERE au.customer_id=24 UNION ALL
SELECT au.app_user_id,3,'login',NULL,'2026-02-12 09:00:00' FROM app_users au WHERE au.customer_id=39 UNION ALL
SELECT au.app_user_id,3,'transfer',150000.00,'2026-02-12 09:10:00' FROM app_users au WHERE au.customer_id=39 UNION ALL
SELECT au.app_user_id,3,'login',NULL,'2026-02-20 10:00:00' FROM app_users au WHERE au.customer_id=42 UNION ALL
SELECT au.app_user_id,3,'loan_inquiry',NULL,'2026-02-20 10:05:00' FROM app_users au WHERE au.customer_id=42 UNION ALL
SELECT au.app_user_id,4,'login',NULL,'2026-02-10 08:30:00' FROM app_users au WHERE au.customer_id=51 UNION ALL
SELECT au.app_user_id,4,'payment',50000.00,'2026-02-10 08:40:00' FROM app_users au WHERE au.customer_id=51 UNION ALL
SELECT au.app_user_id,5,'login',NULL,'2026-02-18 10:00:00' FROM app_users au WHERE au.customer_id=61 UNION ALL
SELECT au.app_user_id,5,'transfer',120000.00,'2026-02-18 10:15:00' FROM app_users au WHERE au.customer_id=61 UNION ALL
SELECT au.app_user_id,6,'login',NULL,'2026-02-15 09:00:00' FROM app_users au WHERE au.customer_id=69 UNION ALL
SELECT au.app_user_id,6,'view_balance',NULL,'2026-02-15 09:01:00' FROM app_users au WHERE au.customer_id=69;

INSERT INTO app_sessions (app_user_id,branch_id,action_type,amount,created_at)
SELECT au.app_user_id,1,'login',NULL,'2026-03-05 08:00:00' FROM app_users au WHERE au.customer_id=1 UNION ALL
SELECT au.app_user_id,1,'transfer',350000.00,'2026-03-05 08:10:00' FROM app_users au WHERE au.customer_id=1 UNION ALL
SELECT au.app_user_id,1,'login',NULL,'2026-03-12 09:00:00' FROM app_users au WHERE au.customer_id=9 UNION ALL
SELECT au.app_user_id,1,'transfer',180000.00,'2026-03-12 09:10:00' FROM app_users au WHERE au.customer_id=9 UNION ALL
SELECT au.app_user_id,1,'login',NULL,'2026-03-08 10:00:00' FROM app_users au WHERE au.customer_id=13 UNION ALL
SELECT au.app_user_id,1,'transfer',600000.00,'2026-03-08 10:10:00' FROM app_users au WHERE au.customer_id=13 UNION ALL
SELECT au.app_user_id,2,'login',NULL,'2026-03-10 09:00:00' FROM app_users au WHERE au.customer_id=22 UNION ALL
SELECT au.app_user_id,2,'transfer',300000.00,'2026-03-10 09:10:00' FROM app_users au WHERE au.customer_id=22 UNION ALL
SELECT au.app_user_id,2,'login',NULL,'2026-03-18 10:00:00' FROM app_users au WHERE au.customer_id=24 UNION ALL
SELECT au.app_user_id,2,'deposit_request',600000.00,'2026-03-18 10:15:00' FROM app_users au WHERE au.customer_id=24 UNION ALL
SELECT au.app_user_id,2,'login',NULL,'2026-03-15 11:00:00' FROM app_users au WHERE au.customer_id=26 UNION ALL
SELECT au.app_user_id,2,'view_balance',NULL,'2026-03-15 11:01:00' FROM app_users au WHERE au.customer_id=26 UNION ALL
SELECT au.app_user_id,3,'login',NULL,'2026-03-08 09:00:00' FROM app_users au WHERE au.customer_id=40 UNION ALL
SELECT au.app_user_id,3,'transfer',200000.00,'2026-03-08 09:10:00' FROM app_users au WHERE au.customer_id=40 UNION ALL
SELECT au.app_user_id,4,'login',NULL,'2026-03-12 08:30:00' FROM app_users au WHERE au.customer_id=52 UNION ALL
SELECT au.app_user_id,4,'transfer',100000.00,'2026-03-12 08:45:00' FROM app_users au WHERE au.customer_id=52 UNION ALL
SELECT au.app_user_id,5,'login',NULL,'2026-03-20 10:00:00' FROM app_users au WHERE au.customer_id=62 UNION ALL
SELECT au.app_user_id,5,'transfer',150000.00,'2026-03-20 10:15:00' FROM app_users au WHERE au.customer_id=62 UNION ALL
SELECT au.app_user_id,6,'login',NULL,'2026-03-15 09:00:00' FROM app_users au WHERE au.customer_id=71 UNION ALL
SELECT au.app_user_id,6,'transfer',80000.00,'2026-03-15 09:10:00' FROM app_users au WHERE au.customer_id=71;

INSERT INTO app_sessions (app_user_id,branch_id,action_type,amount,created_at)
SELECT au.app_user_id,1,'login',NULL,'2026-04-08 08:00:00' FROM app_users au WHERE au.customer_id=1 UNION ALL
SELECT au.app_user_id,1,'transfer',250000.00,'2026-04-08 08:10:00' FROM app_users au WHERE au.customer_id=1 UNION ALL
SELECT au.app_user_id,1,'login',NULL,'2026-04-15 09:00:00' FROM app_users au WHERE au.customer_id=7 UNION ALL
SELECT au.app_user_id,1,'transfer',380000.00,'2026-04-15 09:10:00' FROM app_users au WHERE au.customer_id=7 UNION ALL
SELECT au.app_user_id,1,'login',NULL,'2026-04-10 10:00:00' FROM app_users au WHERE au.customer_id=13 UNION ALL
SELECT au.app_user_id,1,'transfer',800000.00,'2026-04-10 10:10:00' FROM app_users au WHERE au.customer_id=13 UNION ALL
SELECT au.app_user_id,2,'login',NULL,'2026-04-12 09:00:00' FROM app_users au WHERE au.customer_id=22 UNION ALL
SELECT au.app_user_id,2,'transfer',350000.00,'2026-04-12 09:10:00' FROM app_users au WHERE au.customer_id=22 UNION ALL
SELECT au.app_user_id,2,'login',NULL,'2026-04-20 10:00:00' FROM app_users au WHERE au.customer_id=24 UNION ALL
SELECT au.app_user_id,2,'deposit_request',700000.00,'2026-04-20 10:15:00' FROM app_users au WHERE au.customer_id=24 UNION ALL
SELECT au.app_user_id,2,'login',NULL,'2026-04-08 11:00:00' FROM app_users au WHERE au.customer_id=28 UNION ALL
SELECT au.app_user_id,2,'transfer',200000.00,'2026-04-08 11:10:00' FROM app_users au WHERE au.customer_id=28 UNION ALL
SELECT au.app_user_id,3,'login',NULL,'2026-04-15 09:00:00' FROM app_users au WHERE au.customer_id=40 UNION ALL
SELECT au.app_user_id,3,'transfer',180000.00,'2026-04-15 09:10:00' FROM app_users au WHERE au.customer_id=40 UNION ALL
SELECT au.app_user_id,3,'login',NULL,'2026-04-22 10:00:00' FROM app_users au WHERE au.customer_id=45 UNION ALL
SELECT au.app_user_id,3,'loan_inquiry',NULL,'2026-04-22 10:05:00' FROM app_users au WHERE au.customer_id=45 UNION ALL
SELECT au.app_user_id,4,'login',NULL,'2026-04-10 08:30:00' FROM app_users au WHERE au.customer_id=52 UNION ALL
SELECT au.app_user_id,4,'transfer',120000.00,'2026-04-10 08:45:00' FROM app_users au WHERE au.customer_id=52 UNION ALL
SELECT au.app_user_id,5,'login',NULL,'2026-04-18 10:00:00' FROM app_users au WHERE au.customer_id=62 UNION ALL
SELECT au.app_user_id,5,'transfer',100000.00,'2026-04-18 10:15:00' FROM app_users au WHERE au.customer_id=62;

INSERT INTO app_sessions (app_user_id,branch_id,action_type,amount,created_at)
SELECT au.app_user_id,1,'login',NULL,'2026-05-05 08:00:00' FROM app_users au WHERE au.customer_id=1 UNION ALL
SELECT au.app_user_id,1,'transfer',300000.00,'2026-05-05 08:10:00' FROM app_users au WHERE au.customer_id=1 UNION ALL
SELECT au.app_user_id,1,'login',NULL,'2026-05-12 11:00:00' FROM app_users au WHERE au.customer_id=4 UNION ALL
SELECT au.app_user_id,1,'transfer',500000.00,'2026-05-12 11:10:00' FROM app_users au WHERE au.customer_id=4 UNION ALL
SELECT au.app_user_id,1,'login',NULL,'2026-05-08 09:00:00' FROM app_users au WHERE au.customer_id=9 UNION ALL
SELECT au.app_user_id,1,'view_balance',NULL,'2026-05-08 09:01:00' FROM app_users au WHERE au.customer_id=9 UNION ALL
SELECT au.app_user_id,2,'login',NULL,'2026-05-10 09:00:00' FROM app_users au WHERE au.customer_id=22 UNION ALL
SELECT au.app_user_id,2,'transfer',400000.00,'2026-05-10 09:10:00' FROM app_users au WHERE au.customer_id=22 UNION ALL
SELECT au.app_user_id,2,'login',NULL,'2026-05-18 10:00:00' FROM app_users au WHERE au.customer_id=26 UNION ALL
SELECT au.app_user_id,2,'payment',80000.00,'2026-05-18 10:10:00' FROM app_users au WHERE au.customer_id=26 UNION ALL
SELECT au.app_user_id,2,'login',NULL,'2026-05-15 11:00:00' FROM app_users au WHERE au.customer_id=28 UNION ALL
SELECT au.app_user_id,2,'transfer',280000.00,'2026-05-15 11:10:00' FROM app_users au WHERE au.customer_id=28 UNION ALL
SELECT au.app_user_id,3,'login',NULL,'2026-05-08 09:00:00' FROM app_users au WHERE au.customer_id=40 UNION ALL
SELECT au.app_user_id,3,'transfer',220000.00,'2026-05-08 09:10:00' FROM app_users au WHERE au.customer_id=40 UNION ALL
SELECT au.app_user_id,4,'login',NULL,'2026-05-12 08:30:00' FROM app_users au WHERE au.customer_id=52 UNION ALL
SELECT au.app_user_id,4,'transfer',90000.00,'2026-05-12 08:45:00' FROM app_users au WHERE au.customer_id=52 UNION ALL
SELECT au.app_user_id,5,'login',NULL,'2026-05-20 10:00:00' FROM app_users au WHERE au.customer_id=62 UNION ALL
SELECT au.app_user_id,5,'transfer',130000.00,'2026-05-20 10:15:00' FROM app_users au WHERE au.customer_id=62 UNION ALL
SELECT au.app_user_id,6,'login',NULL,'2026-05-15 09:00:00' FROM app_users au WHERE au.customer_id=71 UNION ALL
SELECT au.app_user_id,6,'transfer',60000.00,'2026-05-15 09:10:00' FROM app_users au WHERE au.customer_id=71 UNION ALL
SELECT au.app_user_id,7,'login',NULL,'2026-05-10 09:00:00' FROM app_users au WHERE au.customer_id=75 UNION ALL
SELECT au.app_user_id,7,'view_balance',NULL,'2026-05-10 09:01:00' FROM app_users au WHERE au.customer_id=75;

INSERT INTO app_sessions (app_user_id,branch_id,action_type,amount,created_at)
SELECT au.app_user_id,1,'login',NULL,'2026-06-02 08:00:00' FROM app_users au WHERE au.customer_id=1 UNION ALL
SELECT au.app_user_id,1,'transfer',200000.00,'2026-06-02 08:10:00' FROM app_users au WHERE au.customer_id=1 UNION ALL
SELECT au.app_user_id,1,'login',NULL,'2026-06-08 09:00:00' FROM app_users au WHERE au.customer_id=7 UNION ALL
SELECT au.app_user_id,1,'transfer',500000.00,'2026-06-08 09:10:00' FROM app_users au WHERE au.customer_id=7 UNION ALL
SELECT au.app_user_id,1,'login',NULL,'2026-06-05 10:00:00' FROM app_users au WHERE au.customer_id=13 UNION ALL
SELECT au.app_user_id,1,'transfer',700000.00,'2026-06-05 10:10:00' FROM app_users au WHERE au.customer_id=13 UNION ALL
SELECT au.app_user_id,2,'login',NULL,'2026-06-05 09:00:00' FROM app_users au WHERE au.customer_id=22 UNION ALL
SELECT au.app_user_id,2,'transfer',300000.00,'2026-06-05 09:10:00' FROM app_users au WHERE au.customer_id=22 UNION ALL
SELECT au.app_user_id,2,'login',NULL,'2026-06-12 10:00:00' FROM app_users au WHERE au.customer_id=24 UNION ALL
SELECT au.app_user_id,2,'deposit_request',900000.00,'2026-06-12 10:15:00' FROM app_users au WHERE au.customer_id=24 UNION ALL
SELECT au.app_user_id,2,'login',NULL,'2026-06-10 11:00:00' FROM app_users au WHERE au.customer_id=28 UNION ALL
SELECT au.app_user_id,2,'transfer',350000.00,'2026-06-10 11:10:00' FROM app_users au WHERE au.customer_id=28 UNION ALL
SELECT au.app_user_id,3,'login',NULL,'2026-06-08 09:00:00' FROM app_users au WHERE au.customer_id=40 UNION ALL
SELECT au.app_user_id,3,'transfer',180000.00,'2026-06-08 09:10:00' FROM app_users au WHERE au.customer_id=40 UNION ALL
SELECT au.app_user_id,4,'login',NULL,'2026-06-05 08:30:00' FROM app_users au WHERE au.customer_id=52 UNION ALL
SELECT au.app_user_id,4,'payment',70000.00,'2026-06-05 08:40:00' FROM app_users au WHERE au.customer_id=52 UNION ALL
SELECT au.app_user_id,5,'login',NULL,'2026-06-10 10:00:00' FROM app_users au WHERE au.customer_id=62 UNION ALL
SELECT au.app_user_id,5,'transfer',160000.00,'2026-06-10 10:15:00' FROM app_users au WHERE au.customer_id=62 UNION ALL
SELECT au.app_user_id,6,'login',NULL,'2026-06-08 09:00:00' FROM app_users au WHERE au.customer_id=69 UNION ALL
SELECT au.app_user_id,6,'transfer',50000.00,'2026-06-08 09:10:00' FROM app_users au WHERE au.customer_id=69 UNION ALL
SELECT au.app_user_id,7,'login',NULL,'2026-06-05 09:00:00' FROM app_users au WHERE au.customer_id=75 UNION ALL
SELECT au.app_user_id,7,'transfer',40000.00,'2026-06-05 09:10:00' FROM app_users au WHERE au.customer_id=75 UNION ALL
SELECT au.app_user_id,8,'login',NULL,'2026-06-10 10:00:00' FROM app_users au WHERE au.customer_id=79 UNION ALL
SELECT au.app_user_id,8,'view_balance',NULL,'2026-06-10 10:01:00' FROM app_users au WHERE au.customer_id=79;

-- تحويلات خارجية مع البنوك السودانية (BBAN)
INSERT INTO external_transfers (account_id,branch_id,direction,external_bank,bban,amount,channel,created_at) VALUES
-- بنك الخرطوم
(1,1,'inbound', 'بنك الخرطوم',       'SD0001001001234567',550000.00,'bban', '2026-01-15 09:00:00'),
(1,1,'outbound','بنك الخرطوم',       'SD0001001009876543',275000.00,'bban', '2026-02-10 10:00:00'),
(9,1,'inbound', 'بنك الخرطوم',       'SD0001001005551234',400000.00,'bban', '2026-03-08 09:00:00'),
(22,2,'inbound','بنك الخرطوم',       'SD0001002001234567',330000.00,'bban', '2026-01-20 09:00:00'),
(28,2,'outbound','بنك الخرطوم',      'SD0001002009876543',165000.00,'bban', '2026-03-15 10:00:00'),
(40,3,'inbound','بنك الخرطوم',       'SD0001003001234567',250000.00,'bban', '2026-02-18 09:00:00'),
(52,4,'inbound','بنك الخرطوم',       'SD0001004001234567',200000.00,'bban', '2026-04-10 09:00:00'),
-- البنك الأهلي السوداني
(4,1,'inbound', 'البنك الأهلي السوداني','SD0002001001234567',880000.00,'bban','2026-01-08 09:00:00'),
(4,1,'outbound','البنك الأهلي السوداني','SD0002001009876543',440000.00,'bban','2026-03-05 10:00:00'),
(13,1,'inbound','البنك الأهلي السوداني','SD0002001005551234',1650000.00,'bban','2026-02-01 09:00:00'),
(24,2,'inbound','البنك الأهلي السوداني','SD0002002001234567',770000.00,'bban','2026-01-25 09:00:00'),
(30,2,'outbound','البنك الأهلي السوداني','SD0002002009876543',385000.00,'bban','2026-04-12 10:00:00'),
(42,3,'inbound','البنك الأهلي السوداني','SD0002003001234567',550000.00,'bban','2026-03-10 09:00:00'),
(54,4,'inbound','البنك الأهلي السوداني','SD0002004001234567',440000.00,'bban','2026-02-20 09:00:00'),
(64,5,'outbound','البنك الأهلي السوداني','SD0002005009876543',275000.00,'bban','2026-05-05 10:00:00'),
-- بنك فيصل الإسلامي
(1,1,'outbound','بنك فيصل الإسلامي السوداني','SD0003001009876543',330000.00,'bban','2026-02-15 10:00:00'),
(9,1,'inbound', 'بنك فيصل الإسلامي السوداني','SD0003001005551234',440000.00,'bban','2026-04-08 09:00:00'),
(24,2,'inbound','بنك فيصل الإسلامي السوداني','SD0003002001234567',550000.00,'bban','2026-03-20 09:00:00'),
(30,2,'outbound','بنك فيصل الإسلامي السوداني','SD0003002009876543',220000.00,'bban','2026-05-10 10:00:00'),
(40,3,'inbound','بنك فيصل الإسلامي السوداني','SD0003003001234567',385000.00,'bban','2026-02-28 09:00:00'),
(54,4,'outbound','بنك فيصل الإسلامي السوداني','SD0003004009876543',165000.00,'bban','2026-04-25 10:00:00'),
-- بنك السودان التجاري
(4,1,'inbound', 'بنك السودان التجاري','SD0004001001234567',330000.00,'bban','2026-02-05 09:00:00'),
(22,2,'outbound','بنك السودان التجاري','SD0004002009876543',165000.00,'bban','2026-03-25 10:00:00'),
(42,3,'outbound','بنك السودان التجاري','SD0004003009876543',220000.00,'bban','2026-04-18 10:00:00'),
(52,4,'inbound','بنك السودان التجاري','SD0004004001234567',275000.00,'bban','2026-05-12 09:00:00'),
(62,5,'outbound','بنك السودان التجاري','SD0004005009876543',110000.00,'bban','2026-06-02 10:00:00'),
-- بنك أمدرمان الوطني
(6,1,'outbound','بنك أمدرمان الوطني','SD0005001009876543',220000.00,'bban','2026-03-01 10:00:00'),
(26,2,'inbound','بنك أمدرمان الوطني','SD0005002001234567',275000.00,'bban','2026-02-22 09:00:00'),
(46,3,'inbound','بنك أمدرمان الوطني','SD0005003001234567',330000.00,'bban','2026-04-05 09:00:00'),
(62,5,'outbound','بنك أمدرمان الوطني','SD0005005009876543',155000.00,'bban','2026-05-28 10:00:00'),
-- تحويلات خارجية عبر تطبيق الجوال (وارد وصادر)
(9,1,'inbound', 'بنك الخرطوم',              'SD0001001007778889',95000.00,'app','2026-03-22 09:20:00'),
(28,2,'outbound','بنك الخرطوم',              'SD0001002007778889',60000.00,'app','2026-04-02 10:20:00'),
(13,1,'inbound', 'البنك الأهلي السوداني',    'SD0002001007778889',180000.00,'app','2026-02-14 09:30:00'),
(30,2,'outbound','البنك الأهلي السوداني',    'SD0002002007778889',75000.00,'app','2026-05-18 10:30:00'),
(40,3,'inbound', 'بنك فيصل الإسلامي السوداني','SD0003003007778889',120000.00,'app','2026-03-30 09:40:00'),
(52,4,'outbound','بنك السودان التجاري',       'SD0004004007778889',45000.00,'app','2026-05-06 10:40:00');

-- تحويلات داخلية بين الحسابات
INSERT INTO internal_transfers (from_account_id,to_account_id,from_branch_id,to_branch_id,amount,transfer_type,channel,created_at) VALUES
(1,3,1,1,  100000.00,'same_branch','branch','2026-01-18 10:00:00'),
(2,6,1,1,   50000.00,'same_branch','app',   '2026-02-12 11:00:00'),
(7,11,1,1, 200000.00,'same_branch','branch','2026-03-05 10:00:00'),
(22,25,2,2,150000.00,'same_branch','branch','2026-01-25 10:00:00'),
(23,27,2,2, 80000.00,'same_branch','app',   '2026-03-08 11:00:00'),
(39,41,3,3, 60000.00,'same_branch','branch','2026-02-15 10:00:00'),
(40,44,3,3,120000.00,'same_branch','web',   '2026-04-02 09:00:00'),
(51,53,4,4, 75000.00,'same_branch','branch','2026-03-18 10:00:00'),
(61,63,5,5, 90000.00,'same_branch','app',   '2026-04-22 11:00:00'),
(1,22,1,2, 300000.00,'inter_branch','branch','2026-01-22 10:00:00'),
(4,24,1,2, 500000.00,'inter_branch','app',   '2026-02-08 11:00:00'),
(9,30,1,2, 250000.00,'inter_branch','branch','2026-03-12 10:00:00'),
(13,42,1,3,750000.00,'inter_branch','app',   '2026-04-05 11:00:00'),
(22,40,2,3,200000.00,'inter_branch','branch','2026-02-18 10:00:00'),
(24,54,2,4,400000.00,'inter_branch','app',   '2026-03-22 11:00:00'),
(28,52,2,4,180000.00,'inter_branch','branch','2026-04-15 10:00:00'),
(30,64,2,5,350000.00,'inter_branch','app',   '2026-05-02 11:00:00'),
(40,62,3,5,220000.00,'inter_branch','branch','2026-03-28 10:00:00'),
(42,70,3,6,160000.00,'inter_branch','app',   '2026-05-08 11:00:00'),
(54,76,4,7,130000.00,'inter_branch','branch','2026-04-18 10:00:00'),
(62,79,5,8,100000.00,'inter_branch','app',   '2026-05-25 11:00:00'),
(24,4,2,1, 280000.00,'inter_branch','branch','2026-05-15 10:00:00');

-- ============================================================
-- 5) استعلام تحقق — يجب أن تظهر الأعداد التالية بعد الاستيراد
-- ============================================================
SELECT 'branches' AS tbl, COUNT(*) AS `rows` FROM branches
UNION ALL SELECT 'users',              COUNT(*) FROM users
UNION ALL SELECT 'customers',          COUNT(*) FROM customers
UNION ALL SELECT 'accounts',           COUNT(*) FROM accounts
UNION ALL SELECT 'transactions',       COUNT(*) FROM transactions
UNION ALL SELECT 'internal_transfers', COUNT(*) FROM internal_transfers
UNION ALL SELECT 'external_transfers', COUNT(*) FROM external_transfers
UNION ALL SELECT 'loans',              COUNT(*) FROM loans
UNION ALL SELECT 'complaints',         COUNT(*) FROM complaints
UNION ALL SELECT 'notifications',      COUNT(*) FROM notifications
UNION ALL SELECT 'app_users',          COUNT(*) FROM app_users
UNION ALL SELECT 'app_sessions',       COUNT(*) FROM app_sessions;

-- ── جدول الجلسات (لنسخة النشر) ──
CREATE TABLE IF NOT EXISTS sessions (
    id          VARCHAR(128) NOT NULL PRIMARY KEY,
    data        MEDIUMTEXT   NOT NULL,
    user_id     INT          NULL,
    created_at  INT UNSIGNED NOT NULL,
    last_access INT UNSIGNED NOT NULL,
    KEY idx_sessions_last_access (last_access)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
