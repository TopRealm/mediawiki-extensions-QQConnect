<?php
/**
 * AuthenticationRequest used to continue the login flow after the OAuth
 * redirect round-trip.
 *
 * When the QQ primary provider returns an AuthenticationResponse::newRedirect
 * pointing at Special:QQConnectLogin, core stores the list of "needed
 * requests" to resume the flow. After the special page finishes the OAuth
 * dance (exchanging code for token, fetching openid/userinfo), it triggers
 * continuePrimaryAuthentication by submitting an instance of this class,
 * carrying the resolved local username (or leaving it empty to signal
 * failure / a choice-required state).
 *
 * This mirrors PluggableAuth's ContinueAuthenticationRequest.
 */

namespace MediaWiki\Extension\QQConnect\Auth;

use MediaWiki\Auth\AuthenticationRequest;

class QQContinueAuthenticationRequest extends AuthenticationRequest {

	/** @var string|null Resolved local username on success. */
	public $username = null;

	/**
	 * The continue request needs no user-facing fields; it just resumes the
	 * flow. We provide a single hidden field so loadFromSubmission has
	 * something to latch onto.
	 *
	 * @return array
	 */
	public function getFieldInfo(): array {
		return [
			'qqcontinue' => [
				'type' => 'hidden',
				'value' => '1',
				'label' => '',
				'help' => '',
			],
		];
	}

	/**
	 * @param array $data
	 * @return bool
	 */
	public function loadFromSubmission( array $data ): bool {
		// Accept any submission that includes our marker field. The actual
		// outcome data is read from the session by the provider.
		return isset( $data['qqcontinue'] );
	}
}
