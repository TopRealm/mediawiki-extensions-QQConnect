<?php
/**
 * Special:QQConnect — the user-facing QQ account management page.
 *
 * Lets a logged-in user:
 *  - View their currently bound QQ account (nickname, unionid, avatar, time).
 *  - Bind a QQ account (if not bound).
 *  - Change (rebind) their bound QQ account (starts a fresh OAuth flow;
 *    on success the old binding is replaced).
 *  - Unbind their QQ account.
 *
 * This page requires login. Rebinding/unbinding are security-sensitive
 * operations: they trigger AuthManager re-auth via getLoginSecurityLevel when
 * appropriate (the unbind is destructive, so we ask for confirmation).
 */

namespace MediaWiki\Extension\QQConnect\Special;

use HTMLForm;
use MediaWiki\Extension\QQConnect\Auth\QQPrimaryAuthenticationProvider as P;
use MediaWiki\Extension\QQConnect\QQClient;
use MediaWiki\Extension\QQConnect\QQConnectConfig;
use MediaWiki\Extension\QQConnect\QQStore;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\MediaWikiServices;
use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\User\User;
use Psr\Log\LoggerInterface;
use StatusValue;

class SpecialQQConnect extends SpecialPage {

	/** @var QQConnectConfig */
	private $config;

	/** @var QQClient */
	private $client;

	/** @var QQStore */
	private $store;

	/** @var LoggerInterface */
	private $logger;

	public function __construct(
		QQConnectConfig $config,
		QQClient $client,
		QQStore $store
	) {
		parent::__construct( 'QQConnect' );
		$this->config = $config;
		$this->client = $client;
		$this->store = $store;
		$this->logger = LoggerFactory::getInstance( 'qqconnect' );
	}

	protected function getGroupName() {
		return 'users';
	}

	public function requiresLogin() {
		return true;
	}

	/**
	 * @param string|null $subPage
	 */
	public function execute( $subPage ) {
		$this->setHeaders();
		$this->checkLoginSecurityLevel( 'qqconnect-manage-self' );
		$this->checkReadOnly();
		$this->getOutput()->addModuleStyles( 'ext.QQConnect.styles' );

		$user = $this->getUser();
		$binding = $this->store->findBindingByUser( $user->getId() );
		$request = $this->getRequest();

		// HTMLForm action URL is the bare page title; detect unbind form
		// submission via hidden marker field (same pattern as QQConnectLogin).
		if ( $request->getRawVal( '__qqconnect_flow' ) === 'unbind' ) {
			$this->handleUnbind( $binding );
			return;
		}

		// Handle actions from query string.
		$action = $request->getRawVal( 'action' );
		if ( $action === 'bind' && !$binding ) {
			$this->startBindFlow();
			return;
		}
		if ( $action === 'rebind' && $binding ) {
			$this->startRebindFlow();
			return;
		}
		if ( $action === 'unbind' && $binding ) {
			$this->handleUnbind( $binding );
			return;
		}

		// Default: show the management view.
		$this->showManagePage( $binding );
	}

	/**
	 * Show the main management view.
	 *
	 * @param array|null $binding
	 */
	private function showManagePage( ?array $binding ) {
		$out = $this->getOutput();
		$out->setPageTitleMsg( $this->msg( 'qqconnect-special-manage-title' ) );
		$out->addWikiMsg( 'qqconnect-special-manage-intro' );

		if ( $binding ) {
			// Show bound QQ details.
			$nickname = $binding['qqc_nickname'] ?? '';
			$unionid = $binding['qqc_unionid'] ?? '';
			$avatar = $binding['qqc_avatar'] ?? '';
			$boundTs = $binding['qqc_bound_timestamp'] ?? '';

			$out->addWikiMsg( 'qqconnect-manage-bound' );

			$info = '';
			if ( $avatar ) {
				$info .= '<p class="qqconnect-avatar">'
					. '<img src="' . htmlspecialchars( $avatar ) . '" alt="'
					. $this->msg( 'qqconnect-manage-avatar' )->escaped() . '" width="100" height="100" /></p>';
			}
			$info .= '<ul class="qqconnect-binding-info">';
			if ( $nickname ) {
				$info .= '<li>' . $this->msg( 'qqconnect-manage-nickname' )
					->params( htmlspecialchars( $nickname ) )->escaped() . '</li>';
			}
			$info .= '<li>' . $this->msg( 'qqconnect-manage-openid' )
				->params( htmlspecialchars( $unionid ) )->escaped() . '</li>';
			if ( $boundTs ) {
				$info .= '<li>' . $this->msg( 'qqconnect-manage-bound-time' )
					->params( htmlspecialchars( $this->getLanguage()->userTimeAndDate( $boundTs, $this->getUser() ) ) )
					->escaped() . '</li>';
			}
			$info .= '</ul>';
			$out->addHTML( $info );

			// Action buttons: rebind, unbind.
			$rebindUrl = $this->getPageTitle()->getLocalURL( [ 'action' => 'rebind' ] );
			$unbindUrl = $this->getPageTitle()->getLocalURL( [ 'action' => 'unbind' ] );
			$out->addHTML(
				'<div class="qqconnect-actions">'
				. '<a class="mw-ui-button qqconnect-btn qqconnect-btn-rebind" href="'
				. htmlspecialchars( $rebindUrl ) . '">'
				. $this->msg( 'qqconnect-manage-rebind' )->escaped() . '</a> '
				. '<a class="mw-ui-button mw-ui-destructive qqconnect-btn qqconnect-btn-unbind" href="'
				. htmlspecialchars( $unbindUrl ) . '">'
				. $this->msg( 'qqconnect-manage-unbind' )->escaped() . '</a>'
				. '</div>'
			);
		} else {
			$out->addWikiMsg( 'qqconnect-manage-not-bound' );
			$bindUrl = $this->getPageTitle()->getLocalURL( [ 'action' => 'bind' ] );
			$out->addHTML(
				'<div class="qqconnect-actions">'
				. '<a class="mw-ui-button mw-ui-progressive qqconnect-btn qqconnect-btn-bind" href="'
				. htmlspecialchars( $bindUrl ) . '">'
				. $this->msg( 'qqconnect-manage-bind' )->escaped() . '</a>'
				. '</div>'
			);
		}
	}

	/**
	 * Start the bind flow (user is logged in, not yet bound).
	 *
	 * We reuse the QQConnectLogin OAuth round-trip, but set a session flag so
	 * that on callback the special page knows to bind to the *current* user
	 * rather than routing through the choose page.
	 */
	private function startBindFlow() {
		if ( $this->config->isTestMode() || !$this->config->isConfigured() ) {
			$this->getOutput()->redirect(
				SpecialPage::getTitleFor( 'QQConnectLogin' )->getFullURL( [ 'action' => 'test' ] )
			);
			return;
		}
		$state = $this->client->generateState();
		$redirectUri = $this->getConfiguredRedirectUri();
		$authManager = MediaWikiServices::getInstance()->getAuthManager();
		$authManager->setAuthenticationSessionData( P::SESSION_KEY_STATE, $state );
		$authManager->setAuthenticationSessionData( P::SESSION_KEY_REDIRECT, $redirectUri );
		// Flag: this OAuth round-trip is for binding the current user.
		$authManager->setAuthenticationSessionData( 'QQConnect:bindMode', 'bind' );
		$authorizeUrl = $this->client->getAuthorizeUrl( $redirectUri, $state );
		$this->getOutput()->redirect( $authorizeUrl );
	}

	/**
	 * Start the rebind flow (user is logged in and already bound). The OAuth
	 * round-trip replaces the old binding.
	 */
	private function startRebindFlow() {
		if ( $this->config->isTestMode() || !$this->config->isConfigured() ) {
			$this->getOutput()->redirect(
				SpecialPage::getTitleFor( 'QQConnectLogin' )->getFullURL( [ 'action' => 'test' ] )
			);
			return;
		}
		$state = $this->client->generateState();
		$redirectUri = $this->getConfiguredRedirectUri();
		$authManager = MediaWikiServices::getInstance()->getAuthManager();
		$authManager->setAuthenticationSessionData( P::SESSION_KEY_STATE, $state );
		$authManager->setAuthenticationSessionData( P::SESSION_KEY_REDIRECT, $redirectUri );
		$authManager->setAuthenticationSessionData( 'QQConnect:bindMode', 'rebind' );
		$authorizeUrl = $this->client->getAuthorizeUrl( $redirectUri, $state );
		$this->getOutput()->redirect( $authorizeUrl );
	}

	/**
	 * Handle the unbind action (with confirmation).
	 *
	 * @param array $binding
	 */
	private function handleUnbind( ?array $binding ) {
		if ( !$binding ) {
			$this->getOutput()->addWikiMsg( 'qqconnect-manage-not-bound' );
			return;
		}

		// Show a confirmation form.
		$formDescriptor = [
			'confirm_info' => [
				'type' => 'info',
				'default' => $this->msg( 'qqconnect-manage-confirm-unbind' )->text(),
			],
			'__qqconnect_flow' => [
				'type' => 'hidden',
				'name' => '__qqconnect_flow',
				'default' => 'unbind',
			],
		];
		$form = HTMLForm::factory( 'ooui', $formDescriptor, $this->getContext() );
		$form->setSubmitTextMsg( 'qqconnect-manage-unbind' );
		$form->setSubmitDestructive();
		$form->setSubmitCallback( [ $this, 'onUnbindConfirm' ] );
		$form->show();
	}

	/**
	 * @param array $data
	 * @return StatusValue
	 */
	public function onUnbindConfirm( array $data ) {
		$userId = $this->getUser()->getId();
		$ok = $this->store->unbind( $userId );
		if ( $ok ) {
			$logEntry = new \ManualLogEntry( 'qqconnect', 'unbind' );
			$logEntry->setPerformer( $this->getUser() );
			$logEntry->setTarget( $this->getUser()->getUserPage() );
			$logEntry->insert();

			$this->getOutput()->addWikiMsg( 'qqconnect-manage-unbind-success' );
		}
		// MW 1.43 HTMLForm::show() does not redirect on newGood().
		$this->getOutput()->redirect( $this->getPageTitle()->getFullURL() );
		return StatusValue::newGood();
	}

	/**
	 * @return string
	 */
	private function getConfiguredRedirectUri(): string {
		$configured = $this->config->getRedirectUri();
		if ( $configured ) {
			return $configured;
		}
		return SpecialPage::getTitleFor( 'QQConnectLogin' )->getFullURL();
	}

	/**
	 * Make the unbind/rebind actions security-sensitive so they require a
	 * recent re-authentication.
	 *
	 * @return string|false
	 */
	protected function getLoginSecurityLevel() {
		return 'qqconnect-manage-self';
	}
}
