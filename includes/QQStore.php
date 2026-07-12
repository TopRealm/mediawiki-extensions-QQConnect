<?php
/**
 * Database access for the qqconnect_users mapping table.
 *
 * All methods operate on the primary database (DB_PRIMARY) for writes and
 * replica (DB_REPLICA) for reads. The table maps MediaWiki user_id <-> a
 * (QQ OpenID, APPID) pair. Invariants enforced by the schema:
 *   - one MediaWiki user  -> at most one QQ binding (PK on qqc_user)
 *   - one (OpenID, APPID) -> at most one MediaWiki user (unique index)
 */

namespace MediaWiki\Extension\QQConnect;

use IDBAccessObject;
use MediaWiki\User\UserFactory;
use Wikimedia\Rdbms\IDatabase;
use Wikimedia\Rdbms\ILoadBalancer;
use Wikimedia\Rdbms\IReadableDatabase;

class QQStore {

	public const TABLE = 'qqconnect_users';

	/** @var ILoadBalancer */
	private $loadBalancer;

	/** @var UserFactory */
	private $userFactory;

	public function __construct( ILoadBalancer $loadBalancer, UserFactory $userFactory ) {
		$this->loadBalancer = $loadBalancer;
		$this->userFactory = $userFactory;
	}

	/**
	 * Get the replica DB connection for reads.
	 *
	 * @param int $flags
	 * @return IReadableDatabase
	 */
	private function getReplicaDb( int $flags = 0 ) {
		return $this->loadBalancer->getConnection( DB_REPLICA, [], $this->guessDomain(), $flags );
	}

	/**
	 * Get the primary DB connection for writes.
	 *
	 * @return IDatabase
	 */
	private function getPrimaryDb(): IDatabase {
		return $this->loadBalancer->getConnection( DB_PRIMARY, [], $this->guessDomain() );
	}

	private function guessDomain(): false {
		// Use the wiki's local DB; false => local domain.
		return false;
	}

	/**
	 * Find the MediaWiki user bound to a given QQ OpenID/APPID.
	 *
	 * @param string $openid
	 * @param string $appid
	 * @return array|null Associative row (qqc_user, qqc_openid, qqc_appid,
	 *    qqc_nickname, qqc_avatar, qqc_bound_timestamp) or null if not bound.
	 */
	public function findBindingByOpenid( string $openid, string $appid ): ?array {
		$db = $this->getReplicaDb();
		$row = $db->selectRow(
			self::TABLE,
			'*',
			[
				'qqc_openid' => $openid,
				'qqc_appid' => $appid,
			],
			__METHOD__
		);
		return $row ? (array)$row : null;
	}

	/**
	 * Find the QQ binding for a given MediaWiki user id.
	 *
	 * @param int $userId
	 * @return array|null
	 */
	public function findBindingByUser( int $userId ): ?array {
		$db = $this->getReplicaDb();
		$row = $db->selectRow(
			self::TABLE,
			'*',
			[ 'qqc_user' => $userId ],
			__METHOD__
		);
		return $row ? (array)$row : null;
	}

	/**
	 * Returns true if a QQ OpenID is already bound to some account.
	 *
	 * @param string $openid
	 * @param string $appid
	 * @return bool
	 */
	public function openidIsBound( string $openid, string $appid ): bool {
		return $this->findBindingByOpenid( $openid, $appid ) !== null;
	}

	/**
	 * Returns true if the MediaWiki user already has a QQ bound.
	 *
	 * @param int $userId
	 * @return bool
	 */
	public function userIsBound( int $userId ): bool {
		return $this->findBindingByUser( $userId ) !== null;
	}

	/**
	 * Bind a QQ account to a MediaWiki user. Assumes the caller has already
	 * verified that neither side is currently bound to another record of the
	 * opposite side (see openidIsBound / userIsBound).
	 *
	 * @param int $userId
	 * @param string $openid
	 * @param string $appid
	 * @param string $nickname
	 * @param string $avatar
	 * @return bool True on success.
	 */
	public function bind( int $userId, string $openid, string $appid, string $nickname, string $avatar ): bool {
		$db = $this->getPrimaryDb();
		$db->insert(
			self::TABLE,
			[
				'qqc_user' => $userId,
				'qqc_openid' => $openid,
				'qqc_appid' => $appid,
				'qqc_nickname' => $nickname !== '' ? $nickname : null,
				'qqc_avatar' => $avatar !== '' ? $avatar : null,
				'qqc_bound_timestamp' => $db->timestamp(),
			],
			__METHOD__,
			[ 'IGNORE' ]
		);
		return $db->affectedRows() > 0;
	}

	/**
	 * Unbind (delete) the QQ binding for a MediaWiki user.
	 *
	 * @param int $userId
	 * @return bool True if a row was actually deleted.
	 */
	public function unbind( int $userId ): bool {
		$db = $this->getPrimaryDb();
		$db->delete(
			self::TABLE,
			[ 'qqc_user' => $userId ],
			__METHOD__
		);
		return $db->affectedRows() > 0;
	}

	/**
	 * Replace the QQ binding for a MediaWiki user with a new QQ account.
	 * Implemented as a delete + insert inside a transaction to preserve
	 * atomicity.
	 *
	 * @param int $userId
	 * @param string $openid
	 * @param string $appid
	 * @param string $nickname
	 * @param string $avatar
	 * @return bool True on success.
	 */
	public function rebind(
		int $userId,
		string $openid,
		string $appid,
		string $nickname,
		string $avatar
	): bool {
		$db = $this->getPrimaryDb();
		// Open the section as cancelable so the catch block can roll back.
		$db->startAtomic( __METHOD__, IDatabase::ATOMIC_CANCELABLE );
		try {
			$db->delete( self::TABLE, [ 'qqc_user' => $userId ], __METHOD__ );
			$db->insert(
				self::TABLE,
				[
					'qqc_user' => $userId,
					'qqc_openid' => $openid,
					'qqc_appid' => $appid,
					'qqc_nickname' => $nickname !== '' ? $nickname : null,
					'qqc_avatar' => $avatar !== '' ? $avatar : null,
					'qqc_bound_timestamp' => $db->timestamp(),
				],
				__METHOD__,
				[ 'IGNORE' ]
			);
			$ok = $db->affectedRows() > 0;
			$db->endAtomic( __METHOD__ );
			return $ok;
		} catch ( \Throwable $e ) {
			$db->cancelAtomic( __METHOD__ );
			throw $e;
		}
	}

	/**
	 * List all bindings, optionally filtered by a username prefix.
	 *
	 * @param string|null $usernamePrefix
	 * @param int $limit
	 * @param int $offset
	 * @return array List of associative rows joined with user table.
	 */
	public function listBindings( ?string $usernamePrefix = null, int $limit = 100, int $offset = 0 ): array {
		$db = $this->getReplicaDb();
		$conds = [];
		if ( $usernamePrefix !== null && $usernamePrefix !== '' ) {
			$conds[] = 'user_name ' . $db->buildLike( $usernamePrefix, $db->anyString() );
		}
		$res = $db->select(
			[ self::TABLE, 'user' ],
			[
				'qqc_user',
				'qqc_openid',
				'qqc_appid',
				'qqc_nickname',
				'qqc_avatar',
				'qqc_bound_timestamp',
				'user_name',
			],
			$conds,
			__METHOD__,
			[
				'LIMIT' => $limit,
				'OFFSET' => $offset,
				'ORDER BY' => 'qqc_bound_timestamp DESC',
			],
			[ 'user' => [ 'INNER JOIN', [ 'user_id = qqc_user' ] ] ]
		);
		$rows = [];
		foreach ( $res as $row ) {
			$rows[] = (array)$row;
		}
		return $rows;
	}
}
