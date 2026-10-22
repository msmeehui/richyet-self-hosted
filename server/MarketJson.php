<?php
declare(strict_types=1);
namespace RichYet;

/** Provider numbers remain decimal text; PHP floats never touch money. */
final class MarketJson
{
    public static function decode(string $json): mixed
    {
        $quoted = preg_replace_callback('/"(?:\\\\.|[^"\\\\])*"|-?(?:0|[1-9][0-9]*)(?:\.[0-9]+)?(?:[eE][+-]?[0-9]+)?/s',
            static fn(array $m): string => $m[0][0] === '"' ? $m[0] : '"' . $m[0] . '"', $json);
        if ($quoted === null) throw new \RuntimeException('Invalid provider JSON.');
        return json_decode($quoted, true, 64, JSON_THROW_ON_ERROR);
    }

    public static function decimal(mixed $value): ?string
    {
        if (!is_string($value) || strlen($value) > 80 || !preg_match('/^(0|[1-9][0-9]*)(?:\.([0-9]+))?(?:[eE]([+-]?[0-9]{1,2}))?$/D', $value, $m)) return null;
        $whole = $m[1]; $fraction = $m[2] ?? ''; $exponent = (int) ($m[3] ?? 0);
        $digits = $whole . $fraction; $point = strlen($whole) + $exponent;
        if ($point > 24 || strlen($digits) - $point > 24) return null;
        if ($point <= 0) $result = '0.' . str_repeat('0', -$point) . $digits;
        elseif ($point >= strlen($digits)) $result = $digits . str_repeat('0', $point - strlen($digits));
        else $result = substr($digits, 0, $point) . '.' . substr($digits, $point);
        $parts = explode('.', $result);
        $result = ltrim($parts[0], '0') ?: '0';
        if (isset($parts[1]) && rtrim($parts[1], '0') !== '') $result .= '.' . rtrim($parts[1], '0');
        return preg_match('/[1-9]/', $result) ? $result : null;
    }

    public static function date(mixed $value):bool
    {
        if(!is_string($value)||!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D',$value,$m))return false;
        return checkdate((int)$m[2],(int)$m[3],(int)$m[1]);
    }

    public static function instant(mixed $value): ?int
    {
        if (!is_string($value)) return null;
        if (preg_match('/^[0-9]{13}$/D', $value)) return intdiv((int) $value, 1000);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})$/D', $value)) return null;
        try { $date=new \DateTimeImmutable($value); $errors=\DateTimeImmutable::getLastErrors(); if($errors!==false && ($errors['warning_count']||$errors['error_count']))return null; return $date->getTimestamp(); } catch (\Throwable) { return null; }
    }
}
