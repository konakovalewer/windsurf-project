
<?php

use Bitrix\Main\Data\Cache;
use Bitrix\Crm\PhaseSemantics;
use Bitrix\Main\Loader;

class LeadReportService
{
    protected DateConverter $converter;
    protected array $holidaySet = [];
    protected array $manualHolidaysMd = []; // формат MM-DD
    protected int $workStartSeconds = 9 * 3600;
    protected int $workEndSeconds = 18 * 3600;

    public function __construct(DateConverter $converter)
    {
        $this->converter = $converter;
        $this->holidaySet = $this->loadHolidayDates();
    }

    public function configureWorktime(int $startSeconds, int $endSeconds, array $manualHolidaysMd): void
    {
        // защита от неверных значений
        if ($startSeconds < 0 || $startSeconds >= 86400) {
            $startSeconds = 9 * 3600;
        }
        if ($endSeconds <= $startSeconds || $endSeconds > 86400) {
            $endSeconds = 18 * 3600;
        }
        $this->workStartSeconds = $startSeconds;
        $this->workEndSeconds = $endSeconds;
        $this->manualHolidaysMd = $manualHolidaysMd;
    }

    protected function loadHolidayDates(): array
    {
        $set = [];
        $conn = \Bitrix\Main\Application::getConnection();
        if (!$conn->isTableExists('b_timeman_work_calendar_exclusion')) {
            return $set;
        }
        try {
            $res = $conn->query("SELECT YEAR, DATES FROM b_timeman_work_calendar_exclusion");
            while ($row = $res->fetch()) {
                $year = (int)$row['YEAR'];
                $datesJson = $row['DATES'] ?? '';
                if (!is_string($datesJson) || $datesJson === '') {
                    continue;
                }
                $decoded = json_decode($datesJson, true);
                if (!is_array($decoded)) {
                    continue;
                }
                foreach ($decoded as $month => $days) {
                    if (!is_array($days)) {
                        continue;
                    }
                    $m = (int)$month;
                    foreach ($days as $day => $flag) {
                        $d = (int)$day;
                        if ($d <= 0 || $m <= 0 || $year <= 0) {
                            continue;
                        }
                        $key = sprintf('%04d-%02d-%02d', $year, $m, $d);
                        $set[$key] = true;
                    }
                }
            }
        } catch (\Throwable $e) {
        }
        return $set;
    }

    protected function isNonWorkingDay(int $ts): bool
    {
        $date = new \DateTime('@' . $ts);
        $date->setTimezone(new \DateTimeZone(date_default_timezone_get()));
        $key = $date->format('Y-m-d');
        // выходные
        $weekday = (int)$date->format('w'); // 0=Sun,6=Sat
        if ($weekday === 0 || $weekday === 6) {
            return true;
        }
        // ручные праздники MM-DD
        $md = $date->format('m-d');
        if (in_array($md, $this->manualHolidaysMd, true)) {
            return true;
        }
        // праздники из календаря
        if (isset($this->holidaySet[$key])) {
            return true;
        }
        return false;
    }

    protected function calculateWorkingMinutes(?int $startTs, ?int $endTs): float
    {
        if ($startTs === null || $endTs === null || $endTs <= $startTs) {
            return 0.0;
        }
        $total = 0.0;
        $cur = $startTs;
        $tz = new \DateTimeZone(date_default_timezone_get());
        $workStart = $this->workStartSeconds;
        $workEnd = $this->workEndSeconds;
        $workDaySeconds = $workEnd - $workStart;
        if ($workDaySeconds <= 0) {
            return 0.0;
        }
        while ($cur < $endTs) {
            $day = (new \DateTime('@' . $cur))->setTimezone($tz);
            $day->setTime(0, 0, 0);
            $dayStartTs = $day->getTimestamp();
            $dayWorkStart = $dayStartTs + $workStart;
            $dayWorkEnd = $dayStartTs + $workEnd;
            $nextDayTs = $dayStartTs + 86400;

            $segmentStart = max($cur, $dayWorkStart);
            $segmentEnd = min($endTs, $dayWorkEnd);

            if (!$this->isNonWorkingDay($cur) && $segmentEnd > $segmentStart) {
                // пропорционально отрезку рабочего дня
                $segmentSeconds = $segmentEnd - $segmentStart;
                $minutes = ($segmentSeconds / $workDaySeconds) * 480; // 8 часов = 480 минут
                $total += $minutes;
            }

            $cur = max($nextDayTs, $segmentEnd);
        }
        return $total;
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

    public function getLeadsCountByManager($managerId, ?\Bitrix\Main\Type\DateTime $dateFrom = null, ?\Bitrix\Main\Type\DateTime $dateTo = null): int
    {
        if ($managerId <= 0) {
            return 0;
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

        $res = \CCrmLead::GetListEx([], $filter, false, false, ['ID']);
        if ($res && is_object($res)) {
            // SelectedRowsCount без навигации вернёт общее число
            $count = (int)$res->SelectedRowsCount();
            if ($count > 0) {
                return $count;
            }
            // fallback — пересчитать вручную
            $cnt = 0;
            while ($res->Fetch()) {
                $cnt++;
            }
            return $cnt;
        }
        return 0;
    }

    protected function getStatusHistoryEntriesForLead(int $leadId): array
    {
        $rows = [];
        // Предпочитаем ORM/Query, чтобы избежать конкатенации SQL
        if (class_exists('\\Bitrix\\Crm\\History\\Entity\\LeadStatusHistoryTable')) {
            try {
                $res = \Bitrix\Crm\History\Entity\LeadStatusHistoryTable::getList([
                    'filter' => ['=OWNER_ID' => $leadId],
                    'order' => ['CREATED_TIME' => 'ASC'],
                    'select' => ['STATUS_ID', 'CREATED_TIME']
                ]);
                while ($row = $res->fetch()) {
                    if (empty($row['STATUS_ID'])) {
                        continue;
                    }
                    $rows[] = [
                        'STAGE_ID' => $row['STATUS_ID'],
                        'CREATED_TIME' => $row['CREATED_TIME']
                    ];
                }
                return $rows;
            } catch (\Throwable $e) {
                // fallback ниже
            }
        }

        try {
            $conn = \Bitrix\Main\Application::getConnection();
            $sql = "SELECT STATUS_ID, CREATED_TIME FROM b_crm_lead_status_history WHERE OWNER_ID = " . (int)$leadId . " ORDER BY CREATED_TIME ASC";
            $res = $conn->query($sql);
            while ($row = $res->fetch()) {
                if (empty($row['STATUS_ID'])) {
                    continue;
                }
                $rows[] = [
                    'STAGE_ID' => $row['STATUS_ID'],
                    'CREATED_TIME' => $row['CREATED_TIME']
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
                $minutes = $this->calculateWorkingMinutes($startTs, $changeTs);
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
            $minutes = $this->calculateWorkingMinutes($startTs, $nowTs);
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

            return $this->calculateWorkingMinutes($createTs, $closureTs);
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

            return $this->calculateWorkingMinutes($createTs, $stageTs);
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

    /**
     * Пакетная загрузка истории статусов для множества лидов.
     */
    protected function preloadStatusHistory(array $leadIds): array
    {
        $result = [];
        if (empty($leadIds)) {
            return $result;
        }
        $chunks = array_chunk($leadIds, 500);
        foreach ($chunks as $chunk) {
            if (class_exists('\\Bitrix\\Crm\\History\\Entity\\LeadStatusHistoryTable')) {
                $res = \Bitrix\Crm\History\Entity\LeadStatusHistoryTable::getList([
                    'filter' => ['@OWNER_ID' => $chunk],
                    'order' => ['CREATED_TIME' => 'ASC'],
                    'select' => ['OWNER_ID', 'STATUS_ID', 'CREATED_TIME']
                ]);
                while ($row = $res->fetch()) {
                    if (empty($row['STATUS_ID'])) {
                        continue;
                    }
                    $ownerId = (int)$row['OWNER_ID'];
                    if (!isset($result[$ownerId])) {
                        $result[$ownerId] = [];
                    }
                    $result[$ownerId][] = [
                        'STAGE_ID' => $row['STATUS_ID'],
                        'CREATED_TIME' => $row['CREATED_TIME']
                    ];
                }
            } else {
                $conn = \Bitrix\Main\Application::getConnection();
                $idsStr = implode(',', array_map('intval', $chunk));
                $sql = "SELECT OWNER_ID, STATUS_ID, CREATED_TIME FROM b_crm_lead_status_history WHERE OWNER_ID IN ({$idsStr}) ORDER BY CREATED_TIME ASC";
                $res = $conn->query($sql);
                while ($row = $res->fetch()) {
                    if (empty($row['STATUS_ID'])) {
                        continue;
                    }
                    $ownerId = (int)$row['OWNER_ID'];
                    if (!isset($result[$ownerId])) {
                        $result[$ownerId] = [];
                    }
                    $result[$ownerId][] = [
                        'STAGE_ID' => $row['STATUS_ID'],
                        'CREATED_TIME' => $row['CREATED_TIME']
                    ];
                }
            }
        }
        return $result;
    }

    /**
     * Пакетная загрузка таймлайна смен статусов по лидам.
     */
    protected function preloadTimelineChanges(array $leadIds): array
    {
        $result = [];
        if (empty($leadIds)) {
            return $result;
        }

        $bindingClass = null;
        if (class_exists('\\Bitrix\\Crm\\Timeline\\Entity\\TimelineBindingTable')) {
            $bindingClass = '\\Bitrix\\Crm\\Timeline\\Entity\\TimelineBindingTable';
        } elseif (class_exists('\\Bitrix\\Crm\\Timeline\\Entity\\Timeline\\TimelineBindingTable')) {
            $bindingClass = '\\Bitrix\\Crm\\Timeline\\Entity\\Timeline\\TimelineBindingTable';
        } else {
            return $result;
        }

        $timelineClass = null;
        if (class_exists('\\Bitrix\\Crm\\Timeline\\Entity\\TimelineTable')) {
            $timelineClass = '\\Bitrix\\Crm\\Timeline\\Entity\\TimelineTable';
        } elseif (class_exists('\\Bitrix\\Crm\\TimelineTable')) {
            $timelineClass = '\\Bitrix\\Crm\\TimelineTable';
        } else {
            return $result;
        }

        $chunks = array_chunk($leadIds, 200);
        foreach ($chunks as $chunk) {
            $bindingsRes = $bindingClass::getList([
                'filter' => [
                    '=ENTITY_TYPE_ID' => \CCrmOwnerType::Lead,
                    '@ENTITY_ID' => $chunk
                ],
                'select' => ['OWNER_ID', 'ENTITY_ID']
            ]);
            $timelineIdsByLead = [];
            while ($bind = $bindingsRes->fetch()) {
                $leadId = (int)$bind['ENTITY_ID'];
                $timelineIdsByLead[$leadId][] = (int)$bind['OWNER_ID'];
            }
            if (empty($timelineIdsByLead)) {
                continue;
            }
            $allTimelineIds = [];
            foreach ($timelineIdsByLead as $ids) {
                $allTimelineIds = array_merge($allTimelineIds, $ids);
            }
            $allTimelineIds = array_values(array_unique($allTimelineIds));

            $rows = $timelineClass::getList([
                'filter' => ['@ID' => $allTimelineIds],
                'order' => ['CREATED' => 'ASC'],
                'select' => ['ID', 'CREATED', 'SETTINGS']
            ]);
            $timelineData = [];
            while ($row = $rows->fetch()) {
                $timelineData[(int)$row['ID']] = $row;
            }
            foreach ($timelineIdsByLead as $leadId => $ids) {
                foreach ($ids as $tid) {
                    if (!isset($timelineData[$tid])) {
                        continue;
                    }
                    $row = $timelineData[$tid];
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
                    if (!isset($result[$leadId])) {
                        $result[$leadId] = [];
                    }
                    $result[$leadId][] = [
                        'TIME' => $row['CREATED'],
                        'FROM' => $settings['START'] ?? null,
                        'TO' => $settings['FINISH'] ?? null
                    ];
                }
            }
        }

        return $result;
    }

    /**
     * Пакетная загрузка событий (fallback) по лидам.
     */
    protected function preloadEventsHistory(array $leadIds): array
    {
        $result = [];
        if (empty($leadIds) || !class_exists('CCrmEvent')) {
            return $result;
        }
        $chunks = array_chunk($leadIds, 200);
        foreach ($chunks as $chunk) {
            $ev = \CCrmEvent::GetList(
                ['DATE_CREATE' => 'ASC'],
                ['ENTITY_TYPE' => 'LEAD', '@ENTITY_ID' => $chunk]
            );
            while ($row = $ev->Fetch()) {
                $stageCode = null;
                if (!empty($row['EVENT_TEXT_2'])) {
                    $stageCode = trim($row['EVENT_TEXT_2']);
                }
                if (!$stageCode) {
                    continue;
                }
                $leadId = (int)$row['ENTITY_ID'];
                if (!isset($result[$leadId])) {
                    $result[$leadId] = [];
                }
                $result[$leadId][] = [
                    'STAGE_ID' => $stageCode,
                    'CREATED_TIME' => $row['DATE_CREATE']
                ];
            }
        }
        return $result;
    }

    /**
     * Деталь по одному лиду: длительности по этапам и время до закрытия.
     */
    public function computeLeadDetail(array $lead, array $statusMap): array
    {
        $leadId = (int)($lead['ID'] ?? 0);
        $durations = [];
        $closureMinutes = null;
        $source = 'none';

        // 1) Статус-хистори как основной источник
        $historyEntries = $this->getStatusHistoryEntriesForLead($leadId);
        if (!empty($historyEntries)) {
            $this->fillDurationsFromHistory($lead, $historyEntries, $statusMap, $durations, $closureMinutes);
            $source = 'status_history';
            return compact('durations', 'closureMinutes', 'source');
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
            $source = 'timeline';
            return compact('durations', 'closureMinutes', 'source');
        }

        // 3) События как запасной вариант
        $eventHistory = $this->getHistoryEntriesForLead($leadId);
        if (!empty($eventHistory)) {
            $this->fillDurationsFromHistory($lead, $eventHistory, $statusMap, $durations, $closureMinutes);
            $source = 'events';
            return compact('durations', 'closureMinutes', 'source');
        }

        return compact('durations', 'closureMinutes', 'source');
    }

    /**
     * Вспомогательный расчёт по истории (делится с агрегированным расчётом и детализацией).
     */
    protected function fillDurationsFromHistory(array $lead, array $historyEntries, array $statusMap, array &$durations, ?float &$closureMinutes): void
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
            $minutes = $this->calculateWorkingMinutes($startTs, $endTs);
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
                    $closureMinutes = $this->calculateWorkingMinutes($createTs, $startTs);
                }
            }
        }
    }

    public function buildLeadsReport(array $managersToProcess, array $managerNameMap, ?\Bitrix\Main\Type\DateTime $dateFrom, ?\Bitrix\Main\Type\DateTime $dateTo, array $statusMap, array $allStages, float $normNew, float $normOther): array
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

            $leadIds = array_keys($leadRows);
            $statusHistoryMap = $this->preloadStatusHistory($leadIds);
            $timelineMap = $this->preloadTimelineChanges($leadIds);
            $eventMap = $this->preloadEventsHistory($leadIds);

            foreach ($leadRows as $leadId => $lead) {
                if (empty($lead)) {
                    continue;
                }

                $countedStages = [];
                $statusHistoryEntries = $this->getStatusHistoryEntriesForLead($leadId);
                $usedTimeline = false;

                // Batching: заранее загружаем истории/таймлайн/события
                $statusHistoryEntries = $statusHistoryMap[$leadId] ?? [];
                $timelineChanges = $timelineMap[$leadId] ?? [];
                $eventEntries = $eventMap[$leadId] ?? [];

                $usedTimeline = false;
                $finalClosureMinutes = null;

                if (!empty($statusHistoryEntries)) {
                    // считаем по истории
                    usort($statusHistoryEntries, function ($a, $b) {
                        return strtotime($a['CREATED_TIME']) <=> strtotime($b['CREATED_TIME']);
                    });
                    $count = count($statusHistoryEntries);
                    for ($i = 0; $i < $count; $i++) {
                        $cur = $statusHistoryEntries[$i];
                        $startTs = DateConverter::convertDbStringToTimestamp($cur['CREATED_TIME']);
                        $endSource = ($i + 1 < $count) ? $statusHistoryEntries[$i + 1]['CREATED_TIME'] : (new \Bitrix\Main\Type\DateTime())->toString();
                        $endTs = DateConverter::convertDbStringToTimestamp($endSource);
                        if ($startTs === null || $endTs === null) {
                            continue;
                        }
                        $minutes = $this->calculateWorkingMinutes($startTs, $endTs);
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
                                $finalClosureMinutes = $this->calculateWorkingMinutes($createTs, $startTs);
                            }
                        }
                    }
                } else {
                    // пробуем таймлайн
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
                        if ($closureDuration === null) {
                            $closureDuration = $this->getClosureDurationFromHistory($lead, $eventEntries);
                        }
                        if ($closureDuration !== null) {
                            if (!isset($closureStats[$managerName])) {
                                $closureStats[$managerName] = ['SUM' => 0.0, 'COUNT' => 0];
                            }
                            $closureStats[$managerName]['SUM'] += $closureDuration;
                            $closureStats[$managerName]['COUNT'] += 1;
                        }
                    } else {
                        // события как запасной вариант
                        if (!empty($eventEntries)) {
                            usort($eventEntries, function ($a, $b) {
                                return strtotime($a['CREATED_TIME']) <=> strtotime($b['CREATED_TIME']);
                            });
                            $count = count($eventEntries);
                            for ($i = 0; $i < $count; $i++) {
                                $cur = $eventEntries[$i];
                                $startTs = DateConverter::convertDbStringToTimestamp($cur['CREATED_TIME']);
                                $endSource = ($i + 1 < $count) ? $eventEntries[$i + 1]['CREATED_TIME'] : (new \Bitrix\Main\Type\DateTime())->toString();
                                $endTs = DateConverter::convertDbStringToTimestamp($endSource);
                                if ($startTs === null || $endTs === null) {
                                    continue;
                                }
                                $minutes = $this->calculateWorkingMinutes($startTs, $endTs);
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
                                        $finalClosureMinutes = $this->calculateWorkingMinutes($createTs, $startTs);
                                    }
                                }
                            }
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

        $defaultNormDays = $normOther;
        $normativeDaysByStage = [
            'NEW' => $normNew
        ];

        $stageAverages = [];
        foreach ($allStages as $stCode) {
            foreach ($data as $managerName => $stagesData) {
                $countVal = isset($stagesData[$stCode]['COUNT']) ? (int)$stagesData[$stCode]['COUNT'] : 0;
                $timeVal = $stagesData[$stCode]['TIME'] ?? null;
                $avgDaysStage = ($countVal > 0 && $timeVal !== null) ? ($timeVal / $countVal) / 480 : null;
                $stageAverages[$stCode][$managerName] = $avgDaysStage;
            }
        }

        $closureAverages = [];
        foreach ($data as $managerName => $_) {
            $closure = $closureStats[$managerName] ?? null;
            if ($closure && ($closure['COUNT'] ?? 0) > 0) {
                $closureAverages[$managerName] = ($closure['SUM'] / max(1, $closure['COUNT'])) / 480;
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
