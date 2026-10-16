<?php
declare(strict_types=1);

/** Single-table SQL grammar. Identifiers come from metadata; values are bound parameters. */
final class SqlConsole
{
    private array $tokens = [];
    private int $position = 0;
    private array $params = [];
    private array $columns;

    public static function quote(string $name): string { return '[' . str_replace(']', ']]', $name) . ']'; }

    public function compile(string $query, string $schema, string $table, array $columns): array
    {
        $query = preg_replace('/^\xEF\xBB\xBF/', '', $query);
        if (strlen($query) > 20000) throw new InvalidArgumentException('SQL ยาวเกินกำหนด');
        $this->tokens = []; $this->position = 0; $this->params = []; $this->columns = $columns;
        $offset = 0;
        while ($offset < strlen($query)) {
            if (!preg_match('/\G\s*(--[^\r\n]*|\/\*[\s\S]*?\*\/|\[(?:[^\]]|\]\])+\]|\'(?:[^\']|\'\')*\'|-?\d+(?:\.\d+)?|[A-Za-z_][A-Za-z0-9_]*|<>|!=|<=|>=|[=<>(),.*;])/A', $query, $match, 0, $offset)) {
                if (trim(substr($query, $offset)) === '') break;
                throw new InvalidArgumentException('SQL มีรูปแบบที่ไม่รองรับ');
            }
            $offset += strlen($match[0]);
            if (str_starts_with($match[1], '--')) continue;
            if (str_starts_with($match[1], '/*')) {
                if (substr_count($match[1], '/*') > 1) throw new InvalidArgumentException('ไม่รองรับ block comment ซ้อนกัน');
                continue;
            }
            $this->tokens[] = $match[1];
        }
        $action = strtoupper($this->take());
        if ($action === 'SELECT') {
            $limit = 200;
            if ($this->accept('TOP')) {
                $limitToken = $this->take();
                if (!ctype_digit($limitToken) || (int)$limitToken < 1 || (int)$limitToken > 500) throw new InvalidArgumentException('TOP ต้องอยู่ระหว่าง 1–500');
                $limit = (int)$limitToken;
            }
            $fields = [];
            if ($this->accept('*')) $fields[] = '*';
            else { do { $fields[] = $this->column(); } while ($this->accept(',')); }
            $this->expect('FROM');
            $prefix = 'SELECT TOP ' . $limit . ' ' . implode(',', $fields) . ' FROM ';
        } elseif ($action === 'UPDATE') {
            $prefix = 'UPDATE ';
        } elseif ($action === 'DELETE') {
            $this->expect('FROM'); $prefix = 'DELETE FROM ';
        } else throw new InvalidArgumentException('รองรับ SELECT, UPDATE และ DELETE เท่านั้น');
        $first = $this->identifier();
        if ($this->accept('.')) { $givenSchema = $first; $givenTable = $this->identifier(); }
        else { $givenSchema = $schema; $givenTable = $first; }
        if ($givenSchema !== $schema || $givenTable !== $table) throw new InvalidArgumentException('ชื่อตารางใน SQL ต้องตรงกับตารางที่เลือก');
        $sql = $prefix . self::quote($schema) . '.' . self::quote($table);
        if ($action === 'UPDATE') {
            $this->expect('SET'); $sets = [];
            do { $column = $this->column(); $this->expect('='); $sets[] = $column . '=' . $this->value(); } while ($this->accept(','));
            $sql .= ' SET ' . implode(',', $sets);
        }
        $hasWhere = $this->accept('WHERE');
        $setParamCount = count($this->params);
        $where = $hasWhere ? ' WHERE ' . $this->conditions() : '';
        $sql .= $where;
        if ($action !== 'SELECT' && !$hasWhere) throw new InvalidArgumentException('UPDATE / DELETE ต้องระบุ WHERE');
        $this->accept(';');
        if ($this->position !== count($this->tokens)) throw new InvalidArgumentException('อนุญาต SQL ครั้งละหนึ่งคำสั่ง โดยใช้ตารางที่เลือกเท่านั้น');
        return ['action'=>$action, 'sql'=>$sql, 'params'=>$this->params,
            'where'=>$where, 'whereParams'=>array_slice($this->params, $setParamCount)];
    }

    private function conditions(int $depth = 0): string
    {
        if ($depth > 12) throw new InvalidArgumentException('WHERE ซ้อนมากเกินไป');
        $parts = [];
        do {
            if ($this->accept('(')) { $parts[] = '(' . $this->conditions($depth + 1) . ')'; $this->expect(')'); }
            else {
                $column = $this->column(); $op = strtoupper($this->take());
                if ($op === 'IS') { $not = $this->accept('NOT'); $this->expect('NULL'); $parts[] = $column . ' IS ' . ($not ? 'NOT ' : '') . 'NULL'; }
                elseif ($op === 'IN' || $op === 'NOT') {
                    $not = $op === 'NOT';
                    if ($not) $this->expect('IN');
                    $this->expect('('); $values = [];
                    do {
                        if (count($values) >= 1000) throw new InvalidArgumentException('IN รองรับไม่เกิน 1,000 ค่าต่อรายการ');
                        $values[] = $this->value();
                    } while ($this->accept(','));
                    $this->expect(')');
                    $parts[] = $column . ($not ? ' NOT IN (' : ' IN (') . implode(',', $values) . ')';
                }
                else {
                    if (!in_array($op, ['=','!=','<>','<','>','<=','>=','LIKE'], true)) throw new InvalidArgumentException('เงื่อนไขไม่รองรับ');
                    $parts[] = $column . ' ' . $op . ' ' . $this->value();
                }
            }
            $next = strtoupper($this->tokens[$this->position] ?? '');
            if (!in_array($next, ['AND','OR'], true)) break;
            $parts[] = $this->take();
        } while (true);
        return implode(' ', $parts);
    }
    private function column(): string
    {
        $name = $this->identifier();
        if (!in_array($name, $this->columns, true)) throw new InvalidArgumentException('ไม่พบคอลัมน์ ' . $name);
        return self::quote($name);
    }
    private function identifier(): string
    {
        $token = $this->take();
        if ($token[0] === '[') return str_replace(']]', ']', substr($token, 1, -1));
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $token)) throw new InvalidArgumentException('ชื่อ SQL ไม่ถูกต้อง');
        return $token;
    }
    private function value(): string
    {
        $token = $this->take();
        if (strtoupper($token) === 'N' && str_starts_with($this->tokens[$this->position] ?? '', "'")) $token = $this->take();
        if (strtoupper($token) === 'NULL') return 'NULL';
        if (str_starts_with($token, "'")) $value = str_replace("''", "'", substr($token, 1, -1));
        elseif (preg_match('/^-?\d+(?:\.\d+)?$/D', $token)) $value = $token;
        else throw new InvalidArgumentException('ค่าต้องเป็นตัวเลข, NULL หรือข้อความใน single quote');
        if (count($this->params) >= 2000) throw new InvalidArgumentException('SQL รองรับไม่เกิน 2,000 parameter');
        $this->params[] = $value; return '?';
    }
    private function take(): string
    {
        if (!isset($this->tokens[$this->position])) throw new InvalidArgumentException('SQL ยังไม่สมบูรณ์');
        return $this->tokens[$this->position++];
    }
    private function accept(string $token): bool
    {
        if (strtoupper($this->tokens[$this->position] ?? '') !== $token) return false;
        $this->position++; return true;
    }
    private function expect(string $token): void
    {
        if (!$this->accept($token)) throw new InvalidArgumentException('SQL ต้องมี ' . $token);
    }
}
