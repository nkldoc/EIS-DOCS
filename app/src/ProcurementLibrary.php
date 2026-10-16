<?php
declare(strict_types=1);
final class ProcurementLibrary
{
    public function __construct(private ProcurementTemplateStore $store) {}

    public function handle(string $owner, bool $admin, ?array $body, int $costId = 0): array
    {
        $sharedOwner = '__shared_document_types__';
        $shared = $this->store->handle($sharedOwner);
        $private = $this->store->handle($owner);
        $department = $this->store->departmentTemplates($costId, $owner);
        if (($body['action'] ?? '') === 'add_type') {
            if (!$admin) throw new DomainException('เฉพาะ Admin เท่านั้นที่เพิ่มชนิดเอกสารได้');
            $type = trim((string)($body['docType'] ?? ''));
            if (!preg_match('/^[A-Z0-9_]{1,10} - \S/u', $type)) throw new InvalidArgumentException('ระบุชนิดเอกสารในรูปแบบ AM - ชื่อชนิดเอกสาร');
            $shared = $this->store->handle($sharedOwner, $body);
        } elseif ($body !== null) {
            if (($body['action'] ?? '') === 'save') {
                $visibility = $body['visibility'] ?? 'private';
                if (!in_array($visibility, ['private','department'], true)) throw new InvalidArgumentException('การมองเห็นชุด Template ไม่ถูกต้อง');
                if ($visibility === 'department' && $costId < 1) throw new InvalidArgumentException('ไม่พบหน่วยงานของบัญชีที่เข้าสู่ระบบ');
                if (($body['mode'] ?? 'new') === 'update') {
                    $owned = array_filter($private['templates'], fn($item) => $item['id'] === ($body['id'] ?? ''));
                    if (!$owned) throw new DomainException('แก้ไขได้เฉพาะชุด Template ของตนเอง กรุณาบันทึกเป็นชุดใหม่');
                }
                $body['costId'] = $costId;
                $defaults = ['PR - ใบขอซื้อ/ขอจ้าง','PO - ใบสั่งซื้อ','GR - ใบรับสินค้า','IV - ใบแจ้งหนี้'];
                $allowed = array_merge($defaults, $shared['types'], $private['types'], array_column($department,'docType'));
                if (!in_array($body['docType'] ?? '', $allowed, true)) throw new InvalidArgumentException('กรุณาเลือกชนิดเอกสารที่มีอยู่ หรือให้ Admin เพิ่มชนิดเอกสารก่อน');
            }
            $private = $this->store->handle($owner, $body);
        }
        $private['types'] = array_values(array_unique(array_merge($shared['types'], $private['types'])));
        $private['canAddTypes'] = $admin;
        $private['canShareDepartment'] = $costId > 0;
        foreach ($private['templates'] as &$template) {
            $template['visibility'] ??= 'private';
            $template['canEdit'] = true;
        }
        unset($template);
        $private['templates'] = array_merge($private['templates'], $department);
        return $private;
    }
}
