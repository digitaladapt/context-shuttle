<?php

declare(strict_types=1);

namespace App\Tool\Weather;

use InvalidArgumentException;
use RuntimeException;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Weather tool backed by the Open-Meteo API (free, no API key).
 *
 * Accepts either a "lat,lon" string or a free-text place name resolved
 * via Open-Meteo's geocoding API. Returns current conditions plus a
 * multi-day forecast.
 */
final class WeatherTool
{
    private const FORECAST_URL = 'https://api.open-meteo.com/v1/forecast';

    private const GEOCODE_URL = 'https://geocoding-api.open-meteo.com/v1/search';

    private const CURRENT_FIELDS = [
        'temperature_2m', 'relative_humidity_2m', 'apparent_temperature',
        'is_day', 'precipitation', 'weather_code', 'wind_speed_10m',
        'wind_direction_10m', 'wind_gusts_10m',
    ];

    private const DAILY_FIELDS = [
        'weather_code', 'temperature_2m_max', 'temperature_2m_min',
        'precipitation_sum', 'precipitation_probability_max',
        'wind_speed_10m_max', 'sunrise', 'sunset', 'uv_index_max',
    ];

    public function __construct(
        private HttpClientInterface $httpClient,
    ) {
    }

    /**
     * @param string      $location      either "lat,lon" or a place name ("Reykjavik")
     * @param int|null    $forecast_days number of forecast days, 1-7 (default 3)
     * @param string|null $units         "metric" or "imperial" (default metric)
     *
     * @return array<string, mixed>
     */
    public function getWeather(string $location, ?int $forecast_days = null, ?string $units = null): array
    {
        $units = ('imperial' === $units) ? 'imperial' : 'metric';
        $days = $this->clampDays($forecast_days);

        [$lat, $lon, $label] = $this->resolveLocation($location);

        $response = $this->httpClient->request('GET', self::FORECAST_URL, [
            'query' => [
                'latitude' => $lat,
                'longitude' => $lon,
                'current' => implode(',', self::CURRENT_FIELDS),
                'daily' => implode(',', self::DAILY_FIELDS),
                'forecast_days' => $days,
                'timezone' => 'auto',
                ...$this->unitsParams($units),
            ],
            'timeout' => 10,
        ]);

        if ($response->getStatusCode() >= 400) {
            throw new RuntimeException(\sprintf('Open-Meteo returned HTTP %d for %s.', $response->getStatusCode(), $label));
        }

        $data = $response->toArray();

        return $this->format($data, $label, $lat, $lon, $units, $days);
    }

    /**
     * @return array{0: float, 1: float, 2: string}
     */
    private function resolveLocation(string $location): array
    {
        $location = trim($location);

        if ('' === $location) {
            throw new InvalidArgumentException('Location must not be empty.');
        }

        if (preg_match('/^(-?\d{1,2}(?:\.\d+)?)\s*,\s*(-?\d{1,3}(?:\.\d+)?)$/', $location, $m)) {
            $lat = (float) $m[1];
            $lon = (float) $m[2];

            if ($lat < -90 || $lat > 90 || $lon < -180 || $lon > 180) {
                throw new InvalidArgumentException(\sprintf('Coordinates %s are out of range.', $location));
            }

            return [$lat, $lon, \sprintf('%.2f,%.2f', $lat, $lon)];
        }

        return $this->geocode($location);
    }

    /**
     * @return array{0: float, 1: float, 2: string}
     */
    private function geocode(string $place): array
    {
        $response = $this->httpClient->request('GET', self::GEOCODE_URL, [
            'query' => [
                'name' => $place,
                'count' => 1,
                'language' => 'en',
                'format' => 'json',
            ],
            'timeout' => 10,
        ]);

        if ($response->getStatusCode() >= 400) {
            throw new RuntimeException(\sprintf('Geocoding failed for "%s" (HTTP %d).', $place, $response->getStatusCode()));
        }

        $results = $response->toArray()['results'] ?? [];

        if ([] === $results || !isset($results[0]['latitude'], $results[0]['longitude'])) {
            throw new InvalidArgumentException(\sprintf('Could not find a place named "%s". Try "lat,lon" coordinates instead.', $place));
        }

        $first = $results[0];
        $label = $first['name'] ?? $place;
        if (isset($first['country'])) {
            $label .= ', '.$first['country'];
        }

        return [(float) $first['latitude'], (float) $first['longitude'], $label];
    }

    /**
     * @return array<string, mixed>
     */
    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private function format(array $data, string $label, float $lat, float $lon, string $units, int $days): array
    {
        $current = $data['current'] ?? [];
        $daily = $data['daily'] ?? [];

        $tempUnit = ('imperial' === $units) ? '°F' : '°C';
        $windUnit = ('imperial' === $units) ? 'mph' : 'km/h';
        $precipUnit = ('imperial' === $units) ? 'in' : 'mm';

        $forecast = [];
        $nDays = min($days, \count($daily['time'] ?? []));
        for ($i = 0; $i < $nDays; ++$i) {
            $forecast[] = [
                'date' => $daily['time'][$i] ?? null,
                'summary' => $this->weatherCodeDescription($daily['weather_code'][$i] ?? null),
                'weather_code' => $daily['weather_code'][$i] ?? null,
                'temperature_max' => $this->withUnit($daily['temperature_2m_max'][$i] ?? null, $tempUnit),
                'temperature_min' => $this->withUnit($daily['temperature_2m_min'][$i] ?? null, $tempUnit),
                'precipitation_sum' => $this->withUnit($daily['precipitation_sum'][$i] ?? null, $precipUnit),
                'precipitation_probability' => $this->withUnit($daily['precipitation_probability_max'][$i] ?? null, '%'),
                'wind_max' => $this->withUnit($daily['wind_speed_10m_max'][$i] ?? null, $windUnit),
                'uv_index' => $daily['uv_index_max'][$i] ?? null,
                'sunrise' => $daily['sunrise'][$i] ?? null,
                'sunset' => $daily['sunset'][$i] ?? null,
            ];
        }

        return [
            'location' => [
                'label' => $label,
                'latitude' => $lat,
                'longitude' => $lon,
            ],
            'units' => $units,
            'current' => [
                'time' => $current['time'] ?? null,
                'summary' => $this->weatherCodeDescription($current['weather_code'] ?? null),
                'temperature' => $this->withUnit($current['temperature_2m'] ?? null, $tempUnit),
                'apparent_temperature' => $this->withUnit($current['apparent_temperature'] ?? null, $tempUnit),
                'humidity' => $this->withUnit($current['relative_humidity_2m'] ?? null, '%'),
                'is_day' => ($current['is_day'] ?? null) === 1,
                'precipitation' => $this->withUnit($current['precipitation'] ?? null, $precipUnit),
                'wind_speed' => $this->withUnit($current['wind_speed_10m'] ?? null, $windUnit),
                'wind_direction' => $this->withUnit($current['wind_direction_10m'] ?? null, '°'),
                'wind_gusts' => $this->withUnit($current['wind_gusts_10m'] ?? null, $windUnit),
            ],
            'forecast' => $forecast,
            'timezone' => $data['timezone'] ?? null,
            'source' => 'Open-Meteo',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function unitsParams(string $units): array
    {
        if ('imperial' === $units) {
            return [
                'temperature_unit' => 'fahrenheit',
                'wind_speed_unit' => 'mph',
                'precipitation_unit' => 'inch',
            ];
        }

        return [
            'temperature_unit' => 'celsius',
            'wind_speed_unit' => 'kmh',
            'precipitation_unit' => 'mm',
        ];
    }

    private function clampDays(?int $days): int
    {
        if (null === $days || $days < 1) {
            return 3;
        }

        return min($days, 7);
    }

    private function withUnit(mixed $value, string $unit): ?string
    {
        if (null === $value) {
            return null;
        }
        if (is_numeric($value)) {
            $formatted = round((float) $value, 1);

            return "{$formatted}{$unit}";
        }

        return "{$value}{$unit}";
    }

    private function weatherCodeDescription(?int $code): ?string
    {
        if (null === $code) {
            return null;
        }

        $descriptions = [
            0 => 'Clear sky',
            1 => 'Mainly clear',
            2 => 'Partly cloudy',
            3 => 'Overcast',
            45 => 'Fog',
            48 => 'Depositing rime fog',
            51 => 'Light drizzle',
            53 => 'Moderate drizzle',
            55 => 'Dense drizzle',
            56 => 'Light freezing drizzle',
            57 => 'Dense freezing drizzle',
            61 => 'Slight rain',
            63 => 'Moderate rain',
            65 => 'Heavy rain',
            66 => 'Light freezing rain',
            67 => 'Heavy freezing rain',
            71 => 'Slight snowfall',
            73 => 'Moderate snowfall',
            75 => 'Heavy snowfall',
            77 => 'Snow grains',
            80 => 'Slight rain showers',
            81 => 'Moderate rain showers',
            82 => 'Violent rain showers',
            85 => 'Slight snow showers',
            86 => 'Heavy snow showers',
            95 => 'Thunderstorm',
            96 => 'Thunderstorm with slight hail',
            99 => 'Thunderstorm with heavy hail',
        ];

        return $descriptions[$code] ?? "Unknown weather code {$code}";
    }
}
