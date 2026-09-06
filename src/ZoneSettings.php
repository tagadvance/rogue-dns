<?php

namespace tagadvance\roguedns;

use Cloudflare\API\Adapter\Adapter;

class ZoneSettings extends \Cloudflare\API\Endpoints\ZoneSettings
{
	private Adapter $adapter;

	public function __construct(Adapter $adapter)
	{
		parent::__construct($adapter);
		$this->adapter = $adapter;
	}

	/**
	 * @param $zoneID
	 * @return string|bool off or on. Returns <code>false</code> if an error occurred.
	 */
	public function getBrotliSetting($zoneID)
	{
		$return = $this->adapter->get(
			'zones/' . $zoneID . '/settings/brotli'
		);
		$body = json_decode($return->getBody());

		if ($body->success) {
			return $body->result->value;
		}

		return false;
	}

	/**
	 * When the client requesting an asset supports the brotli compression algorithm, Cloudflare will serve a brotli compressed version of the asset.
	 * @param $zoneID
	 * @param $value string default value: off; valid values: off, on
	 * @return bool
	 */
	public function updateBrotliSetting($zoneID, string $value = 'off')
	{
		$return = $this->adapter->patch(
			'zones/' . $zoneID . '/settings/brotli',
			[
				'value' => $value,
			]
		);
		$body = json_decode($return->getBody());

		if ($body->success) {
			return true;
		}

		return false;
	}
}
