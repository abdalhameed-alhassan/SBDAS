<?php
/**
 * includes/session_handler.php
 * -----------------------------------------------------------
 * PHP session handler that stores sessions in the MySQL table `sessions` instead of local files
 * (required on Vercel / serverless, where session files neither persist nor are shared between instances).
 *
 * Authentication logic is unchanged: the application still uses $_SESSION, session_start() and
 * session_regenerate_id(); only the place where session data is stored differs.
 *
 * Concurrent requests: the handler uses its own MySQL connection, separate from the application's.
 * read() opens a transaction and locks the session row with SELECT ... FOR UPDATE until write()/close(),
 * which mirrors the default file-locking behaviour: two requests for the same session run one after the
 * other, so no update is lost. The locking works on MySQL, MariaDB and TiDB (it does not use GET_LOCK).
 * -----------------------------------------------------------
 */
require_once __DIR__ . '/db_conn.php';

class SbdasDbSessionHandler implements SessionHandlerInterface, SessionUpdateTimestampHandlerInterface
{
    /** @var mysqli|null */
    private $db = null;
    private $inTx = false;
    private $lockWait;

    public function __construct($lockWaitSeconds = 10) { $this->lockWait = (int)$lockWaitSeconds; }

    private function conn() {
        if ($this->db === null) {
            $this->db = newDbConnection();
            $this->db->query("SET SESSION innodb_lock_wait_timeout = " . max(1, $this->lockWait));
            // READ COMMITTED avoids gap locks when SELECT ... FOR UPDATE targets a row that does not exist yet.
            // Without it, new visitors arriving at the same moment hit a deadlock (found during testing).
            // It does not affect the application's own connection, which is separate.
            $this->db->query("SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED");
        }
        return $this->db;
    }

    private function finishTx($commit = true) {
        if ($this->inTx && $this->db) {
            try { $commit ? $this->db->commit() : $this->db->rollback(); } catch (Throwable $e) { error_log('session tx end: ' . $e->getMessage()); }
        }
        $this->inTx = false;
    }

    public function open(string $path, string $name): bool { return true; }

    public function close(): bool {
        $this->finishTx(true);
        return true;
    }

    // Check that the id exists (use_strict_mode): prevents accepting a session id the server never issued.
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
        // Short retry on deadlock (1213) or lock wait timeout (1205): rare, but possible under concurrent load.
        for ($attempt = 1; $attempt <= 3; $attempt++) {
            try {
                $db = $this->conn();
                $this->finishTx(true);
                $db->begin_transaction();
                $this->inTx = true;

                $st = $db->prepare("SELECT data FROM sessions WHERE id = ? FOR UPDATE");
                $st->bind_param('s', $id);
                $st->execute();
                $row = $st->get_result()->fetch_assoc();
                if ($row === null) {
                    // New session: create the row immediately so that it is locked
                    // (INSERT IGNORE protects against a race creating the same id twice).
                    $now = time();
                    $ins = $db->prepare("INSERT IGNORE INTO sessions (id, data, created_at, last_access) VALUES (?, '', ?, ?)");
                    $ins->bind_param('sii', $id, $now, $now);
                    $ins->execute();
                    $st->execute();
                    $row = $st->get_result()->fetch_assoc();
                }
                return (string)($row['data'] ?? '');
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
            // user_id is stored for administration only (taken from the session data, never used for authentication).
            if (preg_match('/(?:^|;)user_id\|(?:i:(\d+)|s:\d+:"(\d+)");/', $data, $m)) { $uid = (int)($m[1] !== '' ? $m[1] : ($m[2] ?? 0)); }
            $st = $db->prepare(
                "INSERT INTO sessions (id, data, user_id, created_at, last_access) VALUES (?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE data = VALUES(data), user_id = VALUES(user_id), last_access = VALUES(last_access)"
            );
            $st->bind_param('ssiii', $id, $data, $uid, $now, $now);
            $st->execute();
            $this->finishTx(true);
            return true;
        } catch (Throwable $e) {
            error_log('session write: ' . $e->getMessage());
            $this->finishTx(false);
            return false;
        }
    }

    // Refresh only the last-activity time when the session data did not change.
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
