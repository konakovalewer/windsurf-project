<?php
use Bitrix\Main\Loader;

if (!defined("B_PROLOG_INCLUDED") || B_PROLOG_INCLUDED !== true) die();

class AntiratingReport extends CBitrixComponent
{
    protected function getAllStatusesMap()
    {
        $map = [];
        $res = \CCrmStatus::GetList(['SORT' => 'ASC'], ['ENTITY_ID' => 'STATUS']);
        while ($s = $res->Fetch()) {
            $map[$s['STATUS_ID']] = $s['NAME'];
        }
        return $map;
    }

    protected function getLeadsByManager($managerId)
    {
        $leadIds = [];

        if ($managerId <= 0) {
            return $leadIds;
        }

        // Надёжный вариант — GetListEx
        $res = \CCrmLead::GetListEx(
            ['ID' => 'ASC'],                       // сортировка
            ['ASSIGNED_BY_ID' => $managerId, 'CHECK_PERMISSIONS' => 'N'], // фильтр
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

    public function executeComponent()
    {
        if (!Loader::includeModule('crm')) {
            ShowError('CRM module not available');
            return;
        }

        $managerId = isset($this->arParams['MANAGER_ID']) ? (int)$this->arParams['MANAGER_ID'] : 157;
        if ($managerId <= 0) {
            ShowError('Не задан MANAGER_ID');
            return;
        }

        // Получаем карту стадий
        $statusMap = $this->getAllStatusesMap();
        $allStages = array_keys($statusMap);

        // Информация о менеджере
        $user = \Bitrix\Main\UserTable::getList([
            'select' => ['ID','NAME','LAST_NAME'],
            'filter' => ['ID' => $managerId]
        ])->fetch();

        if (!$user) {
            ShowError('Менеджер не найден: ' . $managerId);
            return;
        }

        $managerName = trim(($user['NAME'] ?? '') . ' ' . ($user['LAST_NAME'] ?? ''));

        // Инициализируем массив для отчёта
        $data = [];
        $data[$managerName] = [];
        foreach ($allStages as $stCode) {
            $data[$managerName][$stCode] = ['COUNT' => 0, 'TIME' => 0.0];
        }

        // Получаем все лиды менеджера
        $leadIds = $this->getLeadsByManager($managerId);
        if (!empty($leadIds)) {
            foreach ($leadIds as $leadId) {
                $entries = $this->getHistoryEntriesForLead($leadId);
                if (empty($entries)) continue;

                // Сортируем по дате
                usort($entries, function($a, $b){
                    return strtotime($a['CREATED_TIME']) <=> strtotime($b['CREATED_TIME']);
                });

                $count = count($entries);
                for ($i = 0; $i < $count; $i++) {
                    $cur = $entries[$i];
                    $start = new \DateTime($cur['CREATED_TIME']);
                    $end = ($i + 1 < $count) ? new \DateTime($entries[$i + 1]['CREATED_TIME']) : new \DateTime();
                    $minutes = max(0, ($end->getTimestamp() - $start->getTimestamp()) / 60.0);

                    $stageCode = $cur['STAGE_ID'];
                    if (!isset($data[$managerName][$stageCode])) {
                        $data[$managerName][$stageCode] = ['COUNT' => 0, 'TIME' => 0.0];
                        if (!in_array($stageCode, $allStages, true)) {
                            $allStages[] = $stageCode;
                        }
                    }

                    $data[$managerName][$stageCode]['TIME'] += $minutes;
                }
            }
        }

        // Передаём данные в шаблон
        $this->arResult['stages'] = $allStages;
        $this->arResult['statusMap'] = $statusMap;
        $this->arResult['data'] = $data;
        $this->arResult['managerName'] = $managerName;
        $this->arResult['managerId'] = $managerId;

        $this->includeComponentTemplate();
    }
}
