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

}

