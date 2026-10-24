<?php

declare(strict_types=1);

namespace ChambreRose;

final class LocationNormalizer
{
    /** @var array<string, list<string>> */
    private const COUNTRY_ALIASES = [
        'belgium' => ['belgium', 'belgique', 'belgie', 'belgica'],
        'brazil' => ['brazil', 'brasil', 'bresil'],
        'france' => ['france', 'franca'],
        'germany' => ['germany', 'deutschland', 'allemagne', 'alemanha'],
        'italy' => ['italy', 'italia', 'italie'],
        'netherlands' => ['netherlands', 'nederland', 'pays bas', 'paises baixos'],
        'spain' => ['spain', 'espana', 'espagne'],
        'united kingdom' => ['united kingdom', 'great britain', 'uk', 'u k', 'reino unido', 'royaume uni'],
        'united states' => ['united states', 'united states of america', 'usa', 'u s a', 'estados unidos', 'etats unis'],
    ];

    public static function display(mixed $value): string
    {
        if (!is_scalar($value)) {
            return '';
        }

        return trim(preg_replace('/\s+/u', ' ', (string) $value) ?? (string) $value);
    }

    public static function key(mixed $value, int $maxLength): string
    {
        $value = self::display($value);
        if ($value === '') {
            return '';
        }

        $value = strtr($value, [
            'À' => 'A', 'Á' => 'A', 'Â' => 'A', 'Ã' => 'A', 'Ä' => 'A', 'Å' => 'A',
            'à' => 'a', 'á' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a', 'å' => 'a',
            'Ç' => 'C', 'ç' => 'c',
            'È' => 'E', 'É' => 'E', 'Ê' => 'E', 'Ë' => 'E',
            'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e',
            'Ì' => 'I', 'Í' => 'I', 'Î' => 'I', 'Ï' => 'I',
            'ì' => 'i', 'í' => 'i', 'î' => 'i', 'ï' => 'i',
            'Ñ' => 'N', 'ñ' => 'n',
            'Ò' => 'O', 'Ó' => 'O', 'Ô' => 'O', 'Õ' => 'O', 'Ö' => 'O', 'Ø' => 'O',
            'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o', 'ø' => 'o',
            'Ù' => 'U', 'Ú' => 'U', 'Û' => 'U', 'Ü' => 'U',
            'ù' => 'u', 'ú' => 'u', 'û' => 'u', 'ü' => 'u',
            'Ý' => 'Y', 'Ÿ' => 'Y', 'ý' => 'y', 'ÿ' => 'y',
            'Æ' => 'AE', 'æ' => 'ae', 'Œ' => 'OE', 'œ' => 'oe', 'ß' => 'ss',
            'Ł' => 'L', 'ł' => 'l',
        ]);
        if (class_exists(\Normalizer::class)) {
            $value = \Normalizer::normalize($value, \Normalizer::FORM_D) ?: $value;
            $value = preg_replace('/\p{Mn}+/u', '', $value) ?? $value;
        }
        if (function_exists('iconv')) {
            $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
            if (is_string($ascii) && $ascii !== '') {
                $value = $ascii;
            }
        }

        $value = function_exists('mb_strtolower')
            ? mb_strtolower($value, 'UTF-8')
            : strtolower($value);
        $value = preg_replace('/[^a-z0-9]+/', ' ', $value) ?? $value;
        $value = trim(preg_replace('/\s+/', ' ', $value) ?? $value);

        return function_exists('mb_substr')
            ? mb_substr($value, 0, $maxLength, 'UTF-8')
            : substr($value, 0, $maxLength);
    }

    public static function countryKey(mixed $value): string
    {
        $key = self::key($value, 80);
        foreach (self::COUNTRY_ALIASES as $canonical => $aliases) {
            if (in_array($key, $aliases, true)) {
                return $canonical;
            }
        }

        return $key;
    }

    /** @return list<string> */
    public static function countryKeys(mixed $value): array
    {
        $canonical = self::countryKey($value);

        return self::COUNTRY_ALIASES[$canonical] ?? ($canonical === '' ? [] : [$canonical]);
    }
}
