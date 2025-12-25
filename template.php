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
$workStartVal = htmlspecialcharsbx((string)($settings['work_start'] ?? '09:00'));
$workEndVal = htmlspecialcharsbx((string)($settings['work_end'] ?? '18:00'));
$usersList = $settings['users'] ?? [];
$userNames = $settings['user_names'] ?? [];
$cacheInfo = htmlspecialcharsbx($settings['cache_info'] ?? 'Cache: 300 seconds; directories /custom/antirating/leads and /custom/antirating/contacts');
$leadLimitVal = htmlspecialcharsbx((string)($settings['lead_limit'] ?? '20000'));
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
        <span class="ar-section-title">РћРїРёСЃР°РЅРёРµ РѕС‚С‡С‘С‚Р°</span>
        <span class="ar-muted">РЅР°Р¶РјРёС‚Рµ, С‡С‚РѕР±С‹ СЂР°СЃРєСЂС‹С‚СЊ</span>
    </div>
    <div class="ar-settings__content" id="ar-desc-box">
        <?php if ($readmeText !== ''): ?>
            <div style="white-space:pre-line;"><?= $readmeText ?></div>
        <?php else: ?>
            <div class="ar-muted">Р—Р°РіСЂСѓР·РёС‚Рµ READ ME.txt РІ РєР°С‚Р°Р»РѕРі РєРѕРјРїРѕРЅРµРЅС‚Р°.</div>
        <?php endif; ?>
    </div>
</div>

<?php if ($USER->IsAdmin()): ?>
    <div class="ar-settings" id="ar-settings">
        <div class="ar-settings__header" onclick="(function(box){ box.style.display = box.style.display === 'block' ? 'none' : 'block';})(document.getElementById('ar-settings-box'))">
            <span class="ar-section-title">РќР°СЃС‚СЂРѕР№РєРё (РґР»СЏ Р°РґРјРёРЅРёСЃС‚СЂР°С‚РѕСЂРѕРІ)</span>
            <span class="ar-muted">РЅР°Р¶РјРёС‚Рµ, С‡С‚РѕР±С‹ СЂР°СЃРєСЂС‹С‚СЊ</span>
        </div>
        <div class="ar-settings__content" id="ar-settings-box">
            <div class="ar-settings__block">
                <h4>РќР°СЃС‚СЂРѕР№РєР° РЅРѕСЂРјР°С‚РёРІРѕРІ РїРѕ СЌС‚Р°РїР°Рј</h4>
                <div class="ar-settings__row">
                    <label style="min-width:90px;">NEW:</label>
                    <input type="number" class="ar-input" data-setting-key="norm_new" value="<?= $normNewVal ?>" min="0" step="0.1">
                </div>
                <div class="ar-settings__row">
                    <label style="min-width:90px;">РћСЃС‚Р°Р»СЊРЅС‹Рµ СЌС‚Р°РїС‹:</label>
                    <input type="number" class="ar-input" data-setting-key="norm_other" value="<?= $normOtherVal ?>" min="0" step="0.1">
                </div>
                <div class="ar-settings__row">
                    <label style="min-width:90px;">Начало дня:</label>
                    <input type="text" class="ar-input" data-setting-key="work_start" value="<?= $workStartVal ?>">
                </div>
                <div class="ar-settings__row">
                    <label style="min-width:90px;">Конец дня:</label>
                    <input type="text" class="ar-input" data-setting-key="work_end" value="<?= $workEndVal ?>">
                </div>
            </div>
            <div class="ar-settings__block">
                <h4>РћРіСЂР°РЅРёС‡РµРЅРёРµ РїРѕ РєРѕР»РёС‡РµСЃС‚РІСѓ Р»РёРґРѕРІ</h4>
                <div class="ar-settings__row">
                    <label style="min-width:90px;">Р›РёРјРёС‚:</label>
                    <input type="number" class="ar-input" data-setting-key="lead_limit" value="<?= $leadLimitVal ?>" min="1" step="1">
                </div>
            </div>
            <div class="ar-settings__block">
                <h4>РџРѕР»СЊР·РѕРІР°С‚РµР»Рё</h4>
                <div class="ar-flex" style="margin-bottom:8px;">
                    <input type="text" id="ar-user-input" class="ar-input" placeholder="Р’РІРµРґРёС‚Рµ РёРјСЏ РёР»Рё ID" onclick="arOpenUserSelector()" readonly>
                    <button type="button" class="ar-button" onclick="arAddUser()">Р”РѕР±Р°РІРёС‚СЊ</button>
                </div>
                <div style="margin-top:10px; font-weight:600;">РџРѕР»СЊР·РѕРІР°С‚РµР»Рё, РїРѕ РєРѕС‚РѕСЂС‹Рј РІС‹РІРѕРґРёС‚СЃСЏ РѕС‚С‡С‘С‚:</div>
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
                <button type="button" class="ar-button" onclick="arApplySettings()">РџСЂРёРјРµРЅРёС‚СЊ</button>
                <button type="button" class="ar-button ar-button--ghost" onclick="arCancelSettings()">РћС‚РјРµРЅР°</button>
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
    <input type="hidden" name="SETTINGS_LEAD_LIMIT" id="settings-lead-limit" value="<?= $leadLimitVal ?>">
    <input type="hidden" name="SETTINGS_USERS" id="settings-users" value="<?= htmlspecialcharsbx(implode(',', $usersList)) ?>">
    <input type="hidden" name="SETTINGS_WORK_START" id="settings-work-start" value="<?= $workStartVal ?>">
    <input type="hidden" name="SETTINGS_WORK_END" id="settings-work-end" value="<?= $workEndVal ?>">
    <input type="hidden" name="SAVE_SETTINGS" id="save-settings" value="">
    <input type="hidden" name="FILTER_APPLY" id="filter-apply" value="">
    <input type="hidden" name="DOWNLOAD_CSV" id="download-csv" value="">
    <div>
        <label style="display:block; margin-bottom:4px;">Р”Р°С‚Р° СЃРѕР·РґР°РЅРёСЏ РѕС‚</label>
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
        <label style="display:block; margin-bottom:4px;">Р”Р°С‚Р° СЃРѕР·РґР°РЅРёСЏ РґРѕ</label>
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
        <button type="submit" class="ar-button" onclick="document.getElementById('filter-apply').value='Y'">РџРѕРєР°Р·Р°С‚СЊ</button>
    </div>
</form>

<?php if (!empty($errors)): ?>
    <div style="margin-bottom:12px; padding:10px; border:1px solid #f5c6cb; background:#f8d7da; color:#721c24;">
        <?php foreach ($errors as $err): ?>
            <div><?= htmlspecialcharsbx($err) ?></div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<h3>Р›РёРґС‹</h3>
<?php
$leadManagers = array_keys($arResult['data'] ?? []);
usort($leadManagers, function($a, $b) use ($leadScoreTotals) {
    $sa = $leadScoreTotals[$a] ?? 0;
    $sb = $leadScoreTotals[$b] ?? 0;
    if ($sa == $sb) return 0;
    return ($sa > $sb) ? -1 : 1;
});
?>
<table class="ar-table">
    <thead>
        <tr>
            <th rowspan="2">РћС‚РІРµС‚СЃС‚РІРµРЅРЅС‹Р№</th>
            <th rowspan="2">Р’СЃРµРіРѕ Р»РёРґРѕРІ</th>
            <th rowspan="2">Р’СЃРµРіРѕ Р±Р°Р»Р»РѕРІ</th>
            <th colspan="2">Р’СЂРµРјСЏ РґРѕ Р·Р°РєСЂС‹С‚РёСЏ, РґРЅРё</th>
            <?php foreach ($arResult['stages'] as $stageCode): ?>
                <th colspan="3"><?= htmlspecialchars($arResult['statusMap'][$stageCode] ?? $stageCode) ?></th>
            <?php endforeach; ?>
        </tr>
        <tr>
            <th>Р’СЂРµРјСЏ, РґРЅРё</th>
            <th>Р‘Р°Р»Р»</th>
            <?php foreach ($arResult['stages'] as $stageCode): ?>
                <th>РљРѕР»РёС‡РµСЃС‚РІРѕ</th>
                <th>Р’СЂРµРјСЏ (РґРЅРё)</th>
                <th>Р‘Р°Р»Р»</th>
            <?php endforeach; ?>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($leadManagers as $managerName): ?>
            <?php $stagesData = $arResult['data'][$managerName] ?? []; ?>
            <?php
                $totalScore = $leadScoreTotals[$managerName] ?? 0;
                $rowStyle = '';
                if ($totalScore > 0) {
                    $rowStyle = 'background:#ffecec;';
                } elseif ($totalScore === 0 || $totalScore === null) {
                    $rowStyle = 'background:#ecffec;';
                }
            ?>
            <tr style="<?= $rowStyle ?>">
                <td><?= htmlspecialchars($managerName) ?></td>
                <td style="text-align:center;"><?= (int)($leadTotals[$managerName] ?? 0) ?></td>
                <td style="text-align:center;"><?= $totalScore !== null ? (int)$totalScore : '-' ?></td>
                <td style="text-align:right; padding-right:8px;">
                    <?php
                    $closure = $arResult['closureStats'][$managerName] ?? null;
                    if ($closure && ($closure['COUNT'] ?? 0) > 0) {
                        $avgDays = ($closure['SUM'] / max(1, $closure['COUNT'])) / ($arResult['settings']['work_day_minutes'] ?? 480);
                        $val = round($avgDays, 2);
                        echo ($val != 0.0) ? $val : '-';
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
                    $avgDaysStage = ($countVal > 0 && $timeVal !== null) ? ($timeVal / $countVal) / ($arResult['settings']['work_day_minutes'] ?? 480) : null;
                    ?>
                    <td style="text-align:center;"><?= $countVal !== 0 ? $countVal : '-' ?></td>
                    <td style="text-align:right; padding-right:8px;"><?= $avgDaysStage !== null && $avgDaysStage != 0.0 ? round($avgDaysStage, 2) : '-' ?></td>
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
    <p style="color:#666;">РџРѕ РІС‹Р±СЂР°РЅРЅС‹Рј РїР°СЂР°РјРµС‚СЂР°Рј РЅРµС‚ РґР°РЅРЅС‹С…. РџРѕРїСЂРѕР±СѓР№С‚Рµ РёР·РјРµРЅРёС‚СЊ С„РёР»СЊС‚СЂ РёР»Рё СЃРїРёСЃРѕРє РїРѕР»СЊР·РѕРІР°С‚РµР»РµР№.</p>
<?php endif; ?>

<div style="margin-top:12px;">
    <?php if ($controlSum !== null): ?>
        <div>РљРѕРЅС‚СЂРѕР»СЊРЅРѕРµ С‡РёСЃР»Рѕ (Р»РёРґС‹): <?= round((float)$controlSum, 4) ?></div>
    <?php endif; ?>
    <?php if ($executionSeconds !== null): ?>
        <div>Р’СЂРµРјСЏ С„РѕСЂРјРёСЂРѕРІР°РЅРёСЏ (СЃРµРє): <?= round((float)$executionSeconds, 4) ?></div>
    <?php endif; ?>
</div>

<?php if ($applyFilter && empty($errors)): ?>
    <div style="margin-top:10px;">
        <button type="button" class="ar-button" onclick="arDownloadCsv()">РЎРєР°С‡Р°С‚СЊ РґРµС‚Р°Р»РёР·Р°С†РёСЋ (CSV)</button>
    </div>
<?php endif; ?>

<h3 style="margin-top:32px;">РљРѕРЅС‚Р°РєС‚С‹</h3>
<?php
$contactRows = $arResult['contactsData'] ?? [];
uksort($contactRows, function($a, $b) use ($arResult) {
    $sa = $arResult['contactsScores'][$a] ?? 0;
    $sb = $arResult['contactsScores'][$b] ?? 0;
    if ($sa == $sb) return 0;
    return ($sa > $sb) ? -1 : 1;
});
?>
<table class="ar-table" style="margin-top:8px; width:auto; min-width:60%;">
    <thead>
        <tr>
            <th>РћС‚РІРµС‚СЃС‚РІРµРЅРЅС‹Р№</th>
            <th>РЎРѕР·РґР°РЅРѕ РІСЃРµРіРѕ</th>
            <th>Р—Р°РїРѕР»РЅРµРЅРѕ РЅРµРїРѕР»РЅРѕС†РµРЅРЅРѕ</th>
            <th>% РЅРµР·Р°РїРѕР»РЅРµРЅРЅС‹С…</th>
            <th>Р‘Р°Р»Р»</th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($contactRows as $managerName => $cData): ?>
            <?php
                $score = $arResult['contactsScores'][$managerName] ?? null;
                $rowStyle = '';
                if ($score > 0) {
                    $rowStyle = 'background:#ffecec;';
                } elseif ($score === 0 || $score === null) {
                    $rowStyle = 'background:#ecffec;';
                }
            ?>
            <tr style="<?= $rowStyle ?>">
                <td><?= htmlspecialchars($managerName) ?></td>
                <td style="text-align:center;"><?= ($cData['TOTAL'] ?? 0) ? (int)$cData['TOTAL'] : '-' ?></td>
                <td style="text-align:center;"><?= ($cData['INCOMPLETE'] ?? 0) ? (int)$cData['INCOMPLETE'] : '-' ?></td>
                <td style="text-align:right; padding-right:8px;">
                    <?php
                    $percent = $cData['PERCENT'] ?? null;
                    echo ($percent !== null && $percent != 0.0) ? round($percent, 2) : '-';
                    ?>
                </td>
                <td style="text-align:center;">
                    <?php
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
        users: [],
        work: {},
        lead_limit: ''
    };

    function arCaptureInitial() {
        var normNew = document.querySelector('[data-setting-key="norm_new"]');
        var normOther = document.querySelector('[data-setting-key="norm_other"]');
        var workStart = document.querySelector('[data-setting-key=\"work_start\"]');
        var workEnd = document.querySelector('[data-setting-key=\"work_end\"]');
        var leadLimit = document.querySelector('[data-setting-key=\"lead_limit\"]');
        arInitialSettings.norms = {
            norm_new: normNew ? normNew.value : '',
            norm_other: normOther ? normOther.value : ''
        };
        arInitialSettings.work = {
            work_start: workStart ? workStart.value : '',
            work_end: workEnd ? workEnd.value : ''
        };
        arInitialSettings.lead_limit = leadLimit ? leadLimit.value : '';
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
        var leadLimit = document.querySelector('[data-setting-key="lead_limit"]');
        var workStart = document.querySelector('[data-setting-key="work_start"]');
        var workEnd = document.querySelector('[data-setting-key="work_end"]');
        var inputNormNew = document.getElementById('settings-norm-new');
        var inputNormOther = document.getElementById('settings-norm-other');
        var inputLeadLimit = document.getElementById('settings-lead-limit');
        var inputWorkStart = document.getElementById('settings-work-start');
        var inputWorkEnd = document.getElementById('settings-work-end');
        if (inputNormNew && normNew) inputNormNew.value = normNew.value;
        if (inputNormOther && normOther) inputNormOther.value = normOther.value;
        if (inputLeadLimit && leadLimit) inputLeadLimit.value = leadLimit.value;
        if (inputWorkStart && workStart) inputWorkStart.value = workStart.value;
        if (inputWorkEnd && workEnd) inputWorkEnd.value = workEnd.value;

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
                content: 'РЎРѕС…СЂР°РЅРµРЅРѕ',
                autoHideDelay: 800
            });
        }
    };

    window.arCancelSettings = function() {
        var normNew = document.querySelector('[data-setting-key="norm_new"]');
        var normOther = document.querySelector('[data-setting-key="norm_other"]');
        var workStart = document.querySelector('[data-setting-key="work_start"]');
        var workEnd = document.querySelector('[data-setting-key="work_end"]');
        var leadLimit = document.querySelector('[data-setting-key="lead_limit"]');
        if (normNew) normNew.value = arInitialSettings.norms.norm_new || '';
        if (normOther) normOther.value = arInitialSettings.norms.norm_other || '';
        if (workStart) workStart.value = arInitialSettings.work.work_start || '';
        if (workEnd) workEnd.value = arInitialSettings.work.work_end || '';
        if (leadLimit) leadLimit.value = arInitialSettings.lead_limit || '';
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

