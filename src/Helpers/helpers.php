<?php

if (!function_exists('setting')) {
    function setting($key, $default = null)
    {
        return TCG\Voyager\Facades\Voyager::setting($key, $default);
    }
}

if (!function_exists('menu')) {
    function menu($menuName, $type = null, array $options = [])
    {
        return TCG\Voyager\Facades\Voyager::model('Menu')->display($menuName, $type, $options);
    }
}

if (!function_exists('voyager_asset')) {
    function voyager_asset($path, $secure = null)
    {
        return route('voyager.voyager_assets').'?path='.urlencode($path);
    }
}

if (!function_exists('get_file_name')) {
    function get_file_name($name)
    {
        preg_match('/(_)([0-9])+$/', $name, $matches);
        if (count($matches) == 3) {
            return Illuminate\Support\Str::replaceLast($matches[0], '', $name).'_'.(intval($matches[2]) + 1);
        } else {
            return $name.'_1';
        }
    }
}

if (!function_exists('voyager_carbon_format')) {
    /**
     * Format a Carbon instance using a strftime-style format string.
     * Carbon 3.x removed formatLocalized(); this helper converts strftime
     * format codes to PHP date() format codes as a compatibility shim.
     */
    function voyager_carbon_format(\Carbon\Carbon $date, string $format): string
    {
        $map = [
            '%A' => 'l',   '%a' => 'D',
            '%B' => 'F',   '%b' => 'M',
            '%C' => '',    '%c' => 'D M j H:i:s Y',
            '%D' => 'm/d/y',
            '%d' => 'd',
            '%e' => 'j',
            '%F' => 'Y-m-d',
            '%G' => 'o',   '%g' => '',
            '%H' => 'H',   '%h' => 'M',
            '%I' => 'h',
            '%j' => 'z',
            '%k' => 'G',
            '%l' => 'g',
            '%M' => 'i',
            '%m' => 'm',
            '%n' => "\n",
            '%P' => 'a',   '%p' => 'A',
            '%R' => 'H:i',
            '%r' => 'h:i:s A',
            '%S' => 's',
            '%s' => 'U',
            '%T' => 'H:i:s',
            '%t' => "\t",
            '%U' => 'W',
            '%u' => 'N',
            '%V' => 'W',
            '%v' => 'j-M-Y',
            '%W' => 'W',
            '%w' => 'w',
            '%X' => 'H:i:s',
            '%x' => 'm/d/y',
            '%Y' => 'Y',   '%y' => 'y',
            '%Z' => 'T',   '%z' => 'O',
            '%%' => '%',
        ];

        $phpFormat = strtr($format, $map);
        return $date->format($phpFormat);
    }
}
