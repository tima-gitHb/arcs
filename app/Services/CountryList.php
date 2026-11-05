<?php

namespace App\Services;

use Locale;

/**
 * The one list of countries (SR-49): the database stores ISO-2 codes, staff only see names.
 * Valid codes come from config/countries.php; names come from intl when it is installed.
 */
class CountryList
{
    /**
     * @return array<string, string> code => English name, sorted by name
     */
    public function all(): array
    {
        $countries = [];

        foreach (array_keys(config('countries')) as $code) {
            $countries[$code] = $this->name($code);
        }

        asort($countries);

        return $countries;
    }

    public function name(string $code): string
    {
        $fallback = config("countries.{$code}", $code);

        return class_exists(Locale::class)
            ? Locale::getDisplayRegion('-'.$code, 'en') ?: $fallback
            : $fallback;
    }

    /**
     * @return list<string>
     */
    public function codes(): array
    {
        return array_keys(config('countries'));
    }
}
