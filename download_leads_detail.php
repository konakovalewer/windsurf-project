<?php
// Детализация по лидам в CSV без записи на диск.
use Bitrix\Main\Loader;
use Bitrix\Main\Application;
use Bitrix\Main\Web\Json;
use Bitrix\Main\Config\Option;

require($_SERVER["DOCUMENT_ROOT"]."/bitrix/modules/main/include/prolog_before.php");

require_once __DIR__ . '/lib/DateConverter.php';
require_once __DIR__ . '/lib/LeadReportService.php';

// Класс-обёртка для доступа к protected-методам LeadReportService.
class LeadDetailService extends LeadReportService
{
    public function computeLead(array $lead, array $statusMap): array
    {
        $leadId = (int)($lead['ID'] ?? 0);
        $durations = [];
        $closureMinutes = null;

        // 1) Основной источник: status history
        $historyEntries = $this->getStatusHistoryEntriesForLead($leadId);
        if (!empty($historyEntries)) {
            $this->fillDurationsFromHistory($lead, $historyEntries, $statusMap, $durations, $closureMinutes);
            return ['durations' => $durations, 'closure' => $closureMinutes, 'source' => 'status_history'];
        }

        // 2) Таймлайн
        $timelineChanges = $this->getTimelineStageChanges($leadId);
        $timelineDurations = $this->calculateDurationsFromTimeline($lead, $statusMap, $timelineChanges);
        if (!empty($timelineDurations)) {
            $durations = $timelineDurations;
            $closureMinutes = $this->getClosureDurationMinutes($lead, $timelineChanges);
            if ($closureMinutes === null) {
                $events = $this->getHistoryEntriesForLead($leadId);
                $closureMinutes = $this->getClosureDurationFromHistory($lead, $events);
            }
            return ['durations' => $durations, 'closure' => $closureMinutes, 'source' => 'timeline'];
        }

        // 3) История событий как fallback
        $eventHistory = $this->getHistoryEntriesForLead($leadId);
        if (!empty($eventHistory)) {
            $this->fillDurationsFromHistory($lead, $eventHistory, $statusMap, $durations, $closureMinutes);
            return ['durations' => $durations, 'closure' => $closureMinutes, 'source' => 'events'];
        }

        return ['durations' => [], 'closure' => null, 'source' => 'none'];
    }

    private function fillDurationsFromHistory(array $lead, array $historyEntries, array $statusMap, array &$durations, ?float &$closureMinutes): void
    {
        usort($historyEntries, function ($a, $b) {
            return strtotime($a['CREATED_TIME']) <=> strtotime($b['CREATED_TIME']);
        });

        $count = count($historyEntries);
        $closureMinutes = null;
        for ($i = 0; $i < $count; $i++) {
            $cur = $historyEntries[$i];
            $startTs = DateConverter::convertDbStringToTimestamp($cur['CREATED_TIME']);
            $endSource = ($i + 1 < $count) ? $historyEntries[$i + 1]['CREATED_TIME'] : (new \Bitrix\Main\Type\DateTime())->toString();
            $endTs = DateConverter::convertDbStringToTimestamp($endSource);
            if ($startTs === null || $endTs === null) {
                continue;
            }
            $minutes = max(0, ($endTs - $startTs) / 60.0);
            $stageCode = $cur['STAGE_ID'];
            if (!isset($statusMap[$stageCode])) {
                continue;
            }
            if (!isset($durations[$stageCode])) {
                $durations[$stageCode] = 0.0;
            }
            $durations[$stageCode] += $minutes;

            if ($closureMinutes === null && $this->isFinalStage($stageCode)) {
                $createTs = DateConverter::convertDbStringToTimestamp($lead['DATE_CREATE'] ?? null);
                if ($createTs !== null) {
                    $closureMinutes = max(0, ($startTs - $createTs) / 60.0);
                }
            }
        }
    }
}

function buildStatusMap(): array
{
    $map = [];
    $res = \CCrmStatus::GetList(['SORT' => 'ASC'], ['ENTITY_ID' => 'STATUS']);
    while ($s = $res->Fetch()) {
        $code = $s['STATUS_ID'];
        $semantic = \CCrmLead::GetSemanticID($code);
        $semUpper = strtoupper((string)$semantic);
        if (in_array($semUpper, ['S','F','SUCCESS','FAILURE'], true)) {
            continue;
        }
        $map[$code] = $s['NAME'];
    }
    return $map;
}

if (!Loader::includeModule('crm')) {
    die('CRM module not available');
}

$request = Application::getInstance()->getContext()->getRequest();
$settingsJson = Option::get('main', 'custom_antirating_settings', '');
$saved = [];
if ($settingsJson !== '') {
    try { $saved = Json::decode($settingsJson); } catch (\Throwable $e) { $saved = []; }
}

$usersRaw = $request->get('SETTINGS_USERS') ?? '';
$managers = [];
foreach (explode(',', (string)$usersRaw) as $id) {
    $id = (int)trim($id);
    if ($id > 0) { $managers[] = $id; }
}
if (empty($managers) && !empty($saved['users']) && is_array($saved['users'])) {
    $managers = array_map('intval', $saved['users']);
}

$dateFromRaw = $request->get('DATE_FROM');
$dateToRaw = $request->get('DATE_TO');
if (empty($dateFromRaw) || empty($dateToRaw)) {
    die('DATE_FROM and DATE_TO are required');
}
$dateFromTs = DateConverter::convertUserDateToTimestamp($dateFromRaw);
$dateToTs = DateConverter::convertUserDateToTimestamp($dateToRaw);
if ($dateFromTs === null || $dateToTs === null) {
    die('Bad dates');
}
$dateFrom = \Bitrix\Main\Type\DateTime::createFromTimestamp($dateFromTs);
$dateTo = \Bitrix\Main\Type\DateTime::createFromTimestamp($dateToTs);

$statusMap = buildStatusMap();
$allStages = array_keys($statusMap);

// Получим имена менеджеров
$managerNames = [];
if (!empty($managers)) {
    $userRes = \Bitrix\Main\UserTable::getList([
        'select' => ['ID','NAME','LAST_NAME'],
        'filter' => ['@ID' => $managers]
    ]);
    while ($u = $userRes->fetch()) {
        $managerNames[(int)$u['ID']] = trim(($u['NAME'] ?? '') . ' ' . ($u['LAST_NAME'] ?? ''));
    }
}

$detailService = new LeadDetailService(new DateConverter());

$rows = [];
foreach ($managers as $managerId) {
    $leads = $detailService->getLeadsByManager($managerId, $dateFrom, $dateTo);
    foreach ($leads as $leadId => $lead) {
        $calc = $detailService->computeLead($lead, $statusMap);
        $row = [
            'LEAD_ID' => $leadId,
            'RESPONSIBLE' => $managerNames[$managerId] ?? ('ID ' . $managerId),
            'CLOSURE_DAYS' => ($calc['closure'] !== null) ? round($calc['closure']/480, 4) : '',
        ];
        foreach ($allStages as $stCode) {
            $minutes = $calc['durations'][$stCode] ?? null;
            $count = ($minutes !== null && $minutes > 0) ? 1 : 0;
            $row['COUNT_'.$stCode] = $count;
            $row['TIME_'.$stCode] = $minutes !== null ? round($minutes/480, 4) : '';
        }
        $rows[] = $row;
    }
}

// Формируем CSV
$headers = ['LEAD_ID','RESPONSIBLE','CLOSURE_DAYS'];
foreach ($allStages as $stCode) {
    $headers[] = 'COUNT_'.$stCode;
    $headers[] = 'TIME_'.$stCode;
}

header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="leads_detail.csv"');

$output = fopen('php://output', 'w');
fputcsv($output, $headers, ';');
foreach ($rows as $r) {
    $line = [];
    foreach ($headers as $h) {
        $line[] = $r[$h] ?? '';
    }
    fputcsv($output, $line, ';');
}
fclose($output);
exit;
