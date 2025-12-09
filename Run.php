<?php
// Диагностика таблиц b_timeman_entries и b_timeman_work_calendar_exclusion
// Запуск из CLI: php Run.php

use Bitrix\Main\Application;
use Bitrix\Main\Loader;

require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php';

$conn = Application::getConnection();

$tables = [
    'b_timeman_entries',
    'b_timeman_work_calendar_exclusion',
];

foreach ($tables as $table) {
    $exists = $conn->isTableExists($table);
    echo "Таблица: {$table} => " . ($exists ? "есть" : "нет") . "\n";
    if (!$exists) {
        echo "--------------------------\n";
        continue;
    }

    // Колонки
    $cols = [];
    $resCols = $conn->query("SHOW COLUMNS FROM {$table}");
    while ($c = $resCols->fetch()) {
        $cols[] = $c['Field'] . ' (' . $c['Type'] . ')' . ($c['Key'] ? ' [' . $c['Key'] . ']' : '');
    }
    echo "Колонки: " . implode(', ', $cols) . "\n";

    // Первые 10 строк
    $resRows = $conn->query("SELECT * FROM {$table} LIMIT 10");
    $rows = $resRows->fetchAll();
    if (empty($rows)) {
        echo "Данные: нет строк\n";
    } else {
        echo "Данные (до 10 строк):\n";
        foreach ($rows as $r) {
            $pairs = [];
            foreach ($r as $k => $v) {
                if (is_string($v)) {
                    $v = str_replace(["\r", "\n"], ' ', $v);
                }
                $pairs[] = $k . '=' . $v;
            }
            echo "  " . implode('; ', $pairs) . "\n";
        }
    }
    echo "--------------------------\n";
}
?>
