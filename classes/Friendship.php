<?php
/**
 * Curse Inc.
 * Curse Profile
 * A modular, multi-featured user profile system.
 *
 * @author		Noah Manneschmidt
 * @copyright	(c) 2013 Curse Inc.
 * @license		All Rights Reserved
 * @package		CurseProfile
 * @link		http://www.curse.com/
 *
**/
namespace CurseProfile;

/**
 * Class that manages friendship relations between users. Create an instance with a curse ID.
 * All relationships statuses are then described from the perspective of that user.
 */
class Friendship {
	private $curse_id;

	/**
	 * Relationship status constants
	 */
	const STRANGERS        = 1;
	const FRIENDS          = 2;
	const REQUEST_SENT     = 3;
	const REQUEST_RECEIVED = 4;

	/**
	 * The user passed to the constructor is used as the main user from which the
	 * perspective of the SENT/RECEIVED status are determined.
	 *
	 * @param	int		curse ID of a user
	 */
	public function __construct($curse_id) {
		$this->curse_id = intval($curse_id);
	}

	/**
	 * Check the relationship status between two users.
	 *
	 * @param	int		curse ID of a user
	 * @return	int		-1 on failure or one of the class constants STRANGERS, FRIENDS, REQUEST_SENT, REQUEST_RECEIVED
	 */
	public function getRelationship($toUser) {
		$toUser = intval($toUser);
		if ($this->curse_id < 1 || $this->curse_id == $toUser || $toUser < 1) {
			return -1;
		}

		$mouse = CP::loadMouse();

		// first check for existing friends
		if ($mouse->redis->sismember($this->friendListRedisKey(), $toUser)) {
			return self::FRIENDS;
		}

		// check for pending requests
		if ($mouse->redis->hexists($this->requestsRedisKey(), $toUser)) {
			return self::REQUEST_RECEIVED;
		}
		if ($mouse->redis->hexists($this->requestsRedisKey($toUser), $this->curse_id)) {
			return self::REQUEST_SENT;
		}

		return self::STRANGERS; // assumption when not found in redis
	}

	/**
	 * Returns the array of curse IDs for this or another user's friends
	 *
	 * @param	int		optional curse ID of a user (default $this->curse_id)
	 * @return	array	curse IDs of friends
	 */
	public function getFriends($user = null) {
		if ($this->curse_id < 1) {
			return [];
		}

		if ($user == null) {
			$user = $this->curse_id;
		}
		$mouse = CP::loadMouse();
		return $mouse->redis->smembers($this->friendListRedisKey($user));
	}

	/**
	 * Returns the number of friends a user has
	 *
	 * @param	int		optional curse ID of a user (default $this->curse_id)
	 * @return	int		a number of friends
	 */
	public function getFriendCount($user = null) {
		if ($this->curse_id < 1) {
			return [];
		}

		if ($user == null) {
			$user = $this->curse_id;
		}
		$mouse = CP::loadMouse();
		return $mouse->redis->scard($this->friendListRedisKey($user));
	}

	/**
	 * Returns the array of pending friend requests that have sent this user
	 *
	 * @return	array	keys are curse IDs of potential friends,
	 *     values are json strings with additional data (currently empty)
	 */
	public function getReceivedRequests() {
		if ($this->curse_id < 1) {
			return [];
		}

		$mouse = CP::loadMouse();
		return $mouse->redis->hgetall($this->requestsRedisKey());
	}

	/**
	 * Returns the array of pending friend requests that have been sent by this user
	 *
	 * @return	array	values are curse IDs
	 */
	public function getSentRequests() {
		if ($this->curse_id < 1) {
			return [];
		}

		$mouse = CP::loadMouse();
		return $mouse->redis->smembers($this->sentRequestsRedisKey());
	}

	/**
	 * Generates a redis key for the hash of pending requests received
	 *
	 * @param	int		optional curse ID of a user (default $this->curse_id)
	 * @return	string	redis key to be used
	 */
	private function requestsRedisKey($user = null) {
		if ($user == null) {
			$user = $this->curse_id;
		}
		return 'friendrequests:'.$user;
	}

	/**
	 * Generates a redis key for the set of pending requests sent
	 *
	 * @param	int		optional curse ID of a user (default $this->curse_id)
	 * @return	string	redis key to be used
	 */
	private function sentRequestsRedisKey($user = null) {
		if ($user == null) {
			$user = $this->curse_id;
		}
		return 'friendrequests:'.$user.':sent';
	}

	/**
	 * Generates a redis key for a set of friends
	 *
	 * @param	int		optional curse ID of a user (default $this->curse_id)
	 * @return	string	redis key to be used
	 */
	private function friendListRedisKey($user = null) {
		if ($user == null) {
			$user = $this->curse_id;
		}
		return 'friendlist:'.$user;
	}

	/**
	 * Sends a friend request to a given user
	 *
	 * @param	int		curse ID of a user
	 * @return	bool	true on success, false on failure
	 */
	public function sendRequest($toUser) {
		$toUser = intval($toUser);
		if ($this->curse_id < 1 || $this->curse_id == $toUser || $toUser < 1) {
			return false;
		}

		// Queue sync before error check in case redis is not in sync
		FriendSync::queue([
			'task' => 'add',
			'actor' => $this->curse_id,
			'target' => $toUser,
		]);
		if ($this->getRelationship($toUser) != self::STRANGERS) {
			return false;
		}

		$mouse = CP::loadMouse();
		$mouse->redis->hset($this->requestsRedisKey($toUser), $this->curse_id, '{}');
		$mouse->redis->sadd($this->sentRequestsRedisKey(), $toUser);

		global $wgUser;
		\EchoEvent::create([
			'type' => 'friendship-request',
			'agent' => $wgUser,
			// 'title' => $wgUser->getUserPage(),
			'extra' => [
				'target_user_id' => CP::userIDfromCurseID($toUser)
			]
		]);

		wfRunHooks('CurseProfileAddFriend', [$this->curse_id, $toUser]);

		return true;
	}

	/**
	 * Accepts a pending request
	 *
	 * @param	int		curse ID of a user
	 * @return	bool	true on success, false on failure
	 */
	public function acceptRequest($toUser) {
		return $this->respondToRequest($toUser, 'accept');
	}

	/**
	 * Ignores and dismisses a pending request
	 *
	 * @param	int		curse ID of a user
	 * @return	bool	true on success, false on failure
	 */
	public function ignoreRequest($toUser) {
		return $this->respondToRequest($toUser, 'ignore');
	}

	/**
	 * Shared logic between accepting and ignoring pending requests
	 *
	 * @param	int		user id of whose request is being responded to
	 * @param	string	responce being sent. one of 'accept' or 'ignore'
	 * @return	bool	true on success
	 */
	private function respondToRequest($toUser, $response) {
		$toUser = intval($toUser);
		if ($this->curse_id < 1 || $this->curse_id == $toUser || $toUser < 1) {
			return -1;
		}

		FriendSync::queue([
			'task' => ($response == 'accept' ? 'confirm' : 'ignore'),
			'actor' => $this->curse_id,
			'target' => $toUser
		]);
		if ($this->getRelationship($toUser) != self::REQUEST_RECEIVED) {
			return false;
		}

		$mouse = CP::loadMouse();

		// delete pending request
		$mouse->redis->hdel($this->requestsRedisKey(), $toUser);
		$mouse->redis->srem($this->sentRequestsRedisKey($toUser), $this->curse_id);

		if ($response == 'accept') {
			// add reciprocal friendship
			$mouse->redis->sadd($this->friendListRedisKey(), $toUser);
			$mouse->redis->sadd($this->friendListRedisKey($toUser), $this->curse_id);
		}

		return true;
	}

	/**
	 * Removes a friend relationship, or cancels a pending request
	 *
	 * @param	int		curse ID of a user
	 * @return	bool	true on success, false on failure
	 */
	public function removeFriend($toUser) {
		$toUser = intval($toUser);
		if ($this->curse_id < 1 || $this->curse_id == $toUser || $toUser < 1) {
			return false;
		}

		FriendSync::queue([
			'task' => 'remove',
			'actor' => $this->curse_id,
			'target' => $toUser
		]);

		$mouse = CP::loadMouse();

		// remove pending incoming requests
		$mouse->redis->hdel($this->requestsRedisKey($toUser), $this->curse_id);
		$mouse->redis->hdel($this->requestsRedisKey(), $toUser);

		// remove sent request references
		$mouse->redis->srem($this->sentRequestsRedisKey($toUser), $this->curse_id);
		$mouse->redis->srem($this->sentRequestsRedisKey(), $toUser);

		// remove existing friendship
		$mouse->redis->srem($this->friendListRedisKey($toUser), $this->curse_id);
		$mouse->redis->srem($this->friendListRedisKey(), $toUser);

		wfRunHooks('CurseProfileRemoveFriend', [$this->curse_id, $toUser]);

		return true;
	}

	/**
	 * Treats the current database info as authoritative and corrects redis to match
	 * If instance of Friendship was created with a null curse ID, will sync entire table
	 *
	 * @param	ILogger	instance of a logger if output is desired
	 * @return	null
	 */
	public function syncToRedis(\SyncService\ILogger $logger = null) {
		if (!defined('CURSEPROFILE_MASTER')) {
			return;
		}
		if (is_null($logger)) {
			$log = function($str, $time=null) {};
		} else {
			$log = function($str, $time=null) use ($logger) {
				$logger->outputLine($str, $time);
			};
		}
		$mouse = CP::loadMouse();

		$res = $mouse->DB->select([
			'select' => 'ur.*',
			'from'   => ['user_relationship' => 'ur'],
			'where'  => "ur.r_type = 1".($this->curse_id < 1 ? NULL : " AND (ur.r_user_id = {$this->curse_id} OR ur.r_user_id_relation = {$this->curse_id})"),
		]);
		while ($friend = $mouse->DB->fetch($res)) {
			$mouse->redis->sadd($this->friendListRedisKey($friend['r_user_id']), $friend['r_user_id_relation']);
			$mouse->redis->sadd($this->friendListRedisKey($friend['r_user_id_relation']), $friend['r_user_id']);
			$log("Added friendship between curse IDs {$friend['r_user_id']} and {$friend['r_user_id_relation']}", time());
		}

		$res = $mouse->DB->select([
			'select' => 'ur.*',
			'from'   => ['user_relationship_request' => 'ur'],
			'where'  => "ur.ur_type = 1".($this->curse_id < 1 ? NULL : " AND (ur.ur_user_id_from = {$this->curse_id} OR ur.ur_user_id_to = {$this->curse_id})"),
		]);
		while ($friendReq = $mouse->DB->fetch($res)) {
			$mouse->redis->hset($this->requestsRedisKey($friendReq['ur_user_id_to']), $friendReq['ur_user_id_from'], '{}');
			$mouse->redis->sadd($this->sentRequestsRedisKey($friendReq['ur_user_id_from']), $friendReq['ur_user_id_to']);
			$log("Added pending friendship between curse IDs {$friendReq['ur_user_id_to']} and {$friendReq['ur_user_id_from']}", time());
		}
	}

	/**
	 * This will write a given change to the database. Called by FriendSync job.
	 *
	 * @param	array	args sent to the FriendSync job
	 * @return	int		exit code: 0 for success, 1 for failure
	 */
	public function saveToDB($args) {
		if (!defined('CURSEPROFILE_MASTER')) {
			return 1; // the appropriate tables don't exist here
		}
		$args['target'] = intval($args['target']);
		if ($args['target'] < 1) {
			return 1;
		}

		$mouse = CP::loadMouse();
		switch ($args['task']) {
			case 'add':
				$mouse->DB->insert('user_relationship_request', [
					'ur_user_id_from' => $this->curse_id,
					'ur_user_id_to'   => $args['target'],
					'ur_type'         => 1,
					'ur_date'         => date( 'Y-m-d H:i:s' ),
				]);
				break;

			case 'confirm':
				$mouse->DB->insert('user_relationship', [
					'r_user_id'          => $this->curse_id,
					'r_user_id_relation' => $args['target'],
					'r_type'             => 1,
					'r_date'             => date( 'Y-m-d H:i:s' ),
				]);
				$mouse->DB->insert('user_relationship', [
					'r_user_id'          => $args['target'],
					'r_user_id_relation' => $this->curse_id,
					'r_type'             => 1,
					'r_date'             => date( 'Y-m-d H:i:s' ),
				]);
				// intentional fall-through

			case 'ignore':
				$mouse->DB->delete('user_relationship_request', "ur_user_id_from = {$args['target']} AND ur_user_id_to = {$this->curse_id}");
				break;

			case 'remove':
				$mouse->DB->delete('user_relationship', "r_user_id = {$args['target']} AND r_user_id_relation = {$this->curse_id}");
				$mouse->DB->delete('user_relationship', "r_user_id = {$this->curse_id} AND r_user_id_relation = {$args['target']}");
				$mouse->DB->delete('user_relationship_request', "ur_user_id_from = {$this->curse_id} AND ur_user_id_to = {$args['target']}");
				break;

			default:
				return 1;
		}
		return 0;
	}
}
