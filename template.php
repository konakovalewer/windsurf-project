<?php if (!defined("B_PROLOG_INCLUDED") || B_PROLOG_INCLUDED !== true) die(); ?>

<?php
global $APPLICATION;
$dateFrom = htmlspecialcharsbx($arResult['filterValues']['DATE_FROM'] ?? '');
$dateTo = htmlspecialcharsbx($arResult['filterValues']['DATE_TO'] ?? '');
$scores = $arResult['scores'] ?? [];
$leadTotals = $arResult['leadTotals'] ?? [];
$leadScoreTotals = $arResult['leadScoreTotals'] ?? [];
$generatedAt = $arResult['generatedAt'] ?? '';
$controlSum = $arResult['controlSum'] ?? null;
$executionSeconds = $arResult['executionSeconds'] ?? null;

\Bitrix\Main\UI\Extension::load('ui.entity-selector');
?>

<style>
    .ar-settings {
        border: 1px solid #e0e0e0;
        border-radius: 4px;
        margin-bottom: 16px;
        background: #fafafa;
    }
    .ar-settings__header {
        padding: 10px 12px;
        cursor: pointer;
        font-weight: 600;
        display: flex;
        align-items: center;
        justify-content: space-between;
    }
    .ar-settings__content {
        display: none;
        padding: 12px;
        border-top: 1px solid #e0e0e0;
    }
    .ar-settings__block {
        margin-bottom: 16px;
    }
    .ar-settings__block h4 {
        margin: 0 0 8px 0;
        font-size: 14px;
    }
    .ar-settings__row {
        display: flex;
        gap: 8px;
        align-items: center;
        margin-bottom: 8px;
    }
    .ar-settings__list {
        display: flex;
        flex-wrap: wrap;
        gap: 6px;
        margin-top: 8px;
    }
    .ar-pill {
        background: #eef3ff;
        border: 1px solid #c6d4ff;
        border-radius: 12px;
        padding: 4px 8px;
        display: inline-flex;
        align-items: center;
        gap: 6px;
        font-size: 12px;
    }
    .ar-pill button {
        border: none;
        background: transparent;
        cursor: pointer;
        color: #888;
        font-size: 12px;
        line-height: 1;
    }
    .ar-input {
        padding: 6px 8px;
        border: 1px solid #dfe3e8;
        border-radius: 4px;
        min-width: 80px;
    }
    .ar-button {
        padding: 6px 10px;
        border: 1px solid #2f7be5;
        background: #2f7be5;
        color: #fff;
        border-radius: 4px;
        cursor: pointer;
    }
    .ar-muted {
        color: #888;
        font-size: 12px;
    }
    .ar-section-title {
        font-weight: 700;
        margin: 0;
        font-size: 15px;
    }
    .ar-flex {
        display: flex;
        gap: 8px;
        align-items: center;
    }
</style>

<div class="ar-settings" id="ar-settings">
    <div class="ar-settings__header" onclick="(function(box){ box.style.display = box.style.display === 'block' ? 'none' : 'block';})(document.getElementById('ar-settings-box'));">
        <span class="ar-section-title">Настройки (для администраторов)</span>
        <span class="ar-muted">кликните, чтобы раскрыть</span>
    </div>
    <div class="ar-settings__content" id="ar-settings-box">
        <div class="ar-settings__block">
            <h4>Настройка нормативов по этапам</h4>
            <div class="ar-settings__row">
                <label>NEW:</label>
                <input type="number" class="ar-input" data-setting-key="norm_new" value="1" min="0" step="0.1">
            </div>
            <div class="ar-settings__row">
                <label>Остальные этапы:</label>
                <input type="number" class="ar-input" data-setting-key="norm_other" value="5" min="0" step="0.1">
            </div>
            <div class="ar-muted">Пока эти поля не связаны с расчётами (только визуальный макет).</div>
        </div>
        <div class="ar-settings__block">
            <h4>Пользователи</h4>
            <div class="ar-flex" style="margin-bottom:8px;">
                <input type="text" id="ar-user-input" class="ar-input" placeholder="Введите имя или ID" onclick="arOpenUserSelector()" readonly>
                <button type="button" class="ar-button" onclick="arAddUser()">Добавить</button>
            </div>
            <div class="ar-muted">Используйте поиск и добавьте в список. Сейчас это демонстрация, без связи с отчётом.</div>
            <div style="margin-top:10px; font-weight:600;">Пользователи, по которым выводится отчёт:</div>
            <div class="ar-settings__list" id="ar-user-list"></div>
        </div>
        <div class="ar-settings__block" style="display:flex; gap:8px; align-items:center;">
            <button type="button" class="ar-button" onclick="arApplySettings()">Применить</button>
            <button type="button" class="ar-button" style="background:#ccc; border-color:#ccc; color:#000;" onclick="arCancelSettings()">Отмена</button>
            <div class="ar-muted">Пока сохраняет только в памяти страницы.</div>
        </div>
    </div>
</div>

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

<div style="margin-top:12px;">
    <?php if ($generatedAt): ?>
        <div>Отчёт сформирован: <?= htmlspecialchars($generatedAt) ?></div>
    <?php endif; ?>
    <?php if ($controlSum !== null): ?>
        <div>Контрольное число (лиды): <?= round((float)$controlSum, 4) ?></div>
    <?php endif; ?>
    <?php if ($executionSeconds !== null): ?>
        <div>Время формирования (сек): <?= round((float)$executionSeconds, 4) ?></div>
    <?php endif; ?>
</div>

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

<script>
BX.ready(function() {
    // Для хранения исходных значений настроек
    var arInitialSettings = {
        norms: {},
        users: []
    };

    function arCaptureInitial() {
        var normNew = document.querySelector('[data-setting-key="norm_new"]');
        var normOther = document.querySelector('[data-setting-key="norm_other"]');
        arInitialSettings.norms = {
            norm_new: normNew ? normNew.value : '',
            norm_other: normOther ? normOther.value : ''
        };
        arInitialSettings.users = [];
        var list = document.getElementById('ar-user-list');
        if (list) {
            list.querySelectorAll('.ar-pill').forEach(function(pill) {
                arInitialSettings.users.push(pill.dataset.user || pill.textContent);
            });
        }
    }
    arCaptureInitial();

    window.arAddUser = function() {
        var input = document.getElementById('ar-user-input');
        if (!input) return;
        var val = (input.value || '').trim();
        if (!val) return;
        var list = document.getElementById('ar-user-list');
        if (!list) return;
        var pill = document.createElement('div');
        pill.className = 'ar-pill';
        pill.textContent = val;
        pill.dataset.user = val;
        var btn = document.createElement('button');
        btn.type = 'button';
        btn.textContent = 'x';
        btn.onclick = function(){ pill.remove(); };
        pill.appendChild(btn);
        list.appendChild(pill);
        input.value = '';
    };

    window.arOpenUserSelector = function() {
        var dialog = new BX.UI.EntitySelector.Dialog({
            targetNode: document.getElementById('ar-user-input'),
            enableSearch: true,
            multiple: false,
            width: 420,
            entities: [{ id: 'user' }],
            events: {
                'Item:onSelect': function(event) {
                    var item = event.getData().item;
                    if (!item) return;
                    var label = item.getTitle() + ' (' + item.getId() + ')';
                    var input = document.getElementById('ar-user-input');
                    if (input) {
                        input.value = label;
                    }
                }
            }
        });
        dialog.show();
    };

    window.arApplySettings = function() {
        arCaptureInitial();
        if (BX && BX.UI && BX.UI.Notification && BX.UI.Notification.Center) {
            BX.UI.Notification.Center.notify({
                content: 'Сохранено',
                autoHideDelay: 800
            });
        }
    };

    window.arCancelSettings = function() {
        var normNew = document.querySelector('[data-setting-key="norm_new"]');
        var normOther = document.querySelector('[data-setting-key="norm_other"]');
        if (normNew) normNew.value = arInitialSettings.norms.norm_new || '';
        if (normOther) normOther.value = arInitialSettings.norms.norm_other || '';

        var list = document.getElementById('ar-user-list');
        if (list) {
            list.innerHTML = '';
            (arInitialSettings.users || []).forEach(function(val) {
                var pill = document.createElement('div');
                pill.className = 'ar-pill';
                pill.textContent = val;
                pill.dataset.user = val;
                var btn = document.createElement('button');
                btn.type = 'button';
                btn.textContent = 'x';
                btn.onclick = function(){ pill.remove(); };
                pill.appendChild(btn);
                list.appendChild(pill);
            });
        }
    };
});
</script>
