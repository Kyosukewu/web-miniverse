<?php

declare(strict_types=1);

namespace App\Helpers;

use Illuminate\Support\Str;

class DashboardHelper
{
    /**
     * Get flag emoji for location string.
     *
     * @param string|null $locationString
     * @return string
     */
    public static function getFlagForLocation(?string $locationString): string
    {
        if (null === $locationString || '' === $locationString) {
            return '';
        }

        $locationLower = Str::lower($locationString);

        if (Str::contains($locationLower, ['美國', 'u.s.', 'usa', '華盛頓'])) {
            return '🇺🇸';
        }

        if (Str::contains($locationLower, ['日本', 'japan', '東京'])) {
            return '🇯🇵';
        }

        if (Str::contains($locationLower, ['中國', 'china', '北京', '上海', '山東'])) {
            return '🇨🇳';
        }

        if (Str::contains($locationLower, ['台灣', 'taiwan', '臺北', '台北'])) {
            return '🇹🇼';
        }

        if (Str::contains($locationLower, ['南非', 'south africa', '約翰尼斯堡'])) {
            return '🇿🇦';
        }

        if (Str::contains($locationLower, ['法國', 'france', '巴黎'])) {
            return '🇫🇷';
        }

        if (Str::contains($locationLower, ['英國', 'u.k.', 'britain'])) {
            return '🇬🇧';
        }

        if (Str::contains($locationLower, ['以色列', 'israel'])) {
            return '🇮🇱';
        }

        if (Str::contains($locationLower, ['加薩', 'gaza'])) {
            return '🇵🇸';
        }

        return '🏳️';
    }

    /**
     * 將 UTC 時間轉換為 UTC+8 (Asia/Taipei) 並格式化顯示
     *
     * @param \DateTime|\Illuminate\Support\Carbon|null $dateTime
     * @param string $format 格式化字串，預設為 'Y-m-d H:i:s'
     * @return string
     */
    public static function formatDateTimeToUtc8($dateTime, string $format = 'Y-m-d H:i:s'): string
    {
        if (null === $dateTime) {
            return 'N/A';
        }

        try {
            // 如果已經是 Carbon 實例，直接使用
            if ($dateTime instanceof \Illuminate\Support\Carbon) {
                return $dateTime->setTimezone('Asia/Taipei')->format($format);
            }

            // 如果是 DateTime 實例，轉換為 Carbon
            if ($dateTime instanceof \DateTime) {
                return \Illuminate\Support\Carbon::instance($dateTime)
                    ->setTimezone('Asia/Taipei')
                    ->format($format);
            }

            // 如果是字串，嘗試解析
            if (is_string($dateTime)) {
                return \Illuminate\Support\Carbon::parse($dateTime, 'UTC')
                    ->setTimezone('Asia/Taipei')
                    ->format($format);
            }

            return 'N/A';
        } catch (\Exception $e) {
            return 'N/A';
        }
    }

}

