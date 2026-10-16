<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');

if (!Auth::check()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'กรุณาเข้าสู่ระบบใหม่'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $connection = Database::connect('erp');
    $query = trim((string) ($_GET['q'] ?? ''));
    $like = '%' . $query . '%';
    $statement = sqlsrv_query($connection, <<<'SQL'
SELECT TOP (30)
    tor_id,
    LTRIM(RTRIM(c_code)) AS c_code,
    LTRIM(RTRIM(c_name)) AS c_name,
    ISNULL(f_total_amt, 0) AS f_total_amt,
    LTRIM(RTRIM(ISNULL(c_department, ''))) AS c_department,
    CONVERT(varchar(10), COALESCE(d_doc_date, d_tor_date, CAST(d_create AS date)), 23) AS doc_date,
    LTRIM(RTRIM(COALESCE(NULLIF(c_pdf, ''), NULLIF(upload, ''), ''))) AS file_path
FROM dbo.sp_tor
WHERE NULLIF(LTRIM(RTRIM(c_code)), '') IS NOT NULL
  AND (? = '' OR c_code LIKE ? OR c_name LIKE ?)
ORDER BY tor_id DESC
SQL, [$query, $like, $like]);
    if ($statement === false) {
        throw new RuntimeException('ไม่สามารถค้นหาเอกสาร PR ได้');
    }

    $documents = [];
    while ($row = sqlsrv_fetch_array($statement, SQLSRV_FETCH_ASSOC)) {
        $documents[] = [
            'id' => (string) $row['tor_id'],
            'code' => (string) $row['c_code'],
            'name' => (string) $row['c_name'],
            'amount' => (float) $row['f_total_amt'],
            'department' => (string) $row['c_department'],
            'docDate' => (string) ($row['doc_date'] ?? ''),
            'filePath' => (string) $row['file_path'],
        ];
    }
    sqlsrv_free_stmt($statement);
    sqlsrv_close($connection);
    echo json_encode(['success' => true, 'documents' => $documents], JSON_UNESCAPED_UNICODE);
} catch (Throwable $error) {
    error_log('PR search API failed: ' . $error->getMessage() . ' ' . json_encode(sqlsrv_errors(), JSON_UNESCAPED_UNICODE));
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $error->getMessage()], JSON_UNESCAPED_UNICODE);
}
