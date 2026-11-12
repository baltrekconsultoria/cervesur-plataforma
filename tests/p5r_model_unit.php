<?php
/** P5-R modelo real con PDO inerte: no DB, ni red, ni contrasenas reales. */
declare(strict_types=1);
ini_set('log_errors', '0');
putenv('AUDIT_LOG_MODE=required');
require __DIR__ . '/../backend/models/UserModel.php';
final class P5RStmt extends PDOStatement {
    private P5RPdo $db;
    private string $sql;
    public function __construct(P5RPdo $db, string $sql) { $this->db=$db; $this->sql=$sql; }
    public function execute(?array $params = null): bool {
        if (str_starts_with($this->sql, 'UPDATE user SET password')) {
            $this->db->updates++;
            $this->db->hashValid = password_verify('ContraseguraQA2026!', (string)($params['p'] ?? ''));
        }
        if (str_starts_with($this->sql, 'INSERT INTO audit_log')) {
            $this->db->audits++;
            $this->db->auditSafe = !str_contains(json_encode($params), 'ContraseguraQA2026!')
                && !str_contains((string)($params[':af'] ?? ''), 'password_hash')
                && ($params[':ac'] ?? '') === 'user.password';
        }
        return true;
    }
    public function fetch(int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed {
        if (str_contains($this->sql, 'FROM user WHERE id')) {
            return ['id' => 42, 'username' => 'qa.42', 'email' => 'qa@example.invalid',
                    'first_name' => 'QA', 'last_name' => 'User', 'phone' => '',
                    'role' => $this->db->targetRole, 'is_active' => $this->db->active ? 1 : 0];
        }
        return false;
    }
    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array {
        return str_contains($this->sql, 'LOWER(TRIM(role))') ? [1] : [];
    }
}
final class P5RPdo extends PDO {
    public string $targetRole = 'user';
    public bool $active = true;
    public int $updates = 0;
    public int $audits = 0;
    public bool $auditSafe = false;
    public bool $hashValid = false;
    private bool $tx = false;
    public function __construct() {} // Nunca llamar PDO::__construct / nunca DSN.
    public function prepare(string $query, array $options = []): PDOStatement|false { return new P5RStmt($this, $query); }
    public function query(string $query, ?int $fetchMode = null, mixed ...$args): PDOStatement|false { return new P5RStmt($this, $query); }
    public function inTransaction(): bool { return $this->tx; }
    public function beginTransaction(): bool { $this->tx=true; return true; }
    public function commit(): bool { $this->tx=false; return true; }
    public function rollBack(): bool { $this->tx=false; return true; }
}
$fail = 0; $pass = 0;
function checkP5R(string $label, bool $ok): void {
    global $fail,$pass;
    if ($ok) { $pass++; echo "PASS $label\n"; }
    else { $fail++; echo "FAIL $label\n"; }
}
$db = new P5RPdo();
$model = new UserModel($db);
$db->targetRole='admin';
$r=$model->setPassword(42,'ContraseguraQA2026!',1);
checkP5R('bloquea otro admin', $r['success'] === false && $db->updates === 0 && $db->audits === 0);
$db->targetRole='user'; $db->active=false;
$r=$model->setPassword(42,'ContraseguraQA2026!',1);
checkP5R('bloquea usuario inactivo', $r['success'] === false && $db->updates === 0);
$db->active=true;
$r=$model->setPassword(42,'ContraseguraQA2026!',99);
checkP5R('bloquea actor no admin', $r['success'] === false && $db->updates === 0);
$r=$model->setPassword(42,'ContraseguraQA2026!',1);
checkP5R('actualiza solo usuario estandar', $r['success'] === true && $db->updates === 1 && $db->hashValid);
checkP5R('auditoria unica sin clave', $db->audits === 1 && $db->auditSafe);
echo "P5R_MODEL=" . ($fail === 0 ? "PASS_$pass" : "FAIL_$fail") . "\n";
echo "MYSQL_CONTACTADO=NO\n";
exit($fail === 0 ? 0 : 1);
