
<?php

class DateConverter
{
    public static function convertDbStringToTimestamp(?string $value): ?int
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }
        try {
            return (new \DateTime($value, new \DateTimeZone(date_default_timezone_get())))->getTimestamp();
        } catch (\Exception $e) {
            return null;
        }
    }

    public static function convertBitrixDateToTimestamp($value): ?int
    {
        if ($value instanceof \Bitrix\Main\Type\DateTime) {
            return $value->getTimestamp();
        }
        if ($value instanceof \DateTime) {
            return $value->getTimestamp();
        }
        return null;
    }

    public static function convertUserDateToTimestamp(?string $value): ?int
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }
        try {
            $dt = \Bitrix\Main\Type\DateTime::createFromUserTime($value);
            return $dt instanceof \Bitrix\Main\Type\DateTime ? $dt->getTimestamp() : null;
        } catch (\Throwable $e) {
            return null;
        }
    }
}
