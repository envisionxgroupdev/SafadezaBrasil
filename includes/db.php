<?php
/**
 * Camada de dados. Os dois drivers (JSON e MySQL) têm a MESMA interface,
 * então basta trocar DB_DRIVER no config.php para migrar para o MySQL.
 *
 * query($table, $opts) aceita:
 *   'where'      => ['campo' => valor, ...]           (igualdade)
 *   'in_set'     => ['campo', valor]                  (lista separada por vírgula contém valor)
 *   'search'     => [['campo1','campo2'], 'termo']    (LIKE em vários campos)
 *   'order'      => 'campo DESC'
 *   'limit'      => 24, 'offset' => 0
 */

interface DataDriver {
    public function query(string $table, array $opts = []): array;
    public function count(string $table, array $opts = []): int;
    public function find(string $table, $id): ?array;
    public function findBy(string $table, string $field, $value): ?array;
    public function insert(string $table, array $row): int;
    public function update(string $table, $id, array $row): bool;
    public function delete(string $table, $id): bool;
    public function increment(string $table, $id, string $field, int $by = 1): void;
}

/* ============================================================
 *  DRIVER JSON (temporário, sem banco)
 * ============================================================ */
class JsonDriver implements DataDriver {
    private string $dir;
    private array $cache = [];

    public function __construct(string $dir) {
        $this->dir = rtrim($dir, '/');
        if (!is_dir($this->dir)) mkdir($this->dir, 0775, true);
    }

    private function file(string $t): string { return "{$this->dir}/{$t}.json"; }

    private function load(string $t): array {
        if (isset($this->cache[$t])) return $this->cache[$t];
        $f = $this->file($t);
        $data = is_file($f) ? json_decode(file_get_contents($f), true) : null;
        if (!is_array($data)) $data = ['auto' => 0, 'rows' => []];
        return $this->cache[$t] = $data;
    }

    private function save(string $t, array $data): void {
        $this->cache[$t] = $data;
        $f = $this->file($t);
        $tmp = $f . '.tmp';
        file_put_contents($tmp, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
        rename($tmp, $f);
    }

    private function filter(string $t, array $o): array {
        $rows = array_values($this->load($t)['rows']);
        if (!empty($o['where'])) {
            $rows = array_filter($rows, function ($r) use ($o) {
                foreach ($o['where'] as $k => $v) if ((string)($r[$k] ?? '') !== (string)$v) return false;
                return true;
            });
        }
        if (!empty($o['in_set'])) {
            [$f, $v] = $o['in_set'];
            $rows = array_filter($rows, fn($r) => in_array((string)$v, array_map('trim', explode(',', (string)($r[$f] ?? ''))), true));
        }
        if (!empty($o['search']) && $o['search'][1] !== '') {
            [$fields, $term] = $o['search'];
            $term = mb_strtolower($term);
            $rows = array_filter($rows, function ($r) use ($fields, $term) {
                foreach ($fields as $f) if (mb_strpos(mb_strtolower((string)($r[$f] ?? '')), $term) !== false) return true;
                return false;
            });
        }
        $rows = array_values($rows);
        if (!empty($o['order'])) {
            if ($o['order'] === 'RAND()') { shuffle($rows); }
            else {
                [$f, $dir] = array_pad(explode(' ', $o['order']), 2, 'ASC');
                $desc = strtoupper($dir) === 'DESC';
                usort($rows, function ($a, $b) use ($f, $desc) {
                    $x = $a[$f] ?? ''; $y = $b[$f] ?? '';
                    $c = is_numeric($x) && is_numeric($y) ? $x <=> $y : strcmp((string)$x, (string)$y);
                    return $desc ? -$c : $c;
                });
            }
        }
        return $rows;
    }

    public function query(string $t, array $o = []): array {
        $rows = $this->filter($t, $o);
        if (isset($o['limit'])) $rows = array_slice($rows, (int)($o['offset'] ?? 0), (int)$o['limit']);
        return $rows;
    }
    public function count(string $t, array $o = []): int { return count($this->filter($t, $o)); }

    public function find(string $t, $id): ?array { return $this->load($t)['rows'][(string)$id] ?? null; }

    public function findBy(string $t, string $f, $v): ?array {
        foreach ($this->load($t)['rows'] as $r) if ((string)($r[$f] ?? '') === (string)$v) return $r;
        return null;
    }

    public function insert(string $t, array $row): int {
        $d = $this->load($t);
        $id = ++$d['auto'];
        $row = ['id' => $id] + $row;
        $d['rows'][(string)$id] = $row;
        $this->save($t, $d);
        return $id;
    }

    public function update(string $t, $id, array $row): bool {
        $d = $this->load($t);
        if (!isset($d['rows'][(string)$id])) return false;
        $d['rows'][(string)$id] = array_merge($d['rows'][(string)$id], $row, ['id' => (int)$id]);
        $this->save($t, $d);
        return true;
    }

    public function delete(string $t, $id): bool {
        $d = $this->load($t);
        if (!isset($d['rows'][(string)$id])) return false;
        unset($d['rows'][(string)$id]);
        $this->save($t, $d);
        return true;
    }

    public function increment(string $t, $id, string $f, int $by = 1): void {
        $r = $this->find($t, $id);
        if ($r) $this->update($t, $id, [$f => (int)($r[$f] ?? 0) + $by]);
    }
}

/* ============================================================
 *  DRIVER MYSQL (pronto — só ativar no config.php)
 * ============================================================ */
class MysqlDriver implements DataDriver {
    private PDO $pdo;
    private array $columns = [];

    public function __construct() {
        $dsn = 'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;
        $this->pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }

    private function cols(string $t): array {
        if (!isset($this->columns[$t])) {
            $this->columns[$t] = array_column($this->pdo->query("SHOW COLUMNS FROM `$t`")->fetchAll(), 'Field');
        }
        return $this->columns[$t];
    }
    private function col(string $t, string $c): string {
        if (!in_array($c, $this->cols($t), true)) throw new InvalidArgumentException("Coluna inválida: $c");
        return "`$c`";
    }

    private function build(string $t, array $o, array &$p): string {
        $w = [];
        foreach ($o['where'] ?? [] as $k => $v) { $w[] = $this->col($t, $k) . ' = ?'; $p[] = $v; }
        if (!empty($o['in_set'])) { $w[] = 'FIND_IN_SET(?, ' . $this->col($t, $o['in_set'][0]) . ')'; $p[] = $o['in_set'][1]; }
        if (!empty($o['search']) && $o['search'][1] !== '') {
            $or = [];
            foreach ($o['search'][0] as $f) { $or[] = $this->col($t, $f) . ' LIKE ?'; $p[] = '%' . $o['search'][1] . '%'; }
            $w[] = '(' . implode(' OR ', $or) . ')';
        }
        return $w ? ' WHERE ' . implode(' AND ', $w) : '';
    }

    public function query(string $t, array $o = []): array {
        $p = [];
        $sql = "SELECT * FROM `$t`" . $this->build($t, $o, $p);
        if (!empty($o['order'])) {
            if ($o['order'] === 'RAND()') $sql .= ' ORDER BY RAND()';
            else {
                [$f, $d] = array_pad(explode(' ', $o['order']), 2, 'ASC');
                $sql .= ' ORDER BY ' . $this->col($t, $f) . (strtoupper($d) === 'DESC' ? ' DESC' : ' ASC');
            }
        }
        if (isset($o['limit'])) $sql .= ' LIMIT ' . (int)$o['limit'] . ' OFFSET ' . (int)($o['offset'] ?? 0);
        $st = $this->pdo->prepare($sql); $st->execute($p);
        return $st->fetchAll();
    }

    public function count(string $t, array $o = []): int {
        $p = [];
        $st = $this->pdo->prepare("SELECT COUNT(*) FROM `$t`" . $this->build($t, $o, $p));
        $st->execute($p);
        return (int)$st->fetchColumn();
    }

    public function find(string $t, $id): ?array {
        $st = $this->pdo->prepare("SELECT * FROM `$t` WHERE id = ?"); $st->execute([$id]);
        return $st->fetch() ?: null;
    }

    public function findBy(string $t, string $f, $v): ?array {
        $st = $this->pdo->prepare("SELECT * FROM `$t` WHERE " . $this->col($t, $f) . " = ? LIMIT 1"); $st->execute([$v]);
        return $st->fetch() ?: null;
    }

    public function insert(string $t, array $row): int {
        $row = array_intersect_key($row, array_flip($this->cols($t)));
        $c = implode(',', array_map(fn($k) => "`$k`", array_keys($row)));
        $q = implode(',', array_fill(0, count($row), '?'));
        $this->pdo->prepare("INSERT INTO `$t` ($c) VALUES ($q)")->execute(array_values($row));
        return (int)$this->pdo->lastInsertId();
    }

    public function update(string $t, $id, array $row): bool {
        $row = array_intersect_key($row, array_flip($this->cols($t)));
        unset($row['id']);
        if (!$row) return false;
        $set = implode(',', array_map(fn($k) => "`$k` = ?", array_keys($row)));
        return $this->pdo->prepare("UPDATE `$t` SET $set WHERE id = ?")->execute([...array_values($row), $id]);
    }

    public function delete(string $t, $id): bool {
        return $this->pdo->prepare("DELETE FROM `$t` WHERE id = ?")->execute([$id]);
    }

    public function increment(string $t, $id, string $f, int $by = 1): void {
        $c = $this->col($t, $f);
        $this->pdo->prepare("UPDATE `$t` SET $c = $c + ? WHERE id = ?")->execute([$by, $id]);
    }
}

function db(): DataDriver {
    static $d = null;
    if ($d === null) {
        $d = DB_DRIVER === 'mysql' ? new MysqlDriver() : new JsonDriver(__DIR__ . '/../data');
    }
    return $d;
}
