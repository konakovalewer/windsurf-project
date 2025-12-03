
<?php

use Bitrix\Main\Data\Cache;
use Bitrix\Crm\PhaseSemantics;

class LeadReportService
{
    protected DateConverter $converter;

    public function __construct(DateConverter $converter)
    {
        $this->converter = $converter;
    }

    public function getLeadsByManager($managerId, ?\Bitrix\Main\Type\DateTime $dateFrom = null, ?\Bitrix\Main\Type\DateTime $dateTo = null): array
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
                    $settings = \Bitrix\Main\Web\Json::decode($settings);
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
            $aTs = DateConverter::convertBitrixDateToTimestamp($a['TIME']) ?? DateConverter::convertDbStringToTimestamp($a['TIME']);
            $bTs = DateConverter::convertBitrixDateToTimestamp($b['TIME']) ?? DateConverter::convertDbStringToTimestamp($b['TIME']);
            return $aTs <=> $bTs;
        });

        $startTs = DateConverter::convertDbStringToTimestamp($lead['DATE_CREATE'] ?? null);
        if ($startTs === null) {
            $startTs = DateConverter::convertBitrixDateToTimestamp($changes[0]['TIME']) ?? DateConverter::convertDbStringToTimestamp($changes[0]['TIME']);
        }

        $currentStage = $changes[0]['FROM'] ?? ($lead['STATUS_ID'] ?? null);
        $durations = [];

        foreach ($changes as $change) {
            $changeTs = DateConverter::convertBitrixDateToTimestamp($change['TIME']) ?? DateConverter::convertDbStringToTimestamp($change['TIME']);
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

        $createTs = DateConverter::convertDbStringToTimestamp($lead['DATE_CREATE'] ?? null);
        if ($createTs === null) {
            return null;
        }

        usort($timelineChanges, function ($a, $b) {
            $aTs = DateConverter::convertBitrixDateToTimestamp($a['TIME']) ?? DateConverter::convertDbStringToTimestamp($a['TIME']);
            $bTs = DateConverter::convertBitrixDateToTimestamp($b['TIME']) ?? DateConverter::convertDbStringToTimestamp($b['TIME']);
            return $aTs <=> $bTs;
        });

        foreach ($timelineChanges as $change) {
            $toStage = $change['TO'] ?? null;
            if (!$toStage || !$this->isFinalStage($toStage)) {
                continue;
            }

            $closureTs = DateConverter::convertBitrixDateToTimestamp($change['TIME']) ?? DateConverter::convertDbStringToTimestamp($change['TIME']);
            if ($closureTs === null) {
                continue;
            }

            return max(0, ($closureTs - $createTs) / 60.0);
        }

        return null;
    }

    protected function getHistoryEntriesForLead($leadId): array
    {
        $entries = [];

        if (!Loader::includeModule('crm')) {
            return $entries;
        }

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
            }
        }

        return $entries;
    }

    protected function getClosureDurationFromHistory(array $lead, array $historyEntries): ?float
    {
        if (empty($historyEntries)) {
            return null;
        }

        usort($historyEntries, function ($a, $b) {
            return strtotime($a['CREATED_TIME']) <=> strtotime($b['CREATED_TIME']);
        });

        $createTs = DateConverter::convertDbStringToTimestamp($lead['DATE_CREATE'] ?? null);
        if ($createTs === null) {
            return null;
        }

        foreach ($historyEntries as $entry) {
            $stageCode = $entry['STAGE_ID'] ?? null;
            if (!$stageCode || !$this->isFinalStage($stageCode)) {
                continue;
            }

            $stageTs = DateConverter::convertDbStringToTimestamp($entry['CREATED_TIME'] ?? null);
            if ($stageTs === null) {
                continue;
            }

            return max(0, ($stageTs - $createTs) / 60.0);
        }

        return null;
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

    public function buildLeadsReport(array $managersToProcess, array $managerNameMap, ?\Bitrix\Main\Type\DateTime $dateFrom, ?\Bitrix\Main\Type\DateTime $dateTo, array $statusMap, array $allStages): array
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
                    $startTs = DateConverter::convertDbStringToTimestamp($cur['CREATED_TIME']);
                    $endSource = ($i + 1 < $count) ? $historyEntries[$i + 1]['CREATED_TIME'] : (new \Bitrix\Main\Type\DateTime())->toString();
                    $endTs = DateConverter::convertDbStringToTimestamp($endSource);

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
                        $createTs = DateConverter::convertDbStringToTimestamp($lead['DATE_CREATE'] ?? null);
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

    protected function isFinalStage(string $stageCode): bool
    {
        $semantic = \CCrmLead::GetSemanticID($stageCode);
        if ($semantic) {
            $semanticUpper = strtoupper((string)$semantic);
            if (in_array($semanticUpper, ['SUCCESS', 'FAILURE', 'S', 'F'], true)) {
                return true;
            }
            return PhaseSemantics::isFinal($semanticUpper);
        }

        $plainCode = strtoupper(strpos($stageCode, ':') !== false ? substr($stageCode, strrpos($stageCode, ':') + 1) : $stageCode);
        $finalCodes = ['CONVERTED', 'JUNK', 'WON', 'LOST', 'LOSE', 'FAILED', 'S', 'F'];
        return in_array($plainCode, $finalCodes, true);
    }
}
