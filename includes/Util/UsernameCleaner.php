<?php
/**
 * Helpers to clean a QQ nickname into a candidate MediaWiki username.
 *
 * MediaWiki username rules (per UserNameUtils / Manual:Username rules):
 *   - Maximum length 255 characters (with some bytes).
 *   - Cannot contain: '/', ':', '@', '<', '>', '|', '[', ']', '#, '{', '}'.
 *   - Cannot start with a space or contain consecutive spaces, or end with
 *     a space.
 *   - Cannot be purely numeric.
 *   - Cannot begin with a lowercase letter (MW will capitalize).
 *   - Underscores are converted to spaces.
 *   - Certain IP-like forms are disallowed.
 *   - Certain reserved names disallowed.
 *
 * Rather than re-implementing the full validation, we sanitize the nickname
 * and then rely on UserNameUtils::getCanonical() in the caller to do the
 * authoritative validation/canonicalization.
 */

namespace MediaWiki\Extension\QQConnect\Util;

class UsernameCleaner {

	/**
	 * Characters MediaWiki disallows in usernames.
	 */
	private const FORBIDDEN_CHARS = [
		'/', ':', '@', '<', '>', '|', '[', ']', '#', '{', '}', "\t", "\n",
	];

	/**
	 * Produce a candidate username from a QQ nickname.
	 *
	 * @param string $nickname
	 * @return string A cleaned nickname (may still be invalid if empty);
	 *    callers should fall back to a generated name if this is empty.
	 */
	public static function clean( string $nickname ): string {
		// Normalize spaces (underscore -> space, collapse runs of whitespace).
		$name = str_replace( '_', ' ', $nickname );

		// Remove forbidden characters.
		$name = str_replace( self::FORBIDDEN_CHARS, '', $name );

		// Collapse multiple spaces into one.
		$name = preg_replace( '/\s+/u', ' ', $name );

		// Trim leading/trailing whitespace.
		$name = trim( $name );

		// Enforce a reasonable max length (255 bytes is the DB limit).
		if ( mb_strlen( $name ) > 255 ) {
			$name = mb_substr( $name, 0, 255 );
			$name = trim( $name );
		}

		return $name;
	}

	/**
	 * Generate a fallback username based on a QQ openid, used when the cleaned
	 * nickname is empty or invalid.
	 *
	 * @param string $openid
	 * @return string e.g. "QQUser_ab12cd34"
	 */
	public static function generateFromOpenid( string $openid ): string {
		$suffix = substr( md5( $openid ), 0, 8 );
		return 'QQUser_' . $suffix;
	}
}
