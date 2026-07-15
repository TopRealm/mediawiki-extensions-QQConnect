<?php
/**
 * QQ Connect (QQ互联) OAuth2 HTTP client.
 *
 * Implements the server-side Authorization Code flow against the QQ Connect
 * platform. Endpoint details (verified against https://wiki.connect.qq.com/):
 *
 *   authorize : https://graph.qq.com/oauth2.0/authorize   (GET, returns 302)
 *   token     : https://graph.qq.com/oauth2.0/token       (GET, urlencoded body)
 *   me        : https://graph.qq.com/oauth2.0/me           (GET, JSONP unless fmt=json)
 *   userinfo  : https://graph.qq.com/user/get_user_info    (GET, JSON, uses oauth_consumer_key)
 *
 * Important non-standard quirks handled here:
 *  - The token endpoint returns an application/x-www-form-urlencoded string
 *    (NOT JSON), so it is parsed with parse_str.
 *  - The "me" endpoint returns a JSONP-style "callback({...});" string by
 *    default; we pass fmt=json to get plain JSON.
 *  - The userinfo endpoint parameter for the app id is "oauth_consumer_key",
 *    not "client_id".
 *  - All four endpoints use GET.
 */

namespace MediaWiki\Extension\QQConnect;

use Exception;
use InvalidArgumentException;

class QQClient {

	public const AUTHORIZE_URL = 'https://graph.qq.com/oauth2.0/authorize';
	public const TOKEN_URL = 'https://graph.qq.com/oauth2.0/token';
	public const ME_URL = 'https://graph.qq.com/oauth2.0/me';
	public const USERINFO_URL = 'https://graph.qq.com/user/get_user_info';

	/** @var QQConnectConfig */
	private $config;

	public function __construct( QQConnectConfig $config ) {
		$this->config = $config;
	}

	/**
	 * Build the authorization URL to redirect the user's browser to.
	 *
	 * @param string $redirectUri
	 * @param string $state CSRF state token (stored server-side for verification).
	 * @return string
	 */
	public function getAuthorizeUrl( string $redirectUri, string $state ): string {
		$params = [
			'response_type' => 'code',
			'client_id' => $this->config->getAppId(),
			'redirect_uri' => $redirectUri,
			'scope' => $this->config->getScopes(),
			'state' => $state,
		];
		return self::AUTHORIZE_URL . '?' . http_build_query( $params, '', '&', PHP_QUERY_RFC3986 );
	}

	/**
	 * Generate a cryptographically random state token.
	 *
	 * @return string
	 */
	public function generateState(): string {
		return bin2hex( random_bytes( 16 ) );
	}

	/**
	 * Exchange an authorization code for an access token.
	 *
	 * @param string $code
	 * @param string $redirectUri Must match the one used for authorization.
	 * @return array Associative array with keys: access_token, expires_in,
	 *    refresh_token (refresh_token may be absent for some apps).
	 * @throws QQConnectException on failure.
	 */
	public function exchangeCodeForToken( string $code, string $redirectUri ): array {
		$params = [
			'grant_type' => 'authorization_code',
			'client_id' => $this->config->getAppId(),
			'client_secret' => $this->config->getAppKey(),
			'code' => $code,
			'redirect_uri' => $redirectUri,
		];
		$body = $this->httpGet( self::TOKEN_URL, $params );

		// The token endpoint returns urlencoded data on success, e.g.:
		//   access_token=...&expires_in=7776000&refresh_token=...
		// On error it returns a callback-wrapped JSON. Detect that case.
		if ( preg_match( '/^callback\s*\(/', trim( $body ) ) ) {
			$error = $this->parseCallbackJson( $body, 'token' );
			throw new QQConnectException(
				'token',
				$error['error_description'] ?? ( 'error ' . ( $error['error'] ?? 'unknown' ) )
			);
		}

		$parsed = [];
		parse_str( $body, $parsed );
		if ( empty( $parsed['access_token'] ) ) {
			throw new QQConnectException( 'token', 'no access_token in response' );
		}
		return $parsed;
	}

	/**
	 * Retrieve the QQ identity (openid + unionid) for the given access token.
	 *
	 * Calls /oauth2.0/me?unionid=1 to obtain both identifiers in one request.
	 * UnionID is the cross-app unique identifier and MUST be present for the
	 * extension to work.  If the developer account has not enabled UnionID
	 * access (error 100048), a clear exception is thrown with instructions.
	 *
	 * @param string $accessToken
	 * @return array{openid: string, unionid: string}
	 * @throws QQConnectException on failure.
	 */
	public function fetchIdentity( string $accessToken ): array {
		$params = [
			'access_token' => $accessToken,
			'unionid' => '1',
			'fmt' => 'json',
		];
		$body = $this->httpGet( self::ME_URL, $params );
		$data = $this->parseCallbackJson( $body, 'openid' );

		if ( empty( $data['unionid'] ) ) {
			// Error 100048 = "companyid not set" → unionid not enabled.
			if ( isset( $data['error'] ) && (int)$data['error'] === 100048 ) {
				throw new QQConnectException( 'unionid',
					'UnionID not enabled for this QQ Connect developer account. '
					. 'See https://wiki.connect.qq.com/unionid%E4%BB%8B%E7%BB%8D '
					. 'to apply for UnionID access. '
					. '(error 100048: ' . ( $data['error_description'] ?? 'no description' ) . ')'
				);
			}
			throw new QQConnectException( 'unionid',
				'unionid not returned by /oauth2.0/me. '
				. 'Ensure the developer account has UnionID access enabled.'
			);
		}
		if ( empty( $data['openid'] ) ) {
			throw new QQConnectException( 'unionid', 'no openid in /me response' );
		}

		return [
			'openid' => $data['openid'],
			'unionid' => $data['unionid'],
		];
	}

	/**
	 * Retrieve the QQ user info (nickname, avatar, etc.).
	 *
	 * @param string $accessToken
	 * @param string $openid
	 * @return array User info fields. ret==0 indicates success.
	 * @throws QQConnectException on failure.
	 */
	public function getUserInfo( string $accessToken, string $openid ): array {
		$params = [
			'access_token' => $accessToken,
			'oauth_consumer_key' => $this->config->getAppId(),
			'openid' => $openid,
		];
		$body = $this->httpGet( self::USERINFO_URL, $params );
		$data = json_decode( $body, true );
		if ( !is_array( $data ) ) {
			throw new QQConnectException( 'userinfo', 'invalid JSON response' );
		}
		if ( !isset( $data['ret'] ) || (int)$data['ret'] !== 0 ) {
			$msg = $data['msg'] ?? ( 'ret=' . ( $data['ret'] ?? 'unknown' ) );
			throw new QQConnectException( 'userinfo', $msg );
		}
		return $data;
	}

	/**
	 * Get the best available avatar URL from a userinfo response.
	 *
	 * @param array $userInfo
	 * @return string
	 */
	public static function pickAvatar( array $userInfo ): string {
		foreach ( [ 'figureurl_qq_2', 'figureurl_qq_1', 'figureurl_2', 'figureurl_1', 'figureurl' ] as $key ) {
			if ( !empty( $userInfo[$key] ) ) {
				return (string)$userInfo[$key];
			}
		}
		return '';
	}

	/**
	 * Perform an HTTP GET request and return the response body.
	 *
	 * Uses cURL (ext-curl) for transport. Throws on transport-level failure.
	 *
	 * @param string $url
	 * @param array $params
	 * @return string
	 * @throws QQConnectException
	 */
	private function httpGet( string $url, array $params ): string {
		$fullUrl = $url . '?' . http_build_query( $params, '', '&', PHP_QUERY_RFC3986 );

		$ch = curl_init( $fullUrl );
		if ( $ch === false ) {
			throw new QQConnectException( 'http', 'curl_init failed' );
		}
		curl_setopt_array( $ch, [
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_FOLLOWLOCATION => true,
			CURLOPT_MAXREDIRS => 5,
			CURLOPT_CONNECTTIMEOUT => 10,
			CURLOPT_TIMEOUT => 30,
			CURLOPT_SSL_VERIFYPEER => true,
			CURLOPT_SSL_VERIFYHOST => 2,
			CURLOPT_USERAGENT => 'MediaWiki-QQConnect/1.0',
			CURLOPT_HTTPHEADER => [ 'Accept: application/json, text/plain, */*' ],
		] );

		$body = curl_exec( $ch );
		$error = curl_error( $ch );
		$errno = curl_errno( $ch );
		$status = (int)curl_getinfo( $ch, CURLINFO_HTTP_CODE );
		curl_close( $ch );

		if ( $body === false || $errno !== 0 ) {
			throw new QQConnectException( 'http', "curl error ($errno): $error" );
		}
		if ( $status >= 400 ) {
			throw new QQConnectException( 'http', "HTTP $status" );
		}
		return (string)$body;
	}

	/**
	 * Parse a QQ Connect response body which may be plain JSON or a
	 * JSONP-style "callback({...});" wrapper.
	 *
	 * @param string $body
	 * @param string $stage Stage name for error messages.
	 * @return array
	 * @throws QQConnectException
	 */
	private function parseCallbackJson( string $body, string $stage ): array {
		$trimmed = trim( $body );
		// Strip a possible "callback( ... );" wrapper.
		if ( preg_match( '/^callback\s*\((.*)\)\s*;?\s*$/s', $trimmed, $m ) ) {
			$trimmed = $m[1];
		}
		$data = json_decode( $trimmed, true );
		if ( !is_array( $data ) ) {
			// Do NOT include the raw body in the exception message —
			// it may contain access tokens in urlencoded token responses.
			throw new QQConnectException(
				$stage,
				'unexpected response format (expected JSON, got: '
					. substr( preg_replace( '/\s+/', ' ', $trimmed ), 0, 80 ) . ')'
			);
		}
		return $data;
	}
}
