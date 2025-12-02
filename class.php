<?php
// Изменение: 2025-01-05 23:001Слоняра
use Bitrix\Main\Loader;
use Bitrix\Main\Web\Json;
use Bitrix\Main\Application;
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
            // Дополнительно отсечём успех/провал по семантике
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

        // Fallback: detect typical финальные коды, даже если в статусе нет семантики
        $plainCode = strtoupper(strpos($stageCode, ':') !== false ? substr($stageCode, strrpos($stageCode, ':') + 1) : $stageCode);
        $finalCodes = ['CONVERTED', 'JUNK', 'WON', 'LOST', 'LOSE', 'FAILED', 'S', 'F'];
        return in_array($plainCode, $finalCodes, true);
    }

    protected function getLeadsByManager($managerId, ?\Bitrix\Main\Type\DateTime $dateFrom = null, ?\Bitrix\Main\Type\DateTime $dateTo = null)
    {
        $leadIds = [];

        if ($managerId <= 0) {
            return $leadIds;
        }

        // Надёжный вариант — GetListEx
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

        $res = \CCrmLead::GetListEx(
            ['ID' => 'ASC'],                       // сортировка
            $filter,                                // фильтр
            false, false,                           // группировка и постраничка
            ['ID']                                  // выбираемые поля
        );

        if ($res && is_object($res)) {
            while ($l = $res->Fetch()) {
                $leadIds[] = (int)$l['ID'];
            }
        }

        return $leadIds;
    }

        protected function getHistoryEntriesForLead($leadId)
        {
            $entries = [];

            if (!Loader::includeModule('crm')) {
                return $entries;
            }

            // HistoryEntry — основной источник
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
                    // игнорируем
                }
            }

            // fallback через CCrmEvent
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
                    // игнорируем
                }
            }

            return $entries;
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
                $aTs = $this->convertToTimestamp($a['TIME']);
                $bTs = $this->convertToTimestamp($b['TIME']);
                return $aTs <=> $bTs;
            });

            $startTs = $this->convertToTimestamp($lead['DATE_CREATE'] ?? null);
            if ($startTs === null) {
                $startTs = $this->convertToTimestamp($changes[0]['TIME']);
            }

            $currentStage = $changes[0]['FROM'] ?? ($lead['STATUS_ID'] ?? null);
            $durations = [];

            foreach ($changes as $change) {
                $changeTs = $this->convertToTimestamp($change['TIME']);
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

            $createTs = $this->convertToTimestamp($lead['DATE_CREATE'] ?? null);
            if ($createTs === null) {
                return null;
            }

            usort($timelineChanges, function ($a, $b) {
                $aTs = $this->convertToTimestamp($a['TIME']);
                $bTs = $this->convertToTimestamp($b['TIME']);
                return $aTs <=> $bTs;
            });

            foreach ($timelineChanges as $change) {
                $toStage = $change['TO'] ?? null;
                if (!$toStage || !$this->isFinalStage($toStage)) {
                    continue;
                }

                $closureTs = $this->convertToTimestamp($change['TIME']);
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

            $createTs = $this->convertToTimestamp($lead['DATE_CREATE'] ?? null);
            if ($createTs === null) {
                return null;
            }

            foreach ($historyEntries as $entry) {
                $stageCode = $entry['STAGE_ID'] ?? null;
                if (!$stageCode || !$this->isFinalStage($stageCode)) {
                    continue;
                }

                $stageTs = $this->convertToTimestamp($entry['CREATED_TIME'] ?? null);
                if ($stageTs === null) {
                    continue;
                }

                return max(0, ($stageTs - $createTs) / 60.0);
            }

            return null;
        }

        protected function convertToTimestamp($value): ?int
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
                    // fallback ниже
                }

                try {
                    return (new \DateTime($value, new \DateTimeZone(date_default_timezone_get())))->getTimestamp();
                } catch (\Exception $e) {
                    return null;
                }
            }

            return null;
        }

        protected function parseDateParam($value): ?\Bitrix\Main\Type\DateTime
        {
            if (empty($value) || !is_string($value)) {
                return null;
            }

            try {
                $date = \Bitrix\Main\Type\DateTime::createFromUserTime($value);
            } catch (\Throwable $e) {
                $date = null;
            }

            if ($date instanceof \Bitrix\Main\Type\DateTime) {
                return $date;
            }

            try {
                $phpDate = new \DateTime($value);
                return \Bitrix\Main\Type\DateTime::createFromPhp($phpDate);
            } catch (\Exception $e) {
                return null;
            }
        }

        public function executeComponent()
        {
            if (!Loader::includeModule('crm')) {
                ShowError('CRM module not available');
                return;
            }

            $allowedManagers = [157, 12, 39, 67, 130, 290, 2681];

            $request = Application::getInstance()->getContext()->getRequest();
            // По умолчанию обходим всех разрешённых; сузить выбор можно только при явном флаге FILTER_MANAGER=Y
            $managerIdRaw = $request->get('MANAGER_ID');
            $requestedManagerId = (int)$managerIdRaw;
            $managerFilterApplied = ($request->get('FILTER_MANAGER') === 'Y' && in_array($requestedManagerId, $allowedManagers, true));

            $managersToProcess = $managerFilterApplied ? [$requestedManagerId] : $allowedManagers;
            $managerId = $managerFilterApplied ? $requestedManagerId : 0;

            // Ограничиваем по стадиям процесса
            $statusMap = $this->getAllStatusesMap();
            $allStages = array_keys($statusMap);

            // Получаем имена доступных ответственных
            $managerNameMap = [];
            $usersRes = \Bitrix\Main\UserTable::getList([
                'select' => ['ID','NAME','LAST_NAME'],
                'filter' => ['@ID' => $allowedManagers]
            ]);
            while ($u = $usersRes->fetch()) {
                $managerNameMap[(int)$u['ID']] = trim(($u['NAME'] ?? '') . ' ' . ($u['LAST_NAME'] ?? ''));
            }

            // Инициализируем массив для отчёта
            $data = [];
            foreach ($managersToProcess as $managerIdItem) {
                $managerNameItem = $managerNameMap[$managerIdItem] ?? ('ID ' . $managerIdItem);
                $data[$managerNameItem] = [];
                foreach ($allStages as $stCode) {
                    $data[$managerNameItem][$stCode] = ['COUNT' => 0, 'TIME' => 0.0];
                }
            }

            // Получаем все лиды менеджера
            $request = Application::getInstance()->getContext()->getRequest();
            $dateFromRaw = $request->get('DATE_FROM') ?? ($this->arParams['DATE_FROM'] ?? null);
            $dateToRaw = $request->get('DATE_TO') ?? ($this->arParams['DATE_TO'] ?? null);

            $dateFrom = $this->parseDateParam($dateFromRaw);
            $dateTo = $this->parseDateParam($dateToRaw);

            $closureStats = [];
            $leadTotals = [];
            foreach ($managersToProcess as $managerIdItem) {
                $managerName = $managerNameMap[$managerIdItem] ?? ('ID ' . $managerIdItem);
                $leadIds = $this->getLeadsByManager($managerIdItem, $dateFrom, $dateTo);
                $leadTotals[$managerName] = count($leadIds);
                if (empty($leadIds)) {
                    continue;
                }

                foreach ($leadIds as $leadId) {
                    $lead = \CCrmLead::GetByID($leadId, true);
                    if (!$lead) {
                        continue;
                    }

                    $countedStages = [];
                    $timelineChanges = $this->getTimelineStageChanges($leadId);
                    $timelineDurations = $this->calculateDurationsFromTimeline($lead, $statusMap, $timelineChanges);

                    if (!empty($timelineDurations)) {
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
                            $historyEntries = $this->getHistoryEntriesForLead($leadId);
                            $closureDuration = $this->getClosureDurationFromHistory($lead, $historyEntries);
                            if ($closureDuration !== null) {
                                if (!isset($closureStats[$managerName])) {
                                    $closureStats[$managerName] = ['SUM' => 0.0, 'COUNT' => 0];
                                }
                                $closureStats[$managerName]['SUM'] += $closureDuration;
                                $closureStats[$managerName]['COUNT'] += 1;
                            }
                        }
                        continue;
                    }

                    $historyEntries = $this->getHistoryEntriesForLead($leadId);
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
                        $startTs = $this->convertToTimestamp($cur['CREATED_TIME']);
                        $endSource = ($i + 1 < $count) ? $historyEntries[$i + 1]['CREATED_TIME'] : (new \Bitrix\Main\Type\DateTime())->toString();
                        $endTs = $this->convertToTimestamp($endSource);

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
                            $createTs = $this->convertToTimestamp($lead['DATE_CREATE'] ?? null);
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

            // Рассчитываем баллы по нормативам
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

            // Общая сумма баллов по лидам (время до закрытия + все стадии)
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

            // Контакты
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
            }

            $contactPercents = [];
            foreach ($contactsData as $managerName => $cData) {
                $contactPercents[$managerName] = $cData['PERCENT'];
            }
            $contactsScores = $this->calculateScoresByNorm($contactPercents, 0.0);

            // Передаём данные в шаблон
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

            // Создание задач временно отключено по запросу (оставлено для будущего использования)
            /*
            if (empty($leadScoreTotals)) {
                $this->logMessage('tasks: leadScoreTotals is empty, task not created');
            } elseif (!Loader::includeModule('tasks')) {
                $this->logMessage('tasks: module not available, task not created');
            } else {
                $lines = [];
                foreach ($leadScoreTotals as $managerName => $scoreSum) {
                    $lines[] = $managerName . ': ' . (int)$scoreSum . ' балл(ов)';
                }
                $description = "Итоги антирейтинга по лидам за выбранный период:\n" . implode("\n", $lines);
                $taskFields = [
                    'TITLE' => 'Антирейтинг: итоги по лидам',
                    'DESCRIPTION' => $description,
                    'RESPONSIBLE_ID' => 2811,
                    'CREATED_BY' => 2811
                ];
                try {
                    $task = new \CTasks();
                    $taskId = $task->Add($taskFields);
                    if ($taskId) {
                        $this->logMessage('tasks: created task ID ' . $taskId);
                    } else {
                        $this->logMessage('tasks: creation returned empty task ID');
                    }
                } catch (\Throwable $e) {
                    $this->logMessage('tasks: exception ' . $e->getMessage());
                    // не мешаем отчёту при ошибке создания задачи
                }
            }
            */

            $this->includeComponentTemplate();
        }

        protected function logMessage(string $text): void
        {
            $logFile = __DIR__ . '/log0212.php';
            $prefix = '[' . date('c') . '] ';
            try {
                file_put_contents($logFile, $prefix . $text . PHP_EOL, FILE_APPEND | LOCK_EX);
            } catch (\Throwable $e) {
                // тихо игнорируем, чтобы не ломать отчёт
            }
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
