<?php if (!defined("B_PROLOG_INCLUDED") || B_PROLOG_INCLUDED !== true) die(); ?>

<?php
global $APPLICATION;
$dateFrom = htmlspecialcharsbx($arResult['filterValues']['DATE_FROM'] ?? '');
$dateTo = htmlspecialcharsbx($arResult['filterValues']['DATE_TO'] ?? '');
$scores = $arResult['scores'] ?? [];
$leadTotals = $arResult['leadTotals'] ?? [];
$leadScoreTotals = $arResult['leadScoreTotals'] ?? [];
?>

<form method="get" name="antirating-filter" style="margin-bottom:16px; display:flex; gap:16px; align-items:flex-end; flex-wrap:wrap;">
    <div>
        <label style="display:block; margin-bottom:4px;">Дата создания от</label>
        <?php
        $APPLICATION->IncludeComponent('bitrix:main.calendar', '', [
            'SHOW_INPUT' => 'Y',
            'FORM_NAME' => 'antirating-filter',
            'INPUT_NAME' => 'DATE_FROM',
            'INPUT_VALUE' => $dateFrom,
            'SHOW_TIME' => 'N'
        ], false);
        ?>
    </div>
    <div>
        <label style="display:block; margin-bottom:4px;">Дата создания до</label>
        <?php
        $APPLICATION->IncludeComponent('bitrix:main.calendar', '', [
            'SHOW_INPUT' => 'Y',
            'FORM_NAME' => 'antirating-filter',
            'INPUT_NAME' => 'DATE_TO',
            'INPUT_VALUE' => $dateTo,
            'SHOW_TIME' => 'N'
        ], false);
        ?>
    </div>
    <input type="hidden" name="MANAGER_ID" value="<?= intval($arResult['managerId'] ?? 0) ?>">
    <div>
        <button type="submit" class="ui-btn ui-btn-primary">Показать</button>
        <a href="<?= strtok($APPLICATION->GetCurPageParam('', []), '?') ?>" class="ui-btn ui-btn-link">Сбросить</a>
    </div>
</form>

<table border="1" cellpadding="6" cellspacing="0" style="border-collapse:collapse;">
    <thead>
        <tr>
            <th rowspan="2">Ответственный</th>
            <th rowspan="2">Всего лидов</th>
            <th rowspan="2">Всего баллов</th>
            <th colspan="2">Время до закрытия, дни</th>
            <?php foreach ($arResult['stages'] as $stageCode): ?>
                <th colspan="3"><?= htmlspecialchars($arResult['statusMap'][$stageCode] ?? $stageCode) ?></th>
            <?php endforeach; ?>
        </tr>
        <tr>
            <th>Время, дни</th>
            <th>Балл</th>
            <?php foreach ($arResult['stages'] as $stageCode): ?>
                <th>Количество</th>
                <th>Время (дни)</th>
                <th>Балл</th>
            <?php endforeach; ?>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($arResult['data'] as $managerName => $stagesData): ?>
            <tr>
                <td><?= htmlspecialchars($managerName) ?></td>
                <td style="text-align:center;"><?= (int)($leadTotals[$managerName] ?? 0) ?></td>
                <td style="text-align:center;"><?= (int)($leadScoreTotals[$managerName] ?? 0) ?></td>
                <td style="text-align:right; padding-right:8px;">
                    <?php
                    $closure = $arResult['closureStats'][$managerName] ?? null;
                    if ($closure && $closure['COUNT'] > 0):
                        $avgDays = ($closure['SUM'] / max(1, $closure['COUNT'])) / 1440;
                        echo round($avgDays, 2);
                    else:
                        echo '-';
                    endif;
                    ?>
                </td>
                <td style="text-align:center;">
                    <?php
                    $closureScore = $scores['CLOSURE'][$managerName] ?? null;
                    echo $closureScore !== null ? (int)$closureScore : '-';
                    ?>
                </td>
                <?php foreach ($arResult['stages'] as $stageCode): ?>
                    <?php
                    $countVal = isset($stagesData[$stageCode]['COUNT']) ? (int)$stagesData[$stageCode]['COUNT'] : 0;
                    $timeVal = $stagesData[$stageCode]['TIME'] ?? null;
                    $avgDaysStage = ($countVal > 0 && $timeVal !== null) ? ($timeVal / $countVal) / 1440 : null;
                    ?>
                    <td style="text-align:center;"><?= $countVal ?></td>
                    <td style="text-align:right; padding-right:8px;">
                        <?= $avgDaysStage !== null ? round($avgDaysStage, 2) : '-' ?>
                    </td>
                    <td style="text-align:center;">
                        <?php
                        $stageScore = $scores[$stageCode][$managerName] ?? null;
                        echo $stageScore !== null ? (int)$stageScore : '-';
                        ?>
                    </td>
                <?php endforeach; ?>
            </tr>
        <?php endforeach; ?>
    </tbody>
</table>

<?php if (empty($arResult['data'])): ?>
    <p style="color:#666;">Данные не найдены (менеджер ID <?= intval($arResult['managerId'] ?? 0) ?>). Проверьте MANAGER_ID или наличие истории стадий у лидов.</p>
<?php endif; ?>

<h3 style="margin-top:32px;">Контакты</h3>
<table border="1" cellpadding="6" cellspacing="0" style="border-collapse:collapse; margin-top:8px;">
    <thead>
        <tr>
            <th>Ответственный</th>
            <th>Создано всего</th>
            <th>Заполнено неполноценно</th>
            <th>% незаполненных</th>
            <th>Балл</th>
        </tr>
    </thead>
    <tbody>
        <?php foreach (($arResult['contactsData'] ?? []) as $managerName => $cData): ?>
            <tr>
                <td><?= htmlspecialchars($managerName) ?></td>
                <td style="text-align:center;"><?= (int)($cData['TOTAL'] ?? 0) ?></td>
                <td style="text-align:center;"><?= (int)($cData['INCOMPLETE'] ?? 0) ?></td>
                <td style="text-align:right; padding-right:8px;">
                    <?php
                    $percent = $cData['PERCENT'] ?? null;
                    echo $percent !== null ? round($percent, 2) : '-';
                    ?>
                </td>
                <td style="text-align:center;">
                    <?php
                    $score = $arResult['contactsScores'][$managerName] ?? null;
                    echo $score !== null ? (int)$score : '-';
                    ?>
                </td>
            </tr>
        <?php endforeach; ?>
    </tbody>
</table>
