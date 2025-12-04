<?php
// Р ВР В·Р СР ВµР Р…Р ВµР Р…Р С‘Р Вµ: 2025-01-05 23:001Р РЋР В»Р С•Р Р…РЎРЏРЎР‚Р В°1
use Bitrix\Main\Loader;
use Bitrix\Main\Web\Json;
use Bitrix\Main\Application;
use Bitrix\Main\Data\Cache;
use Bitrix\Main\Config\Option;
use Bitrix\Crm\PhaseSemantics;

if (!defined("B_PROLOG_INCLUDED") || B_PROLOG_INCLUDED !== true) die();

class AntiratingReport extends CBitrixComponent
{
    protected function getAllStatusesMap()
    {
        $allowedStages = $this->getProcessStageCodes();
        $map = [];
        $res = \CCrmStatus::GetList(['SORT' => 'ASC'], ['ENTITY_ID' => 'STATUS']);
        while ($s = $res->Fetch()) {
            $code = $s['STATUS_ID'];
            if (!empty($allowedStages) && !in_array($code, $allowedStages, true)) {
                continue;
            }
            // Р вЂќР С•Р С—Р С•Р В»Р Р…Р С‘РЎвЂљР ВµР В»РЎРЉР Р…Р С• Р С•РЎвЂљРЎРѓР ВµРЎвЂЎРЎвЂР С РЎС“РЎРѓР С—Р ВµРЎвЂ¦/Р С—РЎР‚Р С•Р Р†Р В°Р В» Р С—Р С• РЎРѓР ВµР СР В°Р Р…РЎвЂљР С‘Р С”Р Вµ
            $semantic = $this->getStageSemantic($code);
            if ($semantic === null || $semantic === '') {
                $semantic = \CCrmLead::GetSemanticID($code);
            }
            $semUpper = strtoupper((string)$semantic);
            if (in_array($semUpper, ['S', 'F', 'SUCCESS', 'FAILURE'], true)) {
                continue;
            }
            $map[$code] = $s['NAME'];
        }
        return $map;
    }

    protected function getProcessStageCodes(): array
    {
        static $codes = null;
        if ($codes !== null) {
            return $codes;
        }

        $codes = [];
        $entityTypes = \CCrmStatus::GetEntityTypes();
        $processStages = $entityTypes['STATUS']['PROCESS'] ?? [];
        if (!empty($processStages) && is_array($processStages)) {
            $codes = array_keys($processStages);
        }

        return $codes;
    }

    protected function getStatusesInfo(): array
    {
        static $info = null;
        if ($info !== null) {
            return $info;
        }

        $info = [];
        $res = \CCrmStatus::GetList(['SORT' => 'ASC'], ['ENTITY_ID' => 'STATUS']);
        while ($s = $res->Fetch()) {
            $info[$s['STATUS_ID']] = $s;
        }

        return $info;
    }

    protected function getStageSemantic(string $stageCode): ?string
    {
        $info = $this->getStatusesInfo();
        if (!isset($info[$stageCode])) {
            return null;
        }

        return $info[$stageCode]['STATUS_SEMANTIC_ID'] ?? ($info[$stageCode]['SEMANTIC_ID'] ?? null);
    }

    protected function isFinalStage(string $stageCode): bool
    {
        $semantic = $this->getStageSemantic($stageCode);
        if (!$semantic || !is_string($semantic)) {
            $semantic = \CCrmLead::GetSemanticID($stageCode);
        }

        if ($semantic) {
            $semanticUpper = strtoupper((string)$semantic);
            if (in_array($semanticUpper, ['SUCCESS', 'FAILURE', 'S', 'F'], true)) {
                return true;
            }
            return PhaseSemantics::isFinal($semanticUpper);
        }

        // Fallback: detect typical РЎвЂћР С‘Р Р…Р В°Р В»РЎРЉР Р…РЎвЂ№Р Вµ Р С”Р С•Р Т‘РЎвЂ№, Р Т‘Р В°Р В¶Р Вµ Р ВµРЎРѓР В»Р С‘ Р Р† РЎРѓРЎвЂљР В°РЎвЂљРЎС“РЎРѓР Вµ Р Р…Р ВµРЎвЂљ РЎРѓР ВµР СР В°Р Р…РЎвЂљР С‘Р С”Р С‘
        $plainCode = strtoupper(strpos($stageCode, ':') !== false ? substr($stageCode, strrpos($stageCode, ':') + 1) : $stageCode);
        $finalCodes = ['CONVERTED', 'JUNK', 'WON', 'LOST', 'LOSE', 'FAILED', 'S', 'F'];
        return in_array($plainCode, $finalCodes, true);
    }

    protected function getLeadsByManager($managerId, ?\Bitrix\Main\Type\DateTime $dateFrom = null, ?\Bitrix\Main\Type\DateTime $dateTo = null)
    {
        $result = [];

        if ($managerId <= 0) {
            return $result;
        }

        $filter = [
            'ASSIGNED_BY_ID' => $managerId,
            'CHECK_PERMISSIONS' => 'N'
        ];

        if ($dateFrom instanceof \Bitrix\Main\Type\DateTime) {
            $filter['>=DATE_CREATE'] = $dateFrom;
        }
        if ($dateTo instanceof \Bitrix\Main\Type\DateTime) {
            $filter['<=DATE_CREATE'] = $dateTo;
        }

        $cacheTtl = 300;
        $cacheId = 'leads_' . md5($managerId . '|' . ($dateFrom ? $dateFrom->toString() : '') . '|' . ($dateTo ? $dateTo->toString() : ''));
        $cacheDir = '/custom/antirating/leads';
        $cache = Cache::createInstance();

        if ($cache->initCache($cacheTtl, $cacheId, $cacheDir)) {
            $cached = $cache->getVars();
            if (is_array($cached)) {
                return $cached;
            }
        }

        $res = \CCrmLead::GetListEx(
            ['ID' => 'ASC'],
            $filter,
            false,
            false,
            ['ID', 'DATE_CREATE', 'STATUS_ID']
        );

        if ($res && is_object($res)) {
            while ($l = $res->Fetch()) {
                $leadId = (int)$l['ID'];
                $result[$leadId] = $l;
            }
        }

        if ($cache->startDataCache()) {
            $cache->endDataCache($result);
        }

        return $result;
    }
protected function getHistoryEntriesForLead($leadId)
        {
            $entries = [];

            if (!Loader::includeModule('crm')) {
                return $entries;
            }

            // Р ВРЎРѓР С—Р С•Р В»РЎРЉР В·РЎС“Р ВµР С РЎвЂљР С•Р В»РЎРЉР С”Р С• РЎРѓР С•Р В±РЎвЂ№РЎвЂљР С‘РЎРЏ Р С”Р В°Р С” РЎР‚Р ВµР В·Р ВµРЎР‚Р Р†Р Р…РЎвЂ№Р в„– Р С‘РЎРѓРЎвЂљР С•РЎвЂЎР Р…Р С‘Р С”
            if (class_exists('CCrmEvent')) {
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
                    // Р С‘Р С–Р Р…Р С•РЎР‚Р С‘РЎР‚РЎС“Р ВµР С
                }
            }

            return $entries;
        }

        protected function getStatusHistoryEntriesForLead(int $leadId): array
        {
            $rows = [];
            try {
                $conn = \Bitrix\Main\Application::getConnection();
                $sql = "SELECT STATUS_ID, DATE_CREATE FROM b_crm_lead_status_history WHERE OWNER_ID = " . (int)$leadId . " ORDER BY DATE_CREATE ASC";
                $res = $conn->query($sql);
                while ($row = $res->fetch()) {
                    if (empty($row['STATUS_ID'])) {
                        continue;
                    }
                    $rows[] = [
                        'STAGE_ID' => $row['STATUS_ID'],
                        'CREATED_TIME' => $row['DATE_CREATE']
                    ];
                }
            } catch (\Throwable $e) {
                // ignore errors, fallback to other sources
            }
            return $rows;
        }

        protected function getTimelineStageChanges(int $leadId): array
        {
            if (!class_exists('\\Bitrix\\Crm\\Timeline\\Entity\\TimelineBindingTable')
                || !class_exists('\\Bitrix\\Crm\\Timeline\\Entity\\TimelineTable')) {
                return [];
            }

            $timelineIds = [];
            $bindings = \Bitrix\Crm\Timeline\Entity\TimelineBindingTable::getList([
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
            $timelineRows = \Bitrix\Crm\Timeline\Entity\TimelineTable::getList([
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

        protected function calculateDurationsFromTimeline(array $lead, array $statusMap, array $changes): array
        {
            if (empty($changes)) {
                return [];
            }

            usort($changes, function ($a, $b) {
                $aTs = $this->convertBitrixDateToTimestamp($a['TIME']) ?? $this->convertDbStringToTimestamp($a['TIME']);
                $bTs = $this->convertBitrixDateToTimestamp($b['TIME']) ?? $this->convertDbStringToTimestamp($b['TIME']);
                return $aTs <=> $bTs;
            });

            $startTs = $this->convertDbStringToTimestamp($lead['DATE_CREATE'] ?? null);
            if ($startTs === null) {
                $startTs = $this->convertBitrixDateToTimestamp($changes[0]['TIME']) ?? $this->convertDbStringToTimestamp($changes[0]['TIME']);
            }

            $currentStage = $changes[0]['FROM'] ?? ($lead['STATUS_ID'] ?? null);
            $durations = [];

            foreach ($changes as $change) {
                $changeTs = $this->convertBitrixDateToTimestamp($change['TIME']) ?? $this->convertDbStringToTimestamp($change['TIME']);
                if ($changeTs === null) {
                    continue;
                }

                if ($currentStage && isset($statusMap[$currentStage]) && $startTs !== null) {
                    $minutes = max(0, ($changeTs - $startTs) / 60.0);
                    if (!isset($durations[$currentStage])) {
                        $durations[$currentStage] = 0.0;
                    }
                    $durations[$currentStage] += $minutes;
                }

                $currentStage = $change['TO'] ?: $currentStage;
                $startTs = $changeTs;
            }

            if ($currentStage && isset($statusMap[$currentStage]) && $startTs !== null) {
                $nowTs = time();
                $minutes = max(0, ($nowTs - $startTs) / 60.0);
                if (!isset($durations[$currentStage])) {
                    $durations[$currentStage] = 0.0;
                }
                $durations[$currentStage] += $minutes;
            }

            return $durations;
        }

        protected function getClosureDurationMinutes(array $lead, array $timelineChanges): ?float
        {
            if (empty($timelineChanges)) {
                return null;
            }

            $createTs = $this->convertDbStringToTimestamp($lead['DATE_CREATE'] ?? null);
            if ($createTs === null) {
                return null;
            }

            usort($timelineChanges, function ($a, $b) {
                $aTs = $this->convertBitrixDateToTimestamp($a['TIME']) ?? $this->convertDbStringToTimestamp($a['TIME']);
                $bTs = $this->convertBitrixDateToTimestamp($b['TIME']) ?? $this->convertDbStringToTimestamp($b['TIME']);
                return $aTs <=> $bTs;
            });

            foreach ($timelineChanges as $change) {
                $toStage = $change['TO'] ?? null;
                if (!$toStage || !$this->isFinalStage($toStage)) {
                    continue;
                }

                $closureTs = $this->convertBitrixDateToTimestamp($change['TIME']) ?? $this->convertDbStringToTimestamp($change['TIME']);
                if ($closureTs === null) {
                    continue;
                }

                return max(0, ($closureTs - $createTs) / 60.0);
            }

            return null;
        }

        protected function getClosureDurationFromHistory(array $lead, array $historyEntries): ?float
        {
            if (empty($historyEntries)) {
                return null;
            }

            usort($historyEntries, function ($a, $b) {
                return strtotime($a['CREATED_TIME']) <=> strtotime($b['CREATED_TIME']);
            });

            $createTs = $this->convertDbStringToTimestamp($lead['DATE_CREATE'] ?? null);
            if ($createTs === null) {
                return null;
            }

            foreach ($historyEntries as $entry) {
                $stageCode = $entry['STAGE_ID'] ?? null;
                if (!$stageCode || !$this->isFinalStage($stageCode)) {
                    continue;
                }

                $stageTs = $this->convertDbStringToTimestamp($entry['CREATED_TIME'] ?? null);
                if ($stageTs === null) {
                    continue;
                }

                return max(0, ($stageTs - $createTs) / 60.0);
            }

            return null;
        }

        protected function convertDbStringToTimestamp(?string $value): ?int
        {
            if (!is_string($value) || trim($value) === '') {
                return null;
            }
            try {
                return (new \DateTime($value, new \DateTimeZone(date_default_timezone_get())))->getTimestamp();
            } catch (\Exception $e) {
                return null;
            }
        }

        protected function convertBitrixDateToTimestamp($value): ?int
        {
            if ($value instanceof \Bitrix\Main\Type\DateTime) {
                return $value->getTimestamp();
            }

            if ($value instanceof \DateTime) {
                return $value->getTimestamp();
            }

            return null;
        }

        protected function convertUserDateToTimestamp(?string $value): ?int
        {
            if (!is_string($value) || trim($value) === '') {
                return null;
            }
            try {
                $dt = \Bitrix\Main\Type\DateTime::createFromUserTime($value);
                return $dt instanceof \Bitrix\Main\Type\DateTime ? $dt->getTimestamp() : null;
            } catch (\Throwable $e) {
                return null;
            }
        }

        protected function parseDateParam($value): ?\Bitrix\Main\Type\DateTime
        {
            if (empty($value) || !is_string($value)) {
                return null;
            }

            $ts = $this->convertUserDateToTimestamp($value);
            if ($ts !== null) {
                return \Bitrix\Main\Type\DateTime::createFromTimestamp($ts);
            }

            try {
                $phpDate = new \DateTime($value);
                return \Bitrix\Main\Type\DateTime::createFromPhp($phpDate);
            } catch (\Exception $e) {
                return null;
            }
        }

        protected function buildLeadsReport(array $managersToProcess, array $managerNameMap, ?\Bitrix\Main\Type\DateTime $dateFrom, ?\Bitrix\Main\Type\DateTime $dateTo, array $statusMap, array $allStages): array
        {
            $data = [];
            foreach ($managersToProcess as $managerIdItem) {
                $managerNameItem = $managerNameMap[$managerIdItem] ?? ('ID ' . $managerIdItem);
                $data[$managerNameItem] = [];
                foreach ($allStages as $stCode) {
                    $data[$managerNameItem][$stCode] = ['COUNT' => 0, 'TIME' => 0.0];
                }
            }

            $closureStats = [];
            $leadTotals = [];

            foreach ($managersToProcess as $managerIdItem) {
                $managerName = $managerNameMap[$managerIdItem] ?? ('ID ' . $managerIdItem);
                $leadRows = $this->getLeadsByManager($managerIdItem, $dateFrom, $dateTo);
                $leadTotals[$managerName] = count($leadRows);
                if (empty($leadRows)) {
                    continue;
                }

                foreach ($leadRows as $leadId => $lead) {
                    if (empty($lead)) {
                        continue;
                    }

                    $countedStages = [];
                    $statusHistoryEntries = $this->getStatusHistoryEntriesForLead($leadId);
                    $usedTimeline = false;

                    if (!empty($statusHistoryEntries)) {
                        $historyEntries = $statusHistoryEntries;
                    } else {
                        $timelineChanges = $this->getTimelineStageChanges($leadId);
                        $timelineDurations = $this->calculateDurationsFromTimeline($lead, $statusMap, $timelineChanges);
                        if (!empty($timelineDurations)) {
                            $usedTimeline = true;
                            foreach ($timelineDurations as $stageCode => $minutes) {
                                if (!isset($data[$managerName][$stageCode])) {
                                    $data[$managerName][$stageCode] = ['COUNT' => 0, 'TIME' => 0.0];
                                }
                                $data[$managerName][$stageCode]['TIME'] += $minutes;

                                if (!isset($countedStages[$stageCode])) {
                                    $data[$managerName][$stageCode]['COUNT'] += 1;
                                    $countedStages[$stageCode] = true;
                                }
                            }

                            $closureDuration = $this->getClosureDurationMinutes($lead, $timelineChanges);
                            if ($closureDuration !== null) {
                                if (!isset($closureStats[$managerName])) {
                                    $closureStats[$managerName] = ['SUM' => 0.0, 'COUNT' => 0];
                                }
                                $closureStats[$managerName]['SUM'] += $closureDuration;
                                $closureStats[$managerName]['COUNT'] += 1;
                            }

                            if ($closureDuration === null) {
                                $eventEntries = $this->getHistoryEntriesForLead($leadId);
                                $closureDuration = $this->getClosureDurationFromHistory($lead, $eventEntries);
                                if ($closureDuration !== null) {
                                    if (!isset($closureStats[$managerName])) {
                                        $closureStats[$managerName] = ['SUM' => 0.0, 'COUNT' => 0];
                                    }
                                    $closureStats[$managerName]['SUM'] += $closureDuration;
                                    $closureStats[$managerName]['COUNT'] += 1;
                                }
                            }
                        } else {
                            $historyEntries = $this->getHistoryEntriesForLead($leadId);
                        }
                    }

                    if ($usedTimeline) {
                        continue;
                    }

                    if (empty($historyEntries)) {
                        continue;
                    }

                    usort($historyEntries, function ($a, $b) {
                        return strtotime($a['CREATED_TIME']) <=> strtotime($b['CREATED_TIME']);
                    });

                    $count = count($historyEntries);
                    $finalClosureMinutes = null;
                    for ($i = 0; $i < $count; $i++) {
                        $cur = $historyEntries[$i];
                        $startTs = $this->convertDbStringToTimestamp($cur['CREATED_TIME']);
                        $endSource = ($i + 1 < $count) ? $historyEntries[$i + 1]['CREATED_TIME'] : (new \Bitrix\Main\Type\DateTime())->toString();
                        $endTs = $this->convertDbStringToTimestamp($endSource);

                        if ($startTs === null || $endTs === null) {
                            continue;
                        }

                        $minutes = max(0, ($endTs - $startTs) / 60.0);

                        $stageCode = $cur['STAGE_ID'];
                        if (!isset($data[$managerName][$stageCode])) {
                            $data[$managerName][$stageCode] = ['COUNT' => 0, 'TIME' => 0.0];
                        }

                        $data[$managerName][$stageCode]['TIME'] += $minutes;

                        if (!isset($countedStages[$stageCode])) {
                            $data[$managerName][$stageCode]['COUNT'] += 1;
                            $countedStages[$stageCode] = true;
                        }

                        if ($finalClosureMinutes === null && $this->isFinalStage($stageCode)) {
                            $createTs = $this->convertDbStringToTimestamp($lead['DATE_CREATE'] ?? null);
                            if ($createTs !== null) {
                                $finalClosureMinutes = max(0, ($startTs - $createTs) / 60.0);
                            }
                        }
                    }

                    if ($finalClosureMinutes !== null) {
                        if (!isset($closureStats[$managerName])) {
                            $closureStats[$managerName] = ['SUM' => 0.0, 'COUNT' => 0];
                        }
                        $closureStats[$managerName]['SUM'] += $finalClosureMinutes;
                        $closureStats[$managerName]['COUNT'] += 1;
                    }
                }
            }

            $defaultNormDays = 5.0;
            $normativeDaysByStage = [
                'NEW' => 1.0
            ];

            $stageAverages = [];
            foreach ($allStages as $stCode) {
                foreach ($data as $managerName => $stagesData) {
                    $countVal = isset($stagesData[$stCode]['COUNT']) ? (int)$stagesData[$stCode]['COUNT'] : 0;
                    $timeVal = $stagesData[$stCode]['TIME'] ?? null;
                    $avgDaysStage = ($countVal > 0 && $timeVal !== null) ? ($timeVal / $countVal) / 1440 : null;
                    $stageAverages[$stCode][$managerName] = $avgDaysStage;
                }
            }

            $closureAverages = [];
            foreach ($data as $managerName => $_) {
                $closure = $closureStats[$managerName] ?? null;
                if ($closure && ($closure['COUNT'] ?? 0) > 0) {
                    $closureAverages[$managerName] = ($closure['SUM'] / max(1, $closure['COUNT'])) / 1440;
                } else {
                    $closureAverages[$managerName] = null;
                }
            }

            $scores = [];
            foreach ($stageAverages as $stageCode => $values) {
                $norm = $normativeDaysByStage[strtoupper($stageCode)] ?? $defaultNormDays;
                $scores[$stageCode] = $this->calculateScoresByNorm($values, $norm);
            }
            $scores['CLOSURE'] = $this->calculateScoresByNorm($closureAverages, $defaultNormDays);

            $leadScoreTotals = [];
            foreach (array_keys($data) as $managerName) {
                $sumScore = 0;
                if (isset($scores['CLOSURE'][$managerName])) {
                    $sumScore += (int)$scores['CLOSURE'][$managerName];
                }
                foreach ($allStages as $stCode) {
                    if (isset($scores[$stCode][$managerName])) {
                        $sumScore += (int)$scores[$stCode][$managerName];
                    }
                }
                $leadScoreTotals[$managerName] = $sumScore;
            }

            return [
                'data' => $data,
                'closureStats' => $closureStats,
                'scores' => $scores,
                'leadTotals' => $leadTotals,
                'leadScoreTotals' => $leadScoreTotals
            ];
        }

        protected function buildContactsReport(array $managersToProcess, array $managerNameMap, ?\Bitrix\Main\Type\DateTime $dateFrom, ?\Bitrix\Main\Type\DateTime $dateTo): array
        {
            $contactsData = [];
            foreach ($managersToProcess as $managerIdItem) {
                $managerName = $managerNameMap[$managerIdItem] ?? ('ID ' . $managerIdItem);
                $contactsData[$managerName] = [
                    'TOTAL' => 0,
                    'INCOMPLETE' => 0,
                    'PERCENT' => null
                ];

                $contactFilter = [
                    'ASSIGNED_BY_ID' => $managerIdItem,
                    'CHECK_PERMISSIONS' => 'N'
                ];
                if ($dateFrom instanceof \Bitrix\Main\Type\DateTime) {
                    $contactFilter['>=DATE_CREATE'] = $dateFrom;
                }
                if ($dateTo instanceof \Bitrix\Main\Type\DateTime) {
                    $contactFilter['<=DATE_CREATE'] = $dateTo;
                }

                $contactCacheTtl = 300;
                $contactCacheId = 'contacts_' . md5($managerIdItem . '|' . ($dateFrom ? $dateFrom->toString() : '') . '|' . ($dateTo ? $dateTo->toString() : ''));
                $contactCacheDir = '/custom/antirating/contacts';
                $contactCache = Cache::createInstance();

                $cachedContacts = null;
                if ($contactCache->initCache($contactCacheTtl, $contactCacheId, $contactCacheDir)) {
                    $cachedContacts = $contactCache->getVars();
                }

                if (is_array($cachedContacts)) {
                    $contactsData[$managerName] = $cachedContacts;
                } else {
                    $contactRes = \CCrmContact::GetListEx(
                        ['ID' => 'ASC'],
                        $contactFilter,
                        false,
                        false,
                        ['ID', 'POST', 'UF_CRM_5D4832E6850FC']
                    );

                    while ($c = $contactRes->Fetch()) {
                        $contactsData[$managerName]['TOTAL'] += 1;
                        $interest = $c['UF_CRM_5D4832E6850FC'] ?? null;
                        $post = $c['POST'] ?? '';
                        $isEmptyInterest = is_array($interest) ? empty(array_filter($interest)) : (trim((string)$interest) === '');
                        $isEmptyPost = trim((string)$post) === '';
                        if ($isEmptyInterest || $isEmptyPost) {
                            $contactsData[$managerName]['INCOMPLETE'] += 1;
                        }
                    }

                    if ($contactsData[$managerName]['TOTAL'] > 0) {
                        $contactsData[$managerName]['PERCENT'] = ($contactsData[$managerName]['INCOMPLETE'] / max(1, $contactsData[$managerName]['TOTAL'])) * 100.0;
                    }

                    if ($contactCache->startDataCache()) {
                        $contactCache->endDataCache($contactsData[$managerName]);
                    }
                }
            }

            $contactPercents = [];
            foreach ($contactsData as $managerName => $cData) {
                $contactPercents[$managerName] = $cData['PERCENT'];
            }
            $contactsScores = $this->calculateScoresByNorm($contactPercents, 0.0);

            return [
                'contactsData' => $contactsData,
                'contactsScores' => $contactsScores
            ];
        }

        protected function calculateControlSum(array $data, array $leadTotals, array $leadScoreTotals, array $closureStats, array $scores, array $allStages): float
        {
            $controlSum = 0.0;
            foreach ($data as $managerName => $stagesData) {
                $controlSum += (float)($leadTotals[$managerName] ?? 0);
                $controlSum += (float)($leadScoreTotals[$managerName] ?? 0);

                $closure = $closureStats[$managerName] ?? null;
                if ($closure && ($closure['COUNT'] ?? 0) > 0) {
                    $avgDays = ($closure['SUM'] / max(1, $closure['COUNT'])) / 1440;
                    $controlSum += (float)$avgDays;
                }
                if (isset($scores['CLOSURE'][$managerName])) {
                    $controlSum += (float)$scores['CLOSURE'][$managerName];
                }

                foreach ($allStages as $stageCode) {
                    $countVal = isset($stagesData[$stageCode]['COUNT']) ? (int)$stagesData[$stageCode]['COUNT'] : 0;
                    $timeVal = $stagesData[$stageCode]['TIME'] ?? null;
                    $avgDaysStage = ($countVal > 0 && $timeVal !== null) ? ($timeVal / $countVal) / 1440 : null;

                    $controlSum += (float)$countVal;
                    if ($avgDaysStage !== null) {
                        $controlSum += (float)$avgDaysStage;
                    }
                    if (isset($scores[$stageCode][$managerName])) {
                        $controlSum += (float)$scores[$stageCode][$managerName];
                    }
                }
            }

            return $controlSum;
        }

        public function executeComponent()
        {
            if (!Loader::includeModule('crm')) {
                ShowError('CRM module not available');
                return;
            }

            $startTime = microtime(true);

            require_once __DIR__ . '/lib/DateConverter.php';
            require_once __DIR__ . '/lib/LeadReportService.php';
            require_once __DIR__ . '/lib/ContactReportService.php';
            require_once __DIR__ . '/lib/Logger.php';

            $converter = new DateConverter();
            $leadService = new LeadReportService($converter);
            $contactService = new ContactReportService();

            $request = Application::getInstance()->getContext()->getRequest();
            global $USER;

            // Даты по умолчанию: текущий квартал
            $now = new \DateTime();
            $month = (int)$now->format('n');
            $quarterStartMonth = (int)(floor(($month - 1) / 3) * 3 + 1);
            $quarterStart = new \DateTime($now->format('Y') . '-' . str_pad((string)$quarterStartMonth, 2, '0', STR_PAD_LEFT) . '-01');
            $quarterEnd = clone $quarterStart;
            $quarterEnd->modify('last day of +2 month');
            $defaultFromRaw = $quarterStart->format('d.m.Y');
            $defaultToRaw = $quarterEnd->format('d.m.Y');

            $savedSettings = [];
            $savedJson = Option::get('main', 'custom_antirating_settings', '');
            if ($savedJson !== '') {
                try {
                    $decoded = Json::decode($savedJson);
                    if (is_array($decoded)) {
                        $savedSettings = $decoded;
                    }
                } catch (\Throwable $e) {
                }
            }

            $usersRaw = $request->get('SETTINGS_USERS') ?? '';
            $managersToProcess = [];
            foreach (explode(',', (string)$usersRaw) as $uId) {
                $uId = (int)trim($uId);
                if ($uId > 0) {
                    $managersToProcess[] = $uId;
                }
            }
            if (empty($managersToProcess) && !empty($savedSettings['users']) && is_array($savedSettings['users'])) {
                $managersToProcess = array_map('intval', $savedSettings['users']);
            }
            $managerId = 0;

            $statusMap = $this->getAllStatusesMap();
            $allStages = array_keys($statusMap);

            $managerNameMap = [];
            if (!empty($managersToProcess)) {
                $usersRes = \Bitrix\Main\UserTable::getList([
                    'select' => ['ID','NAME','LAST_NAME'],
                    'filter' => ['@ID' => $managersToProcess]
                ]);
                while ($u = $usersRes->fetch()) {
                    $managerNameMap[(int)$u['ID']] = trim(($u['NAME'] ?? '') . ' ' . ($u['LAST_NAME'] ?? ''));
                }
            }

            $dateFromRaw = $request->get('DATE_FROM') ?? ($this->arParams['DATE_FROM'] ?? null);
            $dateToRaw = $request->get('DATE_TO') ?? ($this->arParams['DATE_TO'] ?? null);
            if (empty($dateFromRaw)) {
                $dateFromRaw = $defaultFromRaw;
            }
            if (empty($dateToRaw)) {
                $dateToRaw = $defaultToRaw;
            }
            $dateFrom = $this->parseDateParam($dateFromRaw);
            $dateTo = $this->parseDateParam($dateToRaw);
            $dateFromPhp = null;
            $dateToPhp = null;
            if ($dateFrom instanceof \Bitrix\Main\Type\DateTime) {
                $dateFromPhp = (new \DateTime('@' . $dateFrom->getTimestamp()))->setTimezone(new \DateTimeZone(date_default_timezone_get()));
            } elseif ($dateFrom instanceof \DateTimeInterface) {
                $dateFromPhp = $dateFrom;
            }
            if ($dateTo instanceof \Bitrix\Main\Type\DateTime) {
                $dateToPhp = (new \DateTime('@' . $dateTo->getTimestamp()))->setTimezone(new \DateTimeZone(date_default_timezone_get()));
            } elseif ($dateTo instanceof \DateTimeInterface) {
                $dateToPhp = $dateTo;
            }

            $normNew = $request->get('SETTINGS_NORM_NEW') !== null
                ? (float)$request->get('SETTINGS_NORM_NEW')
                : (float)($savedSettings['norm_new'] ?? 1);
            $normOther = $request->get('SETTINGS_NORM_OTHER') !== null
                ? (float)$request->get('SETTINGS_NORM_OTHER')
                : (float)($savedSettings['norm_other'] ?? 5);

            $errors = [];
            $applyFilter = ($request->get('FILTER_APPLY') === 'Y');
            if ($applyFilter) {
                if ($dateFromPhp && $dateToPhp) {
                    if ($dateToPhp < $dateFromPhp) {
                        $errors[] = 'Ошибка: дата конца должна быть позже или равна дате начала';
                    } else {
                        $intervalDays = $dateFromPhp->diff($dateToPhp)->days + 1;
                        if ($intervalDays > 367) {
                            $errors[] = 'Лимит по отчёту: только 365 дней';
                        }
                    }
                } else {
                    $errors[] = 'Укажите корректные даты';
                }
            }

            $saveFlag = $request->get('SAVE_SETTINGS');
            if ($saveFlag === 'Y' && is_object($USER) && $USER->IsAdmin()) {
                $toStore = [
                    'norm_new' => $normNew,
                    'norm_other' => $normOther,
                    'users' => $managersToProcess,
                ];
                try {
                    Option::set('main', 'custom_antirating_settings', Json::encode($toStore));
                } catch (\Throwable $e) {
                }
            }

            $data = [];
            $closureStats = [];
            $scores = [];
            $leadTotals = [];
            $leadScoreTotals = [];
            $contactsData = [];
            $contactsScores = [];

            if ($applyFilter && empty($errors)) {
                $leadsReport = $leadService->buildLeadsReport($managersToProcess, $managerNameMap, $dateFrom, $dateTo, $statusMap, $allStages, $normNew, $normOther);
                $data = $leadsReport['data'];
                $closureStats = $leadsReport['closureStats'];
                $scores = $leadsReport['scores'];
                $leadTotals = $leadsReport['leadTotals'];
                $leadScoreTotals = $leadsReport['leadScoreTotals'];

                $contactsReport = $contactService->buildContactsReport($managersToProcess, $managerNameMap, $dateFrom, $dateTo);
                $contactsData = $contactsReport['contactsData'];
                $contactsScores = $contactsReport['contactsScores'];
            }

            $readmeText = '';
            $readmePath = __DIR__ . '/READ ME.txt';
            if (file_exists($readmePath)) {
                $readmeText = (string)file_get_contents($readmePath);
            }

            $this->arResult['stages'] = $allStages;
            $this->arResult['statusMap'] = $statusMap;
            $this->arResult['data'] = $data;
            $this->arResult['managerId'] = $managerId;
            $this->arResult['closureStats'] = $closureStats;
            $this->arResult['scores'] = $scores;
            $this->arResult['leadTotals'] = $leadTotals;
            $this->arResult['leadScoreTotals'] = $leadScoreTotals;
            $this->arResult['contactsData'] = $contactsData;
            $this->arResult['contactsScores'] = $contactsScores;
            $this->arResult['filterValues'] = [
                'DATE_FROM' => $dateFromRaw,
                'DATE_TO' => $dateToRaw
            ];
            $this->arResult['generatedAt'] = $applyFilter && empty($errors) ? date('c') : null;
            $this->arResult['settings'] = [
                'norm_new' => $normNew,
                'norm_other' => $normOther,
                'users' => $managersToProcess,
                'user_names' => $managerNameMap,
                'cache_info' => 'Cache: 300 seconds; directories /custom/antirating/leads and /custom/antirating/contacts'
            ];
            $this->arResult['readmeText'] = $readmeText;

            $this->arResult['controlSum'] = ($applyFilter && empty($errors)) ? $this->calculateControlSum($data, $leadTotals, $leadScoreTotals, $closureStats, $scores, $allStages) : null;
            $this->arResult['executionSeconds'] = ($applyFilter && empty($errors)) ? (microtime(true) - $startTime) : null;
            $this->arResult['errors'] = $errors;
            $this->arResult['applyFilter'] = $applyFilter;

            $this->includeComponentTemplate();
        }
        protected function calculateScoresByNorm(array $averageDaysByManager, float $normativeDays): array
        {
            $rows = [];
            foreach ($averageDaysByManager as $managerName => $avgDays) {
                if ($avgDays === null) {
                    continue;
                }
                if ($avgDays <= $normativeDays) {
                    continue;
                }
                $rows[] = [
                    'manager' => $managerName,
                    'diff' => $avgDays - $normativeDays
                ];
            }

            if (empty($rows)) {
                return [];
            }

            usort($rows, function ($a, $b) {
                if (abs($a['diff'] - $b['diff']) < 1e-9) {
                    return 0;
                }
                return ($a['diff'] < $b['diff']) ? -1 : 1;
            });

            $scores = [];
            $currentScore = 1;
            $lastDiff = null;

            foreach ($rows as $index => $row) {
                if ($lastDiff !== null && abs($row['diff'] - $lastDiff) >= 1e-9) {
                    $currentScore = $index + 1;
                }
                $scores[$row['manager']] = $currentScore;
                $lastDiff = $row['diff'];
            }

            return $scores;
        }
    }
// refactoring marker
