<?php
/**
 * includes/session_handler.php
 * -----------------------------------------------------------
 * معالج جلسات PHP يخزّن الجلسات في جدول MySQL باسم `sessions` بدل ملفات الخادم المحلية
 * (ضروري على Vercel / Serverless لأن ملفات الجلسات لا تدوم ولا تُشارك بين النسخ).
 *
 * لا يغيّر منطق المصادقة: ما زال التطبيق يستعمل $_SESSION و session_start() و
 * session_regenerate_id() كما هو؛ التغيير فقط في مكان تخزين بيانات الجلسة.
 *
 * منع تعارض الطلبات المتزامنة: يعمل المعالج باتصال MySQL مستقل عن اتصال التطبيق،
 * ويفتح معاملة في read() ويقفل صف الجلسة بـ SELECT ... FOR UPDATE حتى write()/close()
 * (نفس سلوك قفل ملف الجلسة الافتراضي): طلبان لنفس الجلسة يُنفَّذان بالتتابع فلا يضيع تحديث.
 * يعمل القفل على MySQL وMariaDB وTiDB (لا يعتمد على GET_LOCK).
 * -----------------------------------------------------------
 */
require_once __DIR__ . '/db_conn.php';

class SbdasDbSessionHandler implements SessionHandlerInterface, SessionUpdateTimestampHandlerInterface
{
    /** @var mysqli|null */
    private $db = null;
    private $inTx = false;
    private $lockedId = null;
    private $loadedData = null;   // ما قُرئ فعلاً، لتجنّب كتابة لا حاجة لها
    private $lockWait;

    public function __construct($lockWaitSeconds = 10) { $this->lockWait = (int)$lockWaitSeconds; }

    private function conn() {
        if ($this->db === null) {
            $this->db = newDbConnection();
            $this->db->query("SET SESSION innodb_lock_wait_timeout = " . max(1, $this->lockWait));
            // READ COMMITTED: لا أقفال فجوات (gap locks) عند SELECT … FOR UPDATE على صف غير موجود؛ بدونه يتعثر زوار جدد
            // يصلون في اللحظة نفسها بـ Deadlock (اكتُشف في الاختبار). لا يؤثر على اتصال التطبيق (اتصال مستقل).
            $this->db->query("SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED");
        }
        return $this->db;
    }

    private function finishTx($commit = true) {
        if ($this->inTx && $this->db) {
            try { $commit ? $this->db->commit() : $this->db->rollback(); } catch (Throwable $e) { error_log('session tx end: ' . $e->getMessage()); }
        }
        $this->inTx = false;
        $this->lockedId = null;
    }

    public function open(string $path, string $name): bool { return true; }

    public function close(): bool {
        $this->finishTx(true);
        return true;
    }

    // التحقق من وجود المعرّف (use_strict_mode): يمنع قبول معرّف جلسة غير صادر من الخادم
    public function validateId(string $id): bool {
        try {
            $st = $this->conn()->prepare("SELECT 1 FROM sessions WHERE id = ?");
            $st->bind_param('s', $id);
            $st->execute();
            return $st->get_result()->num_rows === 1;
        } catch (Throwable $e) {
            error_log('session validateId: ' . $e->getMessage());
            return false;
        }
    }

    public function read(string $id): string|false {
        // إعادة محاولة قصيرة عند Deadlock (1213) أو مهلة القفل (1205) — نادرة، لكنها قد تحدث تحت ضغط متزامن
        for ($attempt = 1; $attempt <= 3; $attempt++) {
            try {
                $db = $this->conn();
                $this->finishTx(true);
                $db->begin_transaction();
                $this->inTx = true;
                $this->lockedId = $id;

                $st = $db->prepare("SELECT data FROM sessions WHERE id = ? FOR UPDATE");
                $st->bind_param('s', $id);
                $st->execute();
                $row = $st->get_result()->fetch_assoc();
                if ($row === null) {
                    // جلسة جديدة: ننشئ الصف فوراً ليُقفل (INSERT IGNORE يحمي من سباق إنشاء نفس المعرّف)
                    $now = time();
                    $ins = $db->prepare("INSERT IGNORE INTO sessions (id, data, created_at, last_access) VALUES (?, '', ?, ?)");
                    $ins->bind_param('sii', $id, $now, $now);
                    $ins->execute();
                    $st->execute();
                    $row = $st->get_result()->fetch_assoc();
                }
                $this->loadedData = (string)($row['data'] ?? '');
                return $this->loadedData;
            } catch (Throwable $e) {
                $errno = ($e instanceof mysqli_sql_exception) ? (int)$e->getCode() : 0;
                $this->finishTx(false);
                if (($errno === 1213 || $errno === 1205) && $attempt < 3) {
                    usleep(random_int(20000, 80000) * $attempt);
                    continue;
                }
                error_log('session read: ' . $e->getMessage());
                return false;
            }
        }
        return false;
    }

    public function write(string $id, string $data): bool {
        try {
            $db = $this->conn();
            if (!$this->inTx) { $db->begin_transaction(); $this->inTx = true; }
            $now = time();
            $uid = null;
            // user_id لأغراض الإدارة فقط (يُستخرج من بيانات الجلسة ولا يُستعمل في المصادقة)
            if (preg_match('/(?:^|;)user_id\|(?:i:(\d+)|s:\d+:"(\d+)");/', $data, $m)) { $uid = (int)($m[1] !== '' ? $m[1] : ($m[2] ?? 0)); }
            $st = $db->prepare(
                "INSERT INTO sessions (id, data, user_id, created_at, last_access) VALUES (?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE data = VALUES(data), user_id = VALUES(user_id), last_access = VALUES(last_access)"
            );
            $st->bind_param('ssiii', $id, $data, $uid, $now, $now);
            $st->execute();
            $this->loadedData = $data;
            $this->finishTx(true);
            return true;
        } catch (Throwable $e) {
            error_log('session write: ' . $e->getMessage());
            $this->finishTx(false);
            return false;
        }
    }

    // تحديث وقت آخر نشاط فقط عندما لا تتغير البيانات
    public function updateTimestamp(string $id, string $data): bool {
        try {
            $db = $this->conn();
            if (!$this->inTx) { $db->begin_transaction(); $this->inTx = true; }
            $now = time();
            $st = $db->prepare("UPDATE sessions SET last_access = ? WHERE id = ?");
            $st->bind_param('is', $now, $id);
            $st->execute();
            $this->finishTx(true);
            return true;
        } catch (Throwable $e) {
            error_log('session updateTimestamp: ' . $e->getMessage());
            $this->finishTx(false);
            return false;
        }
    }

    public function destroy(string $id): bool {
        try {
            $db = $this->conn();
            if (!$this->inTx) { $db->begin_transaction(); $this->inTx = true; }
            $st = $db->prepare("DELETE FROM sessions WHERE id = ?");
            $st->bind_param('s', $id);
            $st->execute();
            $this->finishTx(true);
            return true;
        } catch (Throwable $e) {
            error_log('session destroy: ' . $e->getMessage());
            $this->finishTx(false);
            return false;
        }
    }

    public function gc(int $max_lifetime): int|false {
        try {
            $db = $this->conn();
            $cut = time() - $max_lifetime;
            $st = $db->prepare("DELETE FROM sessions WHERE last_access < ?");
            $st->bind_param('i', $cut);
            $st->execute();
            return $st->affected_rows;
        } catch (Throwable $e) {
            error_log('session gc: ' . $e->getMessage());
            return false;
        }
    }
}
