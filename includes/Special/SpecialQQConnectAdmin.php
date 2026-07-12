<?php
/**
 * Special:QQConnectAdmin — adminstrator page for managing QQ bindings.
 *
 * Requires the 'qqconnect-manage' right. Lets an administrator:
 *  - List all QQ bindings (user, openid, nickname, bound time).
 *  - Search by username prefix.
 *  - Force-unbind a QQ from any user.
 *
 * Force-unbind only deletes the qqconnect_users row; it does not touch the
 * MediaWiki user account itself.
 */

namespace MediaWiki\Extension\QQConnect\Special;

use ErrorPageError;
use HTMLForm;
use MediaWiki\Extension\QQConnect\QQConnectConfig;
use MediaWiki\Extension\QQConnect\QQStore;
use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\User\UserFactory;
use PermissionsError;
use StatusValue;

class SpecialQQConnectAdmin extends SpecialPage {

	/** @var QQConnectConfig */
	private $config;

	/** @var QQStore */
	private $store;

	/** @var UserFactory */
	private $userFactory;

	public function __construct(
		QQConnectConfig $config,
		QQStore $store
	) {
		parent::__construct( 'QQConnectAdmin', 'qqconnect-manage' );
		$this->config = $config;
		$this->store = $store;
	}

	protected function getGroupName() {
		return 'users';
	}

	public function doesWrites() {
		return true;
	}

	/**
	 * @param string|null $subPage
	 */
	public function execute( $subPage ) {
		$this->setHeaders();
		// Permission check (uses the restriction passed to the constructor).
		$this->checkPermissions();
		$this->checkReadOnly();
		$this->getOutput()->addModuleStyles( 'ext.QQConnect.styles' );

		$request = $this->getRequest();

		// Handle force-unbind.
		if ( $request->getRawVal( 'action' ) === 'unbind' ) {
			$this->handleAdminUnbind();
			return;
		}

		// Show the list + search form.
		$this->showList();
	}

	/**
	 * Show the list of bindings with a search form.
	 */
	private function showList() {
		$out = $this->getOutput();
		$out->setPageTitleMsg( $this->msg( 'qqconnect-special-admin-title' ) );
		$out->addWikiMsg( 'qqconnect-special-admin-intro' );

		// Search form.
		$formDescriptor = [
			'search' => [
				'type' => 'text',
				'name' => 'search',
				'label-message' => 'qqconnect-admin-search',
				'default' => $this->getRequest()->getVal( 'search', '' ),
			],
		];
		$form = HTMLForm::factory( 'ooui', $formDescriptor, $this->getContext() );
		$form->setMethod( 'get' );
		$form->setSubmitTextMsg( 'qqconnect-admin-search-submit' );
		// The form's submit callback isn't used since method=get; we just
		// render it and read the query string.
		$form->setSubmitCallback( static function () {
			return false;
		} );
		$form->show();

		// Load bindings.
		$search = $this->getRequest()->getVal( 'search', '' );
		$bindings = $this->store->listBindings( $search !== '' ? $search : null, 200 );

		if ( !$bindings ) {
			$out->addWikiMsg( 'qqconnect-admin-nobody' );
			return;
		}

		// Render a table.
		$html = '<table class="wikitable qqconnect-admin-table">';
		$html .= '<tr>';
		$html .= '<th>' . $this->msg( 'qqconnect-admin-list-user' )->escaped() . '</th>';
		$html .= '<th>' . $this->msg( 'qqconnect-admin-list-openid' )->escaped() . '</th>';
		$html .= '<th>' . $this->msg( 'qqconnect-admin-list-nickname' )->escaped() . '</th>';
		$html .= '<th>' . $this->msg( 'qqconnect-admin-list-bound' )->escaped() . '</th>';
		$html .= '<th>' . $this->msg( 'qqconnect-admin-list-actions' )->escaped() . '</th>';
		$html .= '</tr>';

		foreach ( $bindings as $row ) {
			$username = $row['user_name'] ?? ( '#' . $row['qqc_user'] );
			$openid = $row['qqc_openid'] ?? '';
			$nickname = $row['qqc_nickname'] ?? '';
			$boundTs = $row['qqc_bound_timestamp'] ?? '';
			$boundDisplay = $boundTs
				? htmlspecialchars( $this->getLanguage()->userTimeAndDate( $boundTs, $this->getUser() ) )
				: '';
			$unbindUrl = $this->getPageTitle()->getLocalURL( [
				'action' => 'unbind',
				'user' => $row['qqc_user'],
			] );

			$html .= '<tr>';
			$html .= '<td>' . htmlspecialchars( $username ) . '</td>';
			$html .= '<td><code>' . htmlspecialchars( $openid ) . '</code></td>';
			$html .= '<td>' . htmlspecialchars( $nickname ) . '</td>';
			$html .= '<td>' . $boundDisplay . '</td>';
			$html .= '<td><a class="mw-ui-button mw-ui-destructive qqconnect-admin-unbind" href="'
				. htmlspecialchars( $unbindUrl ) . '">'
				. $this->msg( 'qqconnect-admin-unbind' )->escaped() . '</a></td>';
			$html .= '</tr>';
		}
		$html .= '</table>';
		$out->addHTML( $html );
	}

	/**
	 * Handle a force-unbind request, with confirmation.
	 */
	private function handleAdminUnbind() {
		$request = $this->getRequest();
		$userId = (int)$request->getInt( 'user' );
		$confirmed = $request->getBool( 'confirm' );

		if ( !$userId ) {
			$this->getOutput()->addWikiMsg( 'qqconnect-admin-nobody' );
			return;
		}

		$binding = $this->store->findBindingByUser( $userId );
		if ( !$binding ) {
			$this->getOutput()->addWikiMsg( 'qqconnect-admin-nobody' );
			return;
		}

		$userFactory = \MediaWiki\MediaWikiServices::getInstance()->getUserFactory();
		$targetUser = $userFactory->newFromId( $userId );
		$username = $targetUser->getName();

		if ( !$confirmed ) {
			$formDescriptor = [
				'confirm_info' => [
					'type' => 'info',
					'default' => $this->msg( 'qqconnect-admin-unbind-confirm' )->params( $username )->text(),
				],
			];
			$form = HTMLForm::factory( 'ooui', $formDescriptor, $this->getContext() );
			$form->setSubmitTextMsg( 'qqconnect-admin-unbind' );
			$form->setSubmitDestructive();
			$form->setSubmitCallback( [ $this, 'onAdminUnbindConfirm' ] );
			// Pass the user id via a hidden field so it survives submission.
			$form->addHiddenField( 'user', $userId );
			$form->show();
			return;
		}

		$this->onAdminUnbindConfirm( [ 'user' => $userId ] );
	}

	/**
	 * @param array $data
	 * @return StatusValue
	 */
	public function onAdminUnbindConfirm( array $data ) {
		$userId = (int)( $data['user'] ?? 0 );
		if ( !$userId ) {
			return StatusValue::newFatal( 'qqconnect-admin-nobody' );
		}
		$userFactory = \MediaWiki\MediaWikiServices::getInstance()->getUserFactory();
		$targetUser = $userFactory->newFromId( $userId );
		$username = $targetUser->getName();
		$ok = $this->store->unbind( $userId );
		if ( $ok ) {
			$this->getOutput()->addWikiMsg( 'qqconnect-admin-unbind-success', $username );
		} else {
			$this->getOutput()->addWikiMsg( 'qqconnect-admin-nobody' );
		}
		$this->getOutput()->addReturnTo( $this->getPageTitle() );
		return StatusValue::newGood();
	}
}
