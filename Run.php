<?php
// Анализ источников смен этапов по лидам за январь 2025.
// Лог пишем в /local/components/custom/antirating/log0212.php: формат "LEAD_ID;Y/N;source".

require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php';

use Bitrix\Main\Loader;
use Bitrix\Main\Web\Json;
use Bitrix\Main\Application;

if (!Loader::includeModule('crm')) {
    die("CRM module not available\n");
}

// Период: 01.01.2025 00:00:00 - 31.01.2025 23:59:59
$dateFrom = \Bitrix\Main\Type\DateTime::createFromPhp(new \DateTime('2025-01-01 00:00:00'));
$dateTo = \Bitrix\Main\Type\DateTime::createFromPhp(new \DateTime('2025-01-31 23:59:59'));

$logFile = $_SERVER['DOCUMENT_ROOT'] . '/local/components/custom/antirating/log0212.php';
$logPrefix = '[' . date('c') . '] ';

function convertToTimestamp($value): ?int
{
    if ($value instanceof \Bitrix\Main\Type\DateTime) {
        return $value->getTimestamp();
    }
    if ($value instanceof \DateTime) {
        return $value->getTimestamp();
    }
    if (is_string($value) && trim($value) !== '') {
        try {
            $bitrixDate = \Bitrix\Main\Type\DateTime::createFromUserTime($value);
            if ($bitrixDate instanceof \Bitrix\Main\Type\DateTime) {
                return $bitrixDate->getTimestamp();
            }
        } catch (\Throwable $e) {
        }
        try {
            return (new \DateTime($value, new \DateTimeZone(date_default_timezone_get())))->getTimestamp();
        } catch (\Exception $e) {
            return null;
        }
    }
    return null;
}

function findInTimeline(int $leadId): bool
{
    $bindingClass = null;
    if (class_exists('\\Bitrix\\Crm\\Timeline\\Entity\\TimelineBindingTable')) {
        $bindingClass = '\\Bitrix\\Crm\\Timeline\\Entity\\TimelineBindingTable';
    } elseif (class_exists('\\Bitrix\\Crm\\Timeline\\Entity\\Timeline\\TimelineBindingTable')) {
        $bindingClass = '\\Bitrix\\Crm\\Timeline\\Entity\\Timeline\\TimelineBindingTable';
    }

    $tableClass = null;
    if (class_exists('\\Bitrix\\Crm\\Timeline\\Entity\\TimelineTable')) {
        $tableClass = '\\Bitrix\\Crm\\Timeline\\Entity\\TimelineTable';
    } elseif (class_exists('\\Bitrix\\Crm\\TimelineTable')) {
        $tableClass = '\\Bitrix\\Crm\\TimelineTable';
    }

    if (!$bindingClass || !$tableClass) {
        return false;
    }

    $timelineIds = [];
    $bindings = $bindingClass::getList([
        'filter' => [
            '=ENTITY_TYPE_ID' => \CCrmOwnerType::Lead,
            '=ENTITY_ID' => $leadId
        ],
        'select' => ['OWNER_ID']
    ]);
    while ($bind = $bindings->fetch()) {
        $timelineIds[] = (int)$bind['OWNER_ID'];
    }
    if (empty($timelineIds)) {
        return false;
    }

    $timelineRows = $tableClass::getList([
        'filter' => ['@ID' => $timelineIds],
        'order' => ['CREATED' => 'ASC'],
        'select' => ['ID','SETTINGS']
    ]);

    while ($row = $timelineRows->fetch()) {
        $settings = $row['SETTINGS'];
        if (is_string($settings)) {
            try {
                $settings = Json::decode($settings);
            } catch (\Throwable $e) {
                $settings = null;
            }
        }
        if (!is_array($settings) || ($settings['FIELD'] ?? null) !== 'STATUS_ID') {
            continue;
        }
        return true; // нашли смену статуса
    }

    return false;
}

function findInStatusHistory(int $leadId): bool
{
    try {
        $connection = Application::getConnection();
        $sql = "SELECT ID FROM b_crm_lead_status_history WHERE OWNER_ID = " . (int)$leadId . " LIMIT 1";
        $row = $connection->query($sql)->fetch();
        return $row ? true : false;
    } catch (\Throwable $e) {
        return false;
    }
}

function findInHistoryEntry(int $leadId): bool
{
    if (!class_exists('\Bitrix\Crm\History\HistoryEntry')) {
        return false;
    }

    try {
        $it = \Bitrix\Crm\History\HistoryEntry::getList([
            'filter' => [
                '=ENTITY_TYPE_ID' => \CCrmOwnerType::Lead,
                '=ENTITY_ID' => $leadId
            ],
            'order' => ['CREATED_TIME' => 'ASC'],
            'select' => ['ID','STAGE_ID','STAGE_SEMANTIC_ID']
        ]);

        while ($row = $it->fetch()) {
            $stageId = $row['STAGE_ID'] ?: $row['STAGE_SEMANTIC_ID'] ?: null;
            if ($stageId) {
                return true;
            }
        }
    } catch (\Throwable $e) {
    }

    return false;
}

function findInEvents(int $leadId): bool
{
    if (!class_exists('CCrmEvent')) {
        return false;
    }
    try {
        $ev = \CCrmEvent::GetList(
            ['DATE_CREATE' => 'ASC'],
            ['ENTITY_TYPE' => 'LEAD', 'ENTITY_ID' => $leadId],
            false,
            false,
            ['ID','EVENT_TEXT_2']
        );
        while ($row = $ev->Fetch()) {
            if (!empty($row['EVENT_TEXT_2'])) {
                return true;
            }
        }
    } catch (\Throwable $e) {
    }
    return false;
}

$filter = [
    '>=DATE_CREATE' => $dateFrom,
    '<=DATE_CREATE' => $dateTo,
    'CHECK_PERMISSIONS' => 'N'
];

$res = \CCrmLead::GetListEx(['ID' => 'ASC'], $filter, false, false, ['ID']);
$count = 0;
$foundStatusHistory = $foundTimeline = $foundHistory = $foundEvent = $foundNone = 0;

while ($lead = $res->Fetch()) {
    $leadId = (int)$lead['ID'];
    $source = 'none';
    $found = 'N';

    if (findInStatusHistory($leadId)) {
        $found = 'Y';
        $source = 'status_history';
        $foundStatusHistory++;
    } elseif (findInTimeline($leadId)) {
        $found = 'Y';
        $source = 'timeline';
        $foundTimeline++;
    } elseif (findInHistoryEntry($leadId)) {
        $found = 'Y';
        $source = 'history';
        $foundHistory++;
    } elseif (findInEvents($leadId)) {
        $found = 'Y';
        $source = 'event';
        $foundEvent++;
    } else {
        $foundNone++;
    }

    $line = $leadId . ';' . $found . ';' . $source . PHP_EOL;
    file_put_contents($logFile, $logPrefix . $line, FILE_APPEND | LOCK_EX);

    $count++;
    if ($count % 500 === 0) {
        echo "Processed {$count} leads...\n";
    }
}

echo "Done. Leads: {$count}, status_history: {$foundStatusHistory}, timeline: {$foundTimeline}, history: {$foundHistory}, event: {$foundEvent}, none: {$foundNone}\n";
