<?php

/**
 * A lower-level class that handles requests to the Snapchat API.
 */
abstract class SnapchatAPI {
	/**
	 * App version.
	 */
	const VERSION = '5.0.1';

	/**
	 * API URL.
	 */
	const URL = 'https://feelinsonice-hrd.appspot.com/bq';

	/**
	 * API secret.
	 */
	const SECRET = 'iEk21fuwZApXlz93750dmW22pw389dPwOk';

	/**
	 * API static token.
	 */
	const STATIC_TOKEN = 'm198sOkJEn37DjqZ32lpRu76xmw288xSQ9';

	/**
	 * Blob encryption key.
	 */
	const BLOB_ENCRYPTION_KEY = 'M02cnQ51Ji97vwT4';

	/**
	 * Hash pattern.
	 */
	const HASH_PATTERN = '0001110111101110001111010101111011010001001110011000110001000110';

	/**
	 * Default curl options.
	 */
	public static $CURL_OPTIONS = array(
		CURLOPT_CONNECTTIMEOUT => 5,
		CURLOPT_RETURNTRANSFER => TRUE,
		CURLOPT_TIMEOUT => 10,
		CURLOPT_USERAGENT => 'Snapchat/5.0.1 CFNetwork/609.1.4 Darwin/13.0.0',
	);

	/**
	 * Returns the current timestamp.
	 *
	 * @return
	 *   The current timestamp, expressed in milliseconds since epoch.
	 */
	public function timestamp() {
		return round(microtime(TRUE) * 1000);
	}

	/**
	 * Pads data using PKCS5.
	 *
	 * @param $data
	 *   The data to be padded.
	 * @param $blocksize
	 *   The block size to pad to. Defaults to 16.
	 *
	 * @return
	 *   The padded data.
	 */
	public function pad($data, $blocksize = 16) {
		$pad = $blocksize - (strlen($data) % $blocksize);
		return $data . str_repeat(chr($pad), $pad);
	}


	/**
	 * Decrypts blob data.
	 *
	 * @param $data
	 *   The data to decrypt.
	 *
	 * @return
	 *   The decrypted data.
	 *
	 * @see SnapchatAPI::encrypt()
	 */
	public function decrypt($data) {
		return mcrypt_decrypt(MCRYPT_RIJNDAEL_128, self::BLOB_ENCRYPTION_KEY, self::pad($data), MCRYPT_MODE_ECB);
	}

	/**
	 * Encrypts blob data.
	 *
	 * @param $data
	 *   The data to encrypt.
	 *
	 * @return
	 *   The encrypted data.
	 *
	 * @see SnapchatAPI::decrypt()
	 */
	public function encrypt($data) {
		return mcrypt_encrypt(MCRYPT_RIJNDAEL_128, self::BLOB_ENCRYPTION_KEY, self::pad($data), MCRYPT_MODE_ECB);
	}

	/**
	 * Implementation of Snapchat's obscure hashing algorithm.
	 *
	 * @param $first
	 *   The first value to use in the hash.
	 * @param $second
	 *   The second value to use in the hash.
	 *
	 * @return
	 *   The hash.
	 */
	public function hash($first, $second) {
		// Append the secret to the values.
		$first = self::SECRET . $first;
		$second = $second . self::SECRET;

		// Hash the values.
		$hash = hash_init('sha256');
		hash_update($hash, $first);
		$hash1 = hash_final($hash);
		$hash = hash_init('sha256');
		hash_update($hash, $second);
		$hash2 = hash_final($hash);

		// Create a new hash with pieces of the two we just made.
		$result = '';
		for ($i = 0; $i < strlen(self::HASH_PATTERN); $i++) {
			$result .= substr(self::HASH_PATTERN, $i, 1) ? $hash2[$i] : $hash1[$i];
		}

		return $result;
	}

	/**
	 * Checks to see if a blob looks like a media file.
	 *
	 * @param $blob
	 *   The blob data (or just the header).
	 *
	 * @return
	 *   TRUE if it's a media file, FALSE if it's not.
	 */
	function is_media($blob) {
		// Check for a JPG header.
		if ($blob[0] == chr(0xFF) && $blob[1] == chr(0xD8)) {
			return TRUE;
		}

		// Check for a MP4 header.
		if ($blob[0] == chr(0x00) && $blob[1] == chr(0x00)) {
			return TRUE;
		}

		return FALSE;
	}

	/**
	 * Runs a POST request against the API.
	 *
	 * Snapchat appears to only use POST for API requests, so this is really
	 * the only function used to query the API.
	 *
	 * @param $endpoint
	 *   The address of the resource being requested (e.g. '/update_snaps' or
	 *   '/friend').
	 * @param $data
	 *   An associative array of values to send to the API. A request token is
	 *   added automatically.
	 * @param $params
	 *   An array containing the parameters used to generate the request token.
	 * @param $multipart
	 *   (optional) If TRUE, sends the request as multipart/form-data. Defaults
	 *   to FALSE.
	 *
	 * @return
	 *   The data returned from the API (decoded if JSON). Returns FALSE if the
	 *   request failed.
	 */
	public function post($endpoint, $data, $params, $multipart = FALSE) {
		$ch = curl_init();

		$data['req_token'] = self::hash($params[0], $params[1]);
		$data['version'] = self::VERSION;

		if (!$multipart) {
			$data = http_build_query($data);
		}

		$options = self::$CURL_OPTIONS + array(
			CURLOPT_POST => TRUE,
			CURLOPT_POSTFIELDS => $data,
			CURLOPT_URL => self::URL . $endpoint,
		);
		curl_setopt_array($ch, $options);

		$result = curl_exec($ch);

		// If the cURL request fails, return FALSE. Also check the status code
		// since the API generally won't return friendly errors.
		if ($result === FALSE || curl_getinfo($ch, CURLINFO_HTTP_CODE) != 200) {
			curl_close($ch);
			return FALSE;
		}

		curl_close($ch);

		$data = json_decode($result);
		return json_last_error() == JSON_ERROR_NONE ? $data : $result;
	}
}