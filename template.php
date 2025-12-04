<?php if (!defined("B_PROLOG_INCLUDED") || B_PROLOG_INCLUDED !== true) die(); ?>
<?php
global $APPLICATION, $USER;

$readmeTextRaw = $arResult['readmeText'] ?? '';
$readmeText = $readmeTextRaw !== '' ? nl2br(htmlspecialcharsbx($readmeTextRaw)) : '';

$dateFrom = htmlspecialcharsbx($arResult['filterValues']['DATE_FROM'] ?? '');
$dateTo = htmlspecialcharsbx($arResult['filterValues']['DATE_TO'] ?? '');
$scores = $arResult['scores'] ?? [];
$leadTotals = $arResult['leadTotals'] ?? [];
$leadScoreTotals = $arResult['leadScoreTotals'] ?? [];
$generatedAt = $arResult['generatedAt'] ?? '';
$controlSum = $arResult['controlSum'] ?? null;
$executionSeconds = $arResult['executionSeconds'] ?? null;
$settings = $arResult['settings'] ?? [];
$normNewVal = htmlspecialcharsbx((string)($settings['norm_new'] ?? '1'));
$normOtherVal = htmlspecialcharsbx((string)($settings['norm_other'] ?? '5'));
$usersList = $settings['users'] ?? [];
$userNames = $settings['user_names'] ?? [];
$cacheInfo = htmlspecialcharsbx($settings['cache_info'] ?? 'Cache: 300 seconds; directories /custom/antirating/leads and /custom/antirating/contacts');
$errors = $arResult['errors'] ?? [];
$applyFilter = (bool)($arResult['applyFilter'] ?? false);

\Bitrix\Main\UI\Extension::load('ui.entity-selector');
?>

<style>
    .ar-settings { border:1px solid #e0e0e0; border-radius:4px; margin-bottom:16px; background:#fafafa; }
    .ar-settings__header { padding:10px 12px; cursor:pointer; font-weight:600; display:flex; align-items:center; justify-content:space-between; }
    .ar-settings__content { display:none; padding:12px; border-top:1px solid #e0e0e0; }
    .ar-settings__block { margin-bottom:16px; }
    .ar-settings__block h4 { margin:0 0 8px 0; font-size:14px; }
    .ar-settings__row { display:flex; gap:8px; align-items:center; margin-bottom:8px; }
    .ar-settings__list { display:flex; flex-wrap:wrap; gap:6px; margin-top:8px; }
    .ar-pill { background:#eef3ff; border:1px solid #c6d4ff; border-radius:12px; padding:4px 8px; display:inline-flex; align-items:center; gap:6px; font-size:12px; }
    .ar-pill button { border:none; background:transparent; cursor:pointer; color:#888; font-size:12px; line-height:1; }
    .ar-input { padding:6px 8px; border:1px solid #dfe3e8; border-radius:4px; min-width:80px; }
    .ar-button { padding:6px 10px; border:1px solid #2f7be5; background:#2f7be5; color:#fff; border-radius:4px; cursor:pointer; }
    .ar-button--ghost { background:#ccc; border-color:#ccc; color:#000; }
    .ar-muted { color:#888; font-size:12px; }
    .ar-section-title { font-weight:700; margin:0; font-size:15px; }
    .ar-flex { display:flex; gap:8px; align-items:center; }
    .ar-table { border-collapse:collapse; width:100%; margin-top:8px; }
    .ar-table th, .ar-table td { border:1px solid #ccc; padding:6px; }
    .ar-table th { background:#f5f5f5; }
</style>

<div class="ar-settings" id="ar-desc">
    <div class="ar-settings__header" onclick="(function(box){ box.style.display = box.style.display === 'block' ? 'none' : 'block';})(document.getElementById('ar-desc-box'))">
        <span class="ar-section-title">Описание отчёта</span>
        <span class="ar-muted">нажмите, чтобы раскрыть</span>
    </div>
    <div class="ar-settings__content" id="ar-desc-box">
        <?php if ($readmeText !== ''): ?>
            <div style="white-space:pre-line;"><?= $readmeText ?></div>
        <?php else: ?>
            <div class="ar-muted">Загрузите READ ME.txt в каталог компонента.</div>
        <?php endif; ?>
    </div>
</div>

<?php if ($USER->IsAdmin()): ?>
    <div class="ar-settings" id="ar-settings">
        <div class="ar-settings__header" onclick="(function(box){ box.style.display = box.style.display === 'block' ? 'none' : 'block';})(document.getElementById('ar-settings-box'))">
            <span class="ar-section-title">Настройки (для администраторов)</span>
            <span class="ar-muted">нажмите, чтобы раскрыть</span>
        </div>
        <div class="ar-settings__content" id="ar-settings-box">
            <div class="ar-settings__block">
                <h4>Настройка нормативов по этапам</h4>
                <div class="ar-settings__row">
                    <label style="min-width:90px;">NEW:</label>
                    <input type="number" class="ar-input" data-setting-key="norm_new" value="<?= $normNewVal ?>" min="0" step="0.1">
                </div>
                <div class="ar-settings__row">
                    <label style="min-width:90px;">Остальные этапы:</label>
                    <input type="number" class="ar-input" data-setting-key="norm_other" value="<?= $normOtherVal ?>" min="0" step="0.1">
                </div>
            </div>
            <div class="ar-settings__block">
                <h4>Пользователи</h4>
                <div class="ar-flex" style="margin-bottom:8px;">
                    <input type="text" id="ar-user-input" class="ar-input" placeholder="Введите имя или ID" onclick="arOpenUserSelector()" readonly>
                    <button type="button" class="ar-button" onclick="arAddUser()">Добавить</button>
                </div>
                <div style="margin-top:10px; font-weight:600;">Пользователи, по которым выводится отчёт:</div>
                <div class="ar-settings__list" id="ar-user-list">
                    <?php foreach ($usersList as $uId): ?>
                        <?php $label = trim($userNames[$uId] ?? (string)$uId); ?>
                        <div class="ar-pill" data-user="<?= (int)$uId ?>" data-label="<?= htmlspecialcharsbx($label) ?>">
                            <?= htmlspecialcharsbx($label) ?>
                            <button type="button" onclick="this.parentNode.remove()">x</button>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="ar-settings__block" style="display:flex; gap:8px; align-items:center;">
                <button type="button" class="ar-button" onclick="arApplySettings()">Применить</button>
                <button type="button" class="ar-button ar-button--ghost" onclick="arCancelSettings()">Отмена</button>
            </div>
            <div class="ar-muted" style="padding:4px 0 0 0;">
                <?= $cacheInfo ?>
            </div>
        </div>
    </div>
<?php endif; ?>

<form method="get" name="antirating-filter" style="margin-bottom:16px; display:flex; gap:16px; align-items:flex-end; flex-wrap:wrap;">
    <input type="hidden" name="SETTINGS_NORM_NEW" id="settings-norm-new" value="<?= $normNewVal ?>">
    <input type="hidden" name="SETTINGS_NORM_OTHER" id="settings-norm-other" value="<?= $normOtherVal ?>">
    <input type="hidden" name="SETTINGS_USERS" id="settings-users" value="<?= htmlspecialcharsbx(implode(',', $usersList)) ?>">
    <input type="hidden" name="SAVE_SETTINGS" id="save-settings" value="">
    <input type="hidden" name="FILTER_APPLY" id="filter-apply" value="">
    <input type="hidden" name="DOWNLOAD_CSV" id="download-csv" value="">
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
    <div style="align-self:flex-end;">
        <button type="submit" class="ar-button" onclick="document.getElementById('filter-apply').value='Y'">Показать</button>
    </div>
</form>

<?php if (!empty($errors)): ?>
    <div style="margin-bottom:12px; padding:10px; border:1px solid #f5c6cb; background:#f8d7da; color:#721c24;">
        <?php foreach ($errors as $err): ?>
            <div><?= htmlspecialcharsbx($err) ?></div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<h3>Лиды</h3>
<table class="ar-table">
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
        <?php foreach (($arResult['data'] ?? []) as $managerName => $stagesData): ?>
            <tr>
                <td><?= htmlspecialchars($managerName) ?></td>
                <td style="text-align:center;"><?= (int)($leadTotals[$managerName] ?? 0) ?></td>
                <td style="text-align:center;"><?= (int)($leadScoreTotals[$managerName] ?? 0) ?></td>
                <td style="text-align:right; padding-right:8px;">
                    <?php
                    $closure = $arResult['closureStats'][$managerName] ?? null;
                    if ($closure && ($closure['COUNT'] ?? 0) > 0) {
                        $avgDays = ($closure['SUM'] / max(1, $closure['COUNT'])) / 1440;
                        echo round($avgDays, 2);
                    } else {
                        echo '-';
                    }
                    ?>
                </td>
                <td style="text-align:center;">
                    <?php
                    $score = $scores['CLOSURE'][$managerName] ?? null;
                    echo $score !== null ? (int)$score : '-';
                    ?>
                </td>
                <?php foreach ($arResult['stages'] as $stageCode): ?>
                    <?php
                    $countVal = isset($stagesData[$stageCode]['COUNT']) ? (int)$stagesData[$stageCode]['COUNT'] : 0;
                    $timeVal = $stagesData[$stageCode]['TIME'] ?? null;
                    $avgDaysStage = ($countVal > 0 && $timeVal !== null) ? ($timeVal / $countVal) / 1440 : null;
                    ?>
                    <td style="text-align:center;"><?= $countVal ?></td>
                    <td style="text-align:right; padding-right:8px;"><?= $avgDaysStage !== null ? round($avgDaysStage, 2) : '-' ?></td>
                    <td style="text-align:center;">
                        <?php
                        $scoreStage = $scores[$stageCode][$managerName] ?? null;
                        echo $scoreStage !== null ? (int)$scoreStage : '-';
                        ?>
                    </td>
                <?php endforeach; ?>
            </tr>
        <?php endforeach; ?>
    </tbody>
</table>

<?php if ($applyFilter && empty($arResult['data'])): ?>
    <p style="color:#666;">По выбранным параметрам нет данных. Попробуйте изменить фильтр или список пользователей.</p>
<?php endif; ?>

<div style="margin-top:12px;">
    <?php if ($controlSum !== null): ?>
        <div>Контрольное число (лиды): <?= round((float)$controlSum, 4) ?></div>
    <?php endif; ?>
    <?php if ($executionSeconds !== null): ?>
        <div>Время формирования (сек): <?= round((float)$executionSeconds, 4) ?></div>
    <?php endif; ?>
</div>

<?php if ($applyFilter && empty($errors)): ?>
    <div style="margin-top:10px;">
        <button type="button" class="ar-button" onclick="arDownloadCsv()">Скачать детализацию (CSV)</button>
    </div>
<?php endif; ?>

<h3 style="margin-top:32px;">Контакты</h3>
<table class="ar-table" style="margin-top:8px;">
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
                arInitialSettings.users.push({
                    id: pill.dataset.user || '',
                    label: pill.dataset.label || pill.textContent
                });
            });
        }
    }

    function arRenderUsers(users) {
        var list = document.getElementById('ar-user-list');
        if (!list) return;
        list.innerHTML = '';
        (users || []).forEach(function(item) {
            var pill = document.createElement('div');
            pill.className = 'ar-pill';
            pill.dataset.user = item.id;
            pill.dataset.label = item.label;
            pill.textContent = item.label;
            var btn = document.createElement('button');
            btn.type = 'button';
            btn.textContent = 'x';
            btn.onclick = function(){ pill.remove(); };
            pill.appendChild(btn);
            list.appendChild(pill);
        });
    }

    arCaptureInitial();

    window.arAddUser = function() {
        var input = document.getElementById('ar-user-input');
        if (!input) return;
        var val = (input.value || '').trim();
        var id = input.dataset.userId ? input.dataset.userId.trim() : '';
        if (!val) return;
        var list = document.getElementById('ar-user-list');
        if (!list) return;
        var pill = document.createElement('div');
        pill.className = 'ar-pill';
        pill.dataset.user = id || val;
        pill.dataset.label = val;
        pill.textContent = val;
        var btn = document.createElement('button');
        btn.type = 'button';
        btn.textContent = 'x';
        btn.onclick = function(){ pill.remove(); };
        pill.appendChild(btn);
        list.appendChild(pill);
        input.value = '';
        input.dataset.userId = '';
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
                        input.dataset.userId = item.getId();
                    }
                }
            }
        });
        dialog.show();
    };

    function arUpdateHidden() {
        var normNew = document.querySelector('[data-setting-key="norm_new"]');
        var normOther = document.querySelector('[data-setting-key="norm_other"]');
        var inputNormNew = document.getElementById('settings-norm-new');
        var inputNormOther = document.getElementById('settings-norm-other');
        if (inputNormNew && normNew) inputNormNew.value = normNew.value;
        if (inputNormOther && normOther) inputNormOther.value = normOther.value;

        var list = document.getElementById('ar-user-list');
        var ids = [];
        if (list) {
            list.querySelectorAll('.ar-pill').forEach(function(pill) {
                if (pill.dataset.user) {
                    ids.push(pill.dataset.user);
                } else {
                    ids.push(pill.textContent.trim());
                }
            });
        }
        var inputUsers = document.getElementById('settings-users');
        if (inputUsers) {
            inputUsers.value = ids.join(',');
        }
    }

        window.arApplySettings = function() {
        arCaptureInitial();
        arUpdateHidden();
        var saveInput = document.getElementById('save-settings');
        if (saveInput) saveInput.value = 'Y';
        var filterApply = document.getElementById('filter-apply');
        if (filterApply) filterApply.value = '';
        var form = document.forms['antirating-filter'];
        if (form) {
            form.submit();
            return;
        }
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
        arRenderUsers(arInitialSettings.users);
        arUpdateHidden();
        var saveInput = document.getElementById('save-settings');
        if (saveInput) saveInput.value = '';
    };

    window.arDownloadCsv = function() {
        arUpdateHidden();
        var dl = document.getElementById('download-csv');
        var apply = document.getElementById('filter-apply');
        if (dl) dl.value = 'Y';
        if (apply) apply.value = 'Y';
        var form = document.forms['antirating-filter'];
        if (form) {
            form.submit();
        }
        if (dl) dl.value = '';
    };
});
</script>

