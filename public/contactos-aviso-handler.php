<?php
declare(strict_types=1);
if (!defined('CO_CONTACT_NOTICE_HANDLER')) { http_response_code(404); exit; }
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');
$noticeUser = requireUser($db);
$noticeUid = (int)$noticeUser['id'];
$noticeVersion = 'CONTACTOS-321-v1';
$noticeMethod = $_SERVER['REQUEST_METHOD'] ?? '';
if ($action === 'contact_notice_seen') {
    if ($noticeMethod !== 'POST') out(['ok'=>false,'error'=>'Método no permitido.'],405);
    $noticeData = body();
    requireCsrf($noticeData);
    $stmt = $db->prepare('INSERT INTO company_notice_receipts (user_id,notice_key,seen_at) VALUES (?,?,UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE notice_key=VALUES(notice_key)');
    $stmt->execute([$noticeUid,$noticeVersion]);
    out(['ok'=>true]);
}
if ($noticeMethod !== 'GET') out(['ok'=>false,'error'=>'Método no permitido.'],405);
$noticePlan = userPlan($db,$noticeUid);
if (($noticePlan['key'] ?? '') !== 'free' || !empty($_SESSION['admin'])) out(['ok'=>true,'eligible'=>false]);
$noticeNow = new DateTimeImmutable();
$noticeQuota = companyContactQuota($db,$noticeUid,$noticePlan,$noticeNow);
$stmt = $db->prepare('SELECT 1 FROM company_notice_receipts WHERE user_id=? AND notice_key=? LIMIT 1');
$stmt->execute([$noticeUid,$noticeVersion]);
$noticeSeen = (bool)$stmt->fetchColumn();
$noticeReset = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',$noticeQuota['resets_at'],$noticeNow->getTimezone());
if (!$noticeReset) throw new RuntimeException('Fecha de renovación no válida.');
out([
    'ok'=>true,'eligible'=>true,'seen'=>$noticeSeen,'csrf'=>csrfToken(),
    'remaining'=>$noticeQuota['remaining'],'limit'=>$noticeQuota['limit'],
    'used'=>$noticeQuota['used'],'month'=>$noticeQuota['month'],
    'resets_at'=>$noticeReset->format(DateTimeInterface::ATOM),
    'resets_label'=>$noticeReset->setTimezone(new DateTimeZone('Europe/Madrid'))->format('d/m/Y H:i'),
]);
