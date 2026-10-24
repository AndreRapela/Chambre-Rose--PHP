<?php

declare(strict_types=1);

namespace ChambreRose;

final class AddressSearchRoutes implements RouteHandler
{
    public function __construct(private readonly ApiRequestGuard $guard, private readonly AuthRateLimiter $limiter) {}

    public function handle(Request $request): ?Response
    {
        if ($request->method !== 'POST' || $request->path !== '/api/locations/search') return null;
        $this->guard->requireJson($request);
        $input = $request->json();
        $query = is_string($input['query'] ?? null) ? trim($input['query']) : '';
        if (strlen($query) < 3 || strlen($query) > 240) throw new ApiException(400, 'Enter a street or address between 3 and 240 characters.');
        $this->limiter->consumeAddressSearch($request->clientIp);
        $language = ($input['language'] ?? '') === 'fr' ? 'fr' : 'en';
        $endpoint = Config::get('ADDRESS_SEARCH_URL', 'https://photon.komoot.io/api/') ?? '';
        if (!str_starts_with($endpoint, 'https://')) throw new ApiException(503, 'Address search is unavailable.');
        $curl = curl_init(rtrim($endpoint, '?') . '?' . http_build_query(['q' => $query, 'limit' => 8, 'lang' => $language]));
        curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_TIMEOUT => 7,
            CURLOPT_FOLLOWLOCATION => false, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_USERAGENT => 'ChambreRose-AddressSearch/1.0 (https://www.chambre-rose.com)',
            CURLOPT_HTTPHEADER => ['Accept: application/json']]);
        $body = curl_exec($curl);
        $status = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);
        if ($status !== 200 || !is_string($body)) throw new ApiException(503, 'Address search is unavailable.');
        $data = json_decode($body, true);
        if (!is_array($data['features'] ?? null)) throw new ApiException(503, 'Address search is unavailable.');
        return ApiResponder::json(['items' => self::suggestions($data['features'])]);
    }

    /**
     * @param array<array-key, mixed> $features
     * @return list<array<string, string>>
     */
    public static function suggestions(array $features): array
    {
        $items = [];
        foreach (array_slice($features, 0, 20) as $feature) {
            $p = $feature['properties'] ?? [];
            if (!is_array($p)) continue;
            $text = static fn (string $key): string => is_string($p[$key] ?? null) ? trim($p[$key]) : '';
            $city = $text('city') ?: (($text('type') === 'city' || $text('osm_value') === 'town' || $text('osm_value') === 'village') ? $text('name') : '');
            $country = $text('country');
            if ($city === '' || $country === '') continue;
            $street = $text('street') ?: ($text('osm_key') === 'highway' || $text('type') === 'street' ? $text('name') : '');
            $address = trim($street . ($text('housenumber') !== '' ? ', ' . $text('housenumber') : ''), ', ');
            $item = ['address' => $address, 'city' => $city, 'region' => $text('state'), 'country' => $country, 'postalCode' => $text('postcode')];
            $key = json_encode($item);
            $items[$key] = $item;
        }
        return array_slice(array_values($items), 0, 6);
    }
}
