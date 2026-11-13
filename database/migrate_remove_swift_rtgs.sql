-- حذف SWIFT و RTGS نهائياً من قاعدة SBDAS (تحويل القيم القديمة إلى BBAN + حذف عمود swift_code + تحديث العروض).
-- يُنفَّذ مرة واحدة على قاعدة موجودة ومملوءة (Aiven أو XAMPP المحلية). القاعدة الجديدة من database.sql لا تحتاجه.
UPDATE transactions SET sub_type='bban' WHERE sub_type IN ('swift','rtgs');
ALTER TABLE transactions MODIFY sub_type ENUM('cash','cheque','app_transfer','web_transfer','internal_transfer','bban','atm','pos') NOT NULL DEFAULT 'cash';
UPDATE external_transfers SET channel='bban' WHERE channel IN ('swift','rtgs');
ALTER TABLE external_transfers MODIFY channel ENUM('branch','app','bban') NOT NULL DEFAULT 'bban';
ALTER TABLE external_transfers DROP COLUMN swift_code;
UPDATE complaints SET subject='مشكلة في تحويل بنكي' WHERE subject='مشكلة في تحويل SWIFT';

-- تحديث العروض (Views) لتخلو من أعمدة swift/rtgs
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
