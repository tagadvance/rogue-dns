<?php

declare(strict_types=1);

namespace tagadvance\roguedns;

use Cloudflare\API\Adapter\Adapter;
use Cloudflare\API\Endpoints\ZoneSettings as BaseZoneSettings;
use Psr\Http\Message\ResponseInterface;
use stdClass;

/**
 * Adds the Brotli setting, which the SDK never covered.
 *
 * Cloudflare deprecated this toggle in August 2024 and now enables Brotli unconditionally, so
 * these calls may be no-ops or may fail outright against the current API. They are kept because
 * confirming which requires a live token.
 */
class ZoneSettings extends BaseZoneSettings
{
    private Adapter $adapter;

    public function __construct(Adapter $adapter)
    {
        parent::__construct($adapter);
        $this->adapter = $adapter;
    }

    /**
     * @return string|null 'off' or 'on', or null if the setting could not be read
     */
    public function getBrotliSetting(string $zoneID): ?string
    {
        $body = $this->decode($this->adapter->get("zones/$zoneID/settings/brotli"));
        if ($body === null || Field::bool($body, 'success', false) !== true) {
            return null;
        }

        return Field::nullableString(Field::object($body, 'result'), 'value');
    }

    /**
     * Serves a Brotli-compressed asset to clients that advertise support for it.
     *
     * @param string $value 'off' or 'on'
     * @return bool whether the API reported success
     */
    public function updateBrotliSetting(string $zoneID, string $value = 'off'): bool
    {
        $body = $this->decode($this->adapter->patch("zones/$zoneID/settings/brotli", ['value' => $value]));

        return $body !== null && Field::bool($body, 'success', false);
    }

    /**
     * Returns null rather than throwing when the body is not a JSON object, which is what a
     * captive portal or an interception proxy sends back with a 200.
     */
    private function decode(ResponseInterface $response): ?stdClass
    {
        $decoded = json_decode((string) $response->getBody());

        return $decoded instanceof stdClass ? $decoded : null;
    }
}
