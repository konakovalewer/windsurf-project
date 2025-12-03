
<?php

use Bitrix\Main\Data\Cache;

class ContactReportService
{
    public function buildContactsReport(array $managersToProcess, array $managerNameMap, ?\Bitrix\Main\Type\DateTime $dateFrom, ?\Bitrix\Main\Type\DateTime $dateTo): array
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

    protected function calculateScoresByNorm(array $averageByManager, float $normative): array
    {
        $rows = [];
        foreach ($averageByManager as $managerName => $avg) {
            if ($avg === null) {
                continue;
            }
            if ($avg <= $normative) {
                continue;
            }
            $rows[] = [
                'manager' => $managerName,
                'diff' => $avg - $normative
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
