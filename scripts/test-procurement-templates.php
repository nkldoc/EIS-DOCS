<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/app/src/ProcurementTemplateStore.php';
function check(bool $value, string $message): void { if (!$value) throw new RuntimeException($message); }
function rejected(callable $callback): void {
    try { $callback(); } catch (InvalidArgumentException $error) { return; }
    throw new RuntimeException('Expected request rejection');
}
$directory = sys_get_temp_dir() . '/eis-procurement-test-' . bin2hex(random_bytes(8));
$store = new ProcurementTemplateStore($directory);
try {
    $empty = $store->handle('owner-a');
    check($empty['templates'] === [] && !file_exists($directory), 'First GET requires no directory creation or write access');
    $catalog = [['id' => 1, 'title' => 'Master', 'page' => 1, 'group' => 1, 'children' => [['id' => 2, 'title' => 'Detail', 'page' => 2, 'group' => 1]]]];
    $request = ['action' => 'save', 'docType' => 'PR - ใบขอซื้อ/ขอจ้าง', 'name' => 'จัดซื้อต่ำกว่า 5 แสน', 'catalog' => $catalog, 'selectedIds' => ['1', '2'], 'mode' => 'new'];
    $first = $store->handle('owner-a', $request);
    check(count($first['templates']) === 1 && count($first['templates'][0]['documents']) === 2, 'Save catalog and selection');
    $id = $first['savedId'];
    $second = $store->handle('owner-a', array_replace($request, ['name' => 'จัดซื้อ E-bidding']));
    check(count($second['templates']) === 2, 'Separate named sets');
    rejected(fn() => $store->handle('owner-a', $request));
    $request['mode'] = 'update';
    $request['id'] = $id;
    $request['catalog'][0]['title'] = 'Updated';
    $request['selectedIds'] = ['2'];
    $updated = $store->handle('owner-a', $request);
    check(count($updated['templates']) === 2 && $updated['savedId'] === $id, 'Update preserves ID and set count');
    check($updated['templates'][0]['catalog'][0]['title'] === 'Updated', 'Update content');
    check(count($updated['templates'][0]['documents']) === 1, 'Update selected detail');
    check(count($store->handle('owner-b')['templates']) === 0, 'Owner B cannot list owner A templates');
    rejected(fn() => $store->handle('owner-b', $request));
    rejected(fn() => $store->handle('owner-a', array_replace($request, ['name' => 'จัดซื้อ E-bidding'])));
    $store->handle('owner-b', array_replace($request, ['mode' => 'new']));
    check(count($store->handle('owner-b')['templates']) === 1, 'Same name permitted for another owner');
    $gr = $store->handle('owner-a', array_replace($request, ['mode' => 'new', 'docType' => 'GR - ใบรับสินค้า']));
    check(count($gr['templates']) === 3, 'Same name permitted under another document type');
    $store->handle('owner-a', ['action' => 'add_type', 'docType' => 'CT - สัญญา']);
    check($store->handle('owner-a')['types'] === ['CT - สัญญา'], 'Custom type persists');
    check($store->handle('owner-b')['types'] === [], 'Custom type private');
    $reopened = new ProcurementTemplateStore($directory);
    check(count($reopened->handle('owner-a')['templates']) === 3, 'Persists across store instances');
    $lockFile = $directory . '/' . hash('sha256', 'owner-a') . '.json.lock';
    unlink($lockFile);
    check(count($reopened->handle('owner-a')['templates']) === 3 && !file_exists($lockFile), 'Existing GET does not create a lock file');
    $invalid = $request;
    $invalid['catalog'][0]['children'][0]['id'] = 1;
    rejected(fn() => $store->handle('owner-a', $invalid));
    rejected(fn() => $store->handle('owner-a', array_replace($request, ['docType' => 'INVALID'])));
    rejected(fn() => $store->handle('', $request));
    check(count($store->handle('owner-a')['templates']) === 3, 'Invalid request does not corrupt saved sets');
    echo "PASS: create, update, duplicate names, type separation, owner isolation, persistence and validation\n";
} finally {
    if (is_dir($directory)) {
        foreach (glob($directory . '/*') as $file) unlink($file);
        rmdir($directory);
    }
}
