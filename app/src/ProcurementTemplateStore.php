<?php
declare(strict_types=1);

/** Private template library: the authenticated owner determines the storage file. */
final class ProcurementTemplateStore
{
    private string $directory;

    public function __construct(string $directory)
    {
        $this->directory = $directory;
    }

    public function handle(string $owner, ?array $request = null): array
    {
        if ($owner === '') throw new InvalidArgumentException('กรุณาเข้าสู่ระบบ');
        $path = $this->directory . '/' . hash('sha256', $owner) . '.json';
        // Loading a library must not create directories or require write access.
        // Writers replace the JSON file atomically, so GET can read the snapshot.
        if ($request === null) {
            $data = ['types' => [], 'templates' => []];
            if (is_file($path)) {
                $raw = file_get_contents($path);
                if ($raw === false) throw new RuntimeException('ไม่สามารถอ่านชุด Template ได้');
                $data = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
                if (!is_array($data) || !is_array($data['types'] ?? null) || !is_array($data['templates'] ?? null)) {
                    throw new RuntimeException('รูปแบบไฟล์ชุด Template ไม่ถูกต้อง');
                }
            }
            return ['success' => true, 'types' => $data['types'], 'templates' => $data['templates'], 'savedId' => null];
        }
        if (!is_dir($this->directory) && !mkdir($this->directory, 0700, true) && !is_dir($this->directory)) {
            throw new RuntimeException('ไม่สามารถสร้างพื้นที่บันทึก Template ได้');
        }
        $lock = fopen($path . '.lock', 'c');
        if (!$lock) throw new RuntimeException('ไม่สามารถเปิดพื้นที่บันทึก Template ได้');
        try {
            if (!flock($lock, LOCK_EX)) throw new RuntimeException('ไม่สามารถล็อกข้อมูล Template ได้');
            $data = is_file($path) ? json_decode((string) file_get_contents($path), true, 64, JSON_THROW_ON_ERROR) : ['types' => [], 'templates' => []];
            $savedId = null;
            if ($request !== null) {
                $type = self::label($request['docType'] ?? '', 100);
                if (!preg_match('/^[A-Z0-9_]{1,10}\s*-\s*\S/u', $type)) throw new InvalidArgumentException('ชนิดเอกสารต้องเป็น รหัส - ชื่อ โดยรหัสเป็น A-Z, 0-9 หรือ _ ไม่เกิน 10 ตัว');
                if (($request['action'] ?? '') === 'add_type') {
                    if (!in_array($type, $data['types'], true)) $data['types'][] = $type;
                } elseif (($request['action'] ?? '') === 'save') {
                    $name = self::label($request['name'] ?? '', 150);
                    $catalog = self::catalog($request['catalog'] ?? null);
                    $ids = $request['selectedIds'] ?? [];
                    if (!is_array($ids) || count($ids) > 1000) throw new InvalidArgumentException('รายการที่เลือกไม่ถูกต้อง');
                    foreach ($ids as $id) {
                        if (!is_int($id) && !is_string($id)) throw new InvalidArgumentException('รหัสรายการที่เลือกไม่ถูกต้อง');
                    }
                    $flat = [];
                    foreach ($catalog as $group) {
                        $flat[] = $group;
                        foreach ($group['children'] as $child) $flat[] = $child;
                    }
                    $ids = array_map('strval', $ids);
                    $documents = array_values(array_filter($flat, fn($item) => in_array((string) $item['id'], $ids, true)));
                    $index = null;
                    $sameName = null;
                    foreach ($data['templates'] as $i => $template) {
                        if ($template['docType'] !== $type) continue;
                        if ($template['id'] === ($request['id'] ?? '')) $index = $i;
                        if (mb_strtolower($template['name']) === mb_strtolower($name)) $sameName = $i;
                    }
                    if (($request['mode'] ?? 'new') === 'update') {
                        $index ??= $sameName;
                        if ($index === null) throw new InvalidArgumentException('ไม่พบชุด Template ที่ต้องการอัปเดต');
                        if ($sameName !== null && $sameName !== $index) throw new InvalidArgumentException('ชื่อชุดนี้ถูกใช้แล้ว กรุณาเลือกชื่ออื่น');
                    } else {
                        if ($sameName !== null) throw new InvalidArgumentException('มีชื่อชุดนี้แล้ว กรุณาใช้ปุ่มอัปเดตทับชุดเดิม');
                        $index = null;
                    }
                    $savedId = $index === null ? bin2hex(random_bytes(16)) : $data['templates'][$index]['id'];
                    $template = ['id' => $savedId, 'docType' => $type, 'name' => $name, 'catalog' => $catalog, 'documents' => $documents, 'updatedAt' => date(DATE_ATOM),
                        'visibility' => ($request['visibility'] ?? 'private') === 'department' ? 'department' : 'private',
                        'costId' => (int)($request['costId'] ?? 0), 'ownerId' => $owner];
                    if ($index === null) $data['templates'][] = $template;
                    else $data['templates'][$index] = $template;
                } else {
                    throw new InvalidArgumentException('คำสั่งไม่ถูกต้อง');
                }
                $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
                $temporary = tempnam($this->directory, 'template-');
                if ($temporary === false) throw new RuntimeException('ไม่สามารถบันทึก Template ได้');
                try {
                    if (file_put_contents($temporary, $json) === false || !rename($temporary, $path)) throw new RuntimeException('ไม่สามารถบันทึก Template ได้');
                } finally {
                    if (is_file($temporary)) unlink($temporary);
                }
            }
            return ['success' => true, 'types' => $data['types'], 'templates' => $data['templates'], 'savedId' => $savedId];
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public function departmentTemplates(int $costId, string $owner): array
    {
        if ($costId < 1) return [];
        $templates = [];
        foreach (glob($this->directory.'/*.json') ?: [] as $path) {
            if (basename($path) === hash('sha256', $owner).'.json') continue;
            $data = json_decode((string)file_get_contents($path), true, 64, JSON_THROW_ON_ERROR);
            foreach ($data['templates'] ?? [] as $template) {
                if (($template['visibility'] ?? 'private') === 'department' && (int)($template['costId'] ?? 0) === $costId) {
                    $template['canEdit'] = false;
                    $templates[] = $template;
                }
            }
        }
        return $templates;
    }

    private static function label($value, int $limit): string
    {
        if (!is_string($value) || trim($value) === '' || mb_strlen(trim($value)) > $limit) throw new InvalidArgumentException('กรุณาระบุชื่อไม่เกิน ' . $limit . ' ตัวอักษร');
        return trim($value);
    }

    private static function isList(array $items): bool
    {
        return $items === [] || array_keys($items) === range(0, count($items) - 1);
    }

    private static function catalog($catalog): array
    {
        if (!is_array($catalog) || !self::isList($catalog) || count($catalog) > 300) throw new InvalidArgumentException('หัวข้อ Template ไม่ถูกต้อง');
        $seen = [];
        $normalize = function ($item) use (&$seen): array {
            if (!is_array($item) || (!is_int($item['id'] ?? null) && !is_string($item['id'] ?? null))) throw new InvalidArgumentException('หัวข้อไม่ถูกต้อง');
            $id = (string) $item['id'];
            if ($id === '' || strlen($id) > 150 || isset($seen[$id])) throw new InvalidArgumentException('รหัสหัวข้อซ้ำหรือไม่ถูกต้อง');
            $seen[$id] = true;
            foreach (['page', 'group'] as $key) {
                if (!filter_var($item[$key] ?? null, FILTER_VALIDATE_INT) || (int) $item[$key] < 1) throw new InvalidArgumentException('ชุดและหน้าต้องเป็นจำนวนเต็มตั้งแต่ 1');
            }
            return ['id' => $item['id'], 'title' => self::label($item['title'] ?? '', 500), 'page' => (int) $item['page'], 'group' => (int) $item['group'], 'status' => 1, 'sigLayout' => 1];
        };
        $result = [];
        foreach ($catalog as $group) {
            $row = $normalize($group);
            $children = $group['children'] ?? [];
            if (!is_array($children) || !self::isList($children)) throw new InvalidArgumentException('รายการย่อยไม่ถูกต้อง');
            $row['children'] = array_map($normalize, $children);
            $result[] = $row;
        }
        if (count($seen) > 1000) throw new InvalidArgumentException('หัวข้อรวมต้องไม่เกิน 1000 รายการ');
        return $result;
    }
}
