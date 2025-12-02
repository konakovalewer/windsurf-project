<?php
// Print hours spent in stage NEW for the given lead IDs
require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php';

use Bitrix\Main\Loader;
use Bitrix\Main\Web\Json;

if (!Loader::includeModule('crm')) {
    die("CRM module not available\n");
}

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

function getTimelineStageChanges(int $leadId): array
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
        return [];
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
        return [];
    }

    $changes = [];
    $timelineRows = $tableClass::getList([
        'filter' => ['@ID' => $timelineIds],
        'order' => ['CREATED' => 'ASC'],
        'select' => ['ID','CREATED','SETTINGS']
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
        $changes[] = [
            'TIME' => $row['CREATED'],
            'FROM' => $settings['START'] ?? null,
            'TO' => $settings['FINISH'] ?? null
        ];
    }

    return $changes;
}

function getHistoryEntriesForLead($leadId): array
{
    $entries = [];

    if (!Loader::includeModule('crm')) {
        return $entries;
    }

    if (class_exists('\Bitrix\Crm\History\HistoryEntry')) {
        try {
            $it = \Bitrix\Crm\History\HistoryEntry::getList([
                'filter' => [
                    '=ENTITY_TYPE_ID' => \CCrmOwnerType::Lead,
                    '=ENTITY_ID' => $leadId
                ],
                'order' => ['CREATED_TIME' => 'ASC'],
                'select' => ['ID','CREATED_TIME','STAGE_ID','STAGE_SEMANTIC_ID']
            ]);

            while ($row = $it->fetch()) {
                $stageId = $row['STAGE_ID'] ?: $row['STAGE_SEMANTIC_ID'] ?: null;
                if ($stageId) {
                    $entries[] = [
                        'STAGE_ID' => $stageId,
                        'CREATED_TIME' => $row['CREATED_TIME']
                    ];
                }
            }
        } catch (\Exception $e) {
        }
    }

    if (empty($entries) && class_exists('CCrmEvent')) {
        try {
            $ev = \CCrmEvent::GetList(
                ['DATE_CREATE' => 'ASC'],
                ['ENTITY_TYPE' => 'LEAD', 'ENTITY_ID' => $leadId]
            );
            while ($row = $ev->Fetch()) {
                $stageCode = null;
                if (!empty($row['EVENT_TEXT_2'])) {
                    $stageCode = trim($row['EVENT_TEXT_2']);
                }
                if ($stageCode) {
                    $entries[] = [
                        'STAGE_ID' => $stageCode,
                        'CREATED_TIME' => $row['DATE_CREATE']
                    ];
                }
            }
        } catch (\Exception $e) {
        }
    }

    return $entries;
}

function calculateDurationsFromTimeline(array $lead, array $changes): array
{
    if (empty($changes)) {
        return [];
    }

    usort($changes, function ($a, $b) {
        $aTs = convertToTimestamp($a['TIME']);
        $bTs = convertToTimestamp($b['TIME']);
        return $aTs <=> $bTs;
    });

    $startTs = convertToTimestamp($lead['DATE_CREATE'] ?? null);
    if ($startTs === null) {
        $startTs = convertToTimestamp($changes[0]['TIME']);
    }

    $currentStage = $changes[0]['FROM'] ?? ($lead['STATUS_ID'] ?? null);
    $durations = [];

    foreach ($changes as $change) {
        $changeTs = convertToTimestamp($change['TIME']);
        if ($changeTs === null) {
            continue;
        }

        if ($currentStage && $startTs !== null) {
            $minutes = max(0, ($changeTs - $startTs) / 60.0);
            if (!isset($durations[$currentStage])) {
                $durations[$currentStage] = 0.0;
            }
            $durations[$currentStage] += $minutes;
        }

        $currentStage = $change['TO'] ?: $currentStage;
        $startTs = $changeTs;
    }

    if ($currentStage && $startTs !== null) {
        $nowTs = time();
        $minutes = max(0, ($nowTs - $startTs) / 60.0);
        if (!isset($durations[$currentStage])) {
            $durations[$currentStage] = 0.0;
        }
        $durations[$currentStage] += $minutes;
    }

    return $durations;
}

function calculateDurationsFromHistory(array $lead, array $historyEntries): array
{
    if (empty($historyEntries)) {
        return [];
    }

    usort($historyEntries, function ($a, $b) {
        return strtotime($a['CREATED_TIME']) <=> strtotime($b['CREATED_TIME']);
    });

    $durations = [];
    $count = count($historyEntries);
    for ($i = 0; $i < $count; $i++) {
        $cur = $historyEntries[$i];
        $startTs = convertToTimestamp($cur['CREATED_TIME']);
        $endSource = ($i + 1 < $count) ? $historyEntries[$i + 1]['CREATED_TIME'] : (new \Bitrix\Main\Type\DateTime())->toString();
        $endTs = convertToTimestamp($endSource);

        if ($startTs === null || $endTs === null) {
            continue;
        }

        $minutes = max(0, ($endTs - $startTs) / 60.0);
        $stageCode = $cur['STAGE_ID'];
        if (!isset($durations[$stageCode])) {
            $durations[$stageCode] = 0.0;
        }
        $durations[$stageCode] += $minutes;
    }

    return $durations;
}

$leadIds = [
    41660,41981,41880,41869,41801,41851,41833,41815,41901,41861,41799,41977,41900,
    41832,41679,41680,41681,41826,41806,41841,41814,41907,41909,41864,41865,41838,
    41920,41835,41862,41868,41817,41905,41906,41924,41866,41867,41894,42053,41736,
    41730,41731,41575,41910,42091
];

foreach ($leadIds as $leadId) {
    $lead = \CCrmLead::GetByID($leadId, true);
    if (!$lead) {
        echo $leadId . ";-\n";
        continue;
    }

    $timelineChanges = getTimelineStageChanges($leadId);
    $durations = calculateDurationsFromTimeline($lead, $timelineChanges);

    if (empty($durations)) {
        $historyEntries = getHistoryEntriesForLead($leadId);
        $durations = calculateDurationsFromHistory($lead, $historyEntries);
    }

    $minutesInNew = $durations['NEW'] ?? 0.0;
    $hoursInNew = $minutesInNew / 60.0;

    echo $leadId . ";" . round($hoursInNew, 4) . "\n";
}
