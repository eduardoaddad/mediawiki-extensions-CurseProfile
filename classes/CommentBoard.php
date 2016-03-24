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
 * Class that manages a 'wall' of comments on a user profile page
 */
class CommentBoard {
	/**
	 *	@var	int		the id of the user to whom this comment board belongs to
	 */
	private $user_id;

	// maximum character length of a single comment
	const MAX_LENGTH = 5000;

	/**
	 * @var		int		the number of comments to load on a board before a user clicks for more
	 */
	protected static $commentsPerPage = 5;

	/**
	 * @var		int		one of the below constants
	 */
	public $type;

	/**
	 * Board type constants
	 */
	const BOARDTYPE_RECENT   = 1; // recent comments shown on a person's profile
	const BOARDTYPE_ARCHIVES = 2; // archive page that shows all comments

	/**
	 * Message visibility constants
	 */
	const DELETED_MESSAGE = -1;
	const PUBLIC_MESSAGE = 0;
	const PRIVATE_MESSAGE = 1;

	/**
	 * The user passed to the constructor is used as the main user from which the
	 * perspective of the SENT/RECEIVED status are determined.
	 *
	 * @param	integer	the ID of a user
	 */
	public function __construct($user_id, $type = self::BOARDTYPE_RECENT) {
		$this->DB = CP::getDb(DB_MASTER);
		$this->user_id = intval($user_id);
		$this->type = intval($type);
		if ($this->user_id < 1) {
			throw new \Exception('Invalid user ID');
		}
	}

	/**
	 * Returns a sql WHERE clause fragment limiting comments to the current user's visibility
	 *
	 * @access	private
	 * @param   object  [Optional] mw User object doing the viewing (defaults to wgUser)
	 * @return  string  a single SQL condition entirely enclosed in parenthesis
	 */
	static private function visibleClause($asUser = null) {
		if (is_null($asUser)) {
			global $wgUser;
			$asUser = $wgUser;
		} else {
			$asUser = \User::newFromId($asUser);
		}

		if ($asUser->isAllowed('profile-moderate')) {
			// admins see everything
			return '1=1';
		} else {
			$conditions = [];
			//Everyone sees public messages.
			$conditions[] = 'user_board.ub_type = 0';
			//See private if you are author or recipient.
			$conditions[] = sprintf('user_board.ub_type = 1 AND (user_board.ub_user_id = %1$s OR user_board.ub_user_id_from = %1$s)', $asUser->getId());
			//See deleted if you are the author.
			$conditions[] = sprintf('user_board.ub_type = -1 AND user_board.ub_user_id_from = %1$s', $asUser->getId());
			return '( ('.implode(') OR (', $conditions).') )';
		}
	}

	/**
	 * Returns the total number of top-level comments (or replies to a given comment) that have been left
	 *
	 * @param	integer	[Optional] id of a comment (changes from a top-level count to a reply count)
	 * @param	integer	[Optional] user ID of a user viewing (defaults to wgUser)
	 */
	public function countComments($inReplyTo = null, $asUser = null) {
		if (is_null($inReplyTo)) {
			$inReplyTo = 0;
		} else {
			$inReplyTo = intval($inReplyTo);
		}

		$DB = CP::getDb(DB_SLAVE);
		$results = $DB->select(
			['user_board'],
			['count(*) as total'],
			[
				self::visibleClause($asUser),
				'ub_in_reply_to'	=> $inReplyTo,
				'ub_user_id'		=> $this->user_id
			],
			__METHOD__
		);

		$row = $results->fetchRow();
		return $row['total'];
	}

	/**
	 * Look up a single comment given a comment id (for display from a permalink)
	 *
	 * @param	integer	id of a user board comment
	 * @param   boolean	[Optional] true by default, if given ID is a reply, will fetch parent comment as well
	 * @param	integer	[Optional] user ID of user viewing (defaults to wgUser)
	 * @return  array   An array of comment data in the same format as getComments.
	 *   array will be empty if comment is unknown, or not visible.
	 */
	static public function getCommentById($commentId, $withParent = true, $asUser = null) {
		$commentId = intval($commentId);
		if ($commentId < 1) {
			return [];
		}

		//Look up the target comment.
		$comment = self::queryCommentById($commentId);

		if (empty($comment)) {
			return [];
		}

		//Switch our primary ID a parent comment, if it exists.
		if ($withParent && $comment['ub_in_reply_to']) {
			$rootId = $comment['ub_in_reply_to'];
		} else {
			$rootId = $commentId;
		}

		if (!self::canView($comment, $asUser)) {
			return [];
		}

		$board = new self($comment['ub_user_id'], self::BOARDTYPE_ARCHIVES);
		$comment = $board->getCommentsWithConditions(['ub_id' => $rootId], $asUser, 0, 1);
		// force loading all replies instead of just 5
		$comment[0]['replies'] = $board->getReplies($rootId, $asUser, 0);
		return $comment;
	}

	/**
	 * Function Documentation
	 *
	 * @access	private
	 * @return	void
	 */
	static private function queryCommentById($commentId) {
		$DB = CP::getDb(DB_MASTER);
		$result = $DB->select(
			['user_board'],
			['*'],
			['ub_id' => intval($commentId)],
			__METHOD__
		);

		return $result->fetchRow();
	}

	/**
	 * Generic comment retrieval utility function.  Automatically limits to viewable types.
	 *
	 * @access	private
	 * @param	array	SQL conditions applied to the user_board table query.  Will be merged with existing conditions.
	 * @param	integer	[Optional] User ID of user viewing. (Defaults to wgUser)
	 * @param	integer	[Optional] Number of comments to skip when loading more.
	 * @param	integer	[Optional] Number of top-level items to return.
	 * @return	array	comments!
	 */
	private function getCommentsWithConditions($conditions, $asUser = null, $startAt = 0, $limit = 100) {
		if (!is_array($conditions)) {
			$conditions = [];
		}
		//Fetch top level comments.
		$results = $this->DB->select(
			['user_board'],
			[
				'*',
				'IFNULL(ub_last_reply, ub_date) AS last_updated'
			],
			array_merge([
				self::visibleClause($asUser),
			], $conditions),
			__METHOD__,
			[
				'ORDER BY'	=> 'last_updated DESC',
				'OFFSET'	=> $startAt,
				'LIMIT'		=> $limit
			]
		);

		$comments = [];
		$commentIds = []; // will contain a mapping of commentId => array index within $comments
		// (for fast lookup of a comment by id when inserting replies)
		while ($row = $results->fetchRow()) {
			$commentIds[$row['ub_id']] = count($comments);
			$row['reply_count'] = 0;
			$comments[] = $row;
		}

		if (empty($comments)) {
			return $comments;
		}

		//Count many replies each comment in this chunk has.
		$results = $this->DB->select(
				['user_board'],
				[
					'ub_in_reply_to AS ub_id',
					'COUNT(*) as replies'
				],
				[
					'ub_in_reply_to' => array_keys($commentIds)
				],
				__METHOD__,
				[
					'GROUP BY'	=> 'ub_in_reply_to'
				]
			);
		//@TODO: fetch replies for all comments in a single DB query?
		while ($row = $results->fetchRow()) {
			$comments[$commentIds[$row['ub_id']]]['reply_count'] = intval($row['replies']);
			// retrieve replies if there are any
			if ($row['replies'] > 0) {
				$comments[$commentIds[$row['ub_id']]]['replies'] = $this->getReplies($row['ub_id'], $asUser);
			}
		}

		return $comments;
	}

	/**
	 * Gets all comments on the board.
	 *
	 * @access	public
	 * @param	integer	[Optional] user ID of user viewing (defaults to wgUser)
	 * @param	integer	[Optional] number of comments to skip when loading more
	 * @param	integer	[Optional] number of top-level items to return
	 * @param	integer	[Optional] maximum age of comments (by number of days)
	 * @return	array	an array of comment data (text and user info)
	 */
	public function getComments($asUser = null, $startAt = 0, $limit = 100, $maxAge = 30) {
		$searchConditions = [
			'ub_in_reply_to'	=> 0,
			'ub_user_id'		=> $this->user_id
		];
		if ($maxAge >= 0) {
			$searchConditions[] = 'IFNULL(ub_last_reply, ub_date) >= '.$this->DB->addQuotes(date('Y-m-d H:i:s', time()-$maxAge*86400));
		}
		return $this->getCommentsWithConditions($searchConditions, $asUser, $startAt, $limit);
	}

	/**
	 * Gets all replies to a given comment
	 *
	 * @access	public
	 * @param	integer	id of a comment that would be replied to
	 * @param	integer	[Optional] user ID of user viewing (defaults to wgUser)
	 * @param	integer	[Optional] max number items to return (older replies will be ommitted)
	 * @return	array	array of reply data
	 */
	public function getReplies($rootComment, $asUser = null, $limit = 5) {
		//Fetch comments.
		$options = [
			'ORDER BY'	=> 'ub_date DESC'
		];
		if ($limit > 0) {
			$options['LIMIT'] = intval($limit);
		}
		$results = $this->DB->select(
			['user_board'],
			[
				'*',
			],
			[
				self::visibleClause($asUser),
				'ub_in_reply_to'	=> $rootComment,
				'ub_user_id'		=> $this->user_id
			],
			__METHOD__,
			$options
		);

		$comments = [];
		while ($row = $results->fetchRow()) {
			$comments[] = $row;
		}

		return array_reverse($comments);
	}

	/**
	 * Checks if a user should be able to view a specific comment
	 *
	 * @access	public
	 * @param   mixed   int id of comment to check, or array row from user_board table
	 * @param   object  [Optional] mw User object, defaults to $wgUser
	 * @return  bool
	 */
	public static function canView($commentId, $user = null) {
		if (is_null($user)) {
			global $wgUser;
			$user = $wgUser;
		}
		//Early check for admin status.
		if ($user->isAllowed('profile-moderate')) {
			return true;
		}

		if (is_array($commentId)) {
			$comment = $commentId;
		} else {
			$comment = self::queryCommentById($commentId);
		}

		//PUBLIC comments visible to all, DELETED comments visible to the author, PRIVATE to author and recipient.
		return $comment['ub_type'] == self::PUBLIC_MESSAGE
			|| ($comment['ub_type'] == self::PRIVATE_MESSAGE && $comment['ub_user_id'] == $user->getId() && $comment['ub_user_id_from'] == $user->getId())
			|| ($comment['ub_type'] == self::DELETED_MESSAGE && $comment['ub_user_id_from'] == $user->getId());
	}

	/**
	 * Checks if a user has permissions to leave a comment
	 *
	 * @param	obj		int user id or mw User object who owns the potential board
	 * @param	obj		[Optional] mw User object for comment author, defaults to $wgUser
	 * @return	bool
	 */
	public static function canComment($toUser, $fromUser = null) {
		global $wgCPEditsToComment;

		if (is_numeric($toUser)) {
			$toUser = \User::newFromId($toUser);
		}
		if (empty($toUser)) {
			return false;
		}
		if (is_null($fromUser)) {
			global $wgUser;
			$fromUser = $wgUser;
		}

		$noEmailAuth = ($wgEmailAuthentication && (!boolval($this->getUser()->getEmailAuthenticationTimestamp()) || !\Sanitizer::validateEmail($this->getUser()->getEmail())));

		//User must be logged in, must not be blocked, and target must not be blocked (with exception for admins).
		return !$noEmailAuth && $fromUser->isLoggedIn() && !$fromUser->isBlocked() && (($fromUser->getEditCount() >= $wgCPEditsToComment && !$toUser->isBlocked()) || $fromUser->isAllowed('block'));
	}

	/**
	 * Add a public comment to the board
	 *
	 * @access	public
	 * @param	string	Comment Text
	 * @param	integer	[Optional] User ID of user posting (defaults to wgUser)
	 * @param	integer	[Optional] ID of a board post that this will be in reply to
	 * @return	integer	ID of the newly created comment, or 0 for failure
	 */
	public function addComment($commentText, $fromUser = null, $inReplyTo = null) {
		$extra = [];
		$commentText = substr(trim($commentText), 0, self::MAX_LENGTH);
		if (empty($commentText)) {
			return false;
		}
		$dbw = CP::getDb(DB_MASTER);

		$toUser = \User::newFromId($this->user_id);
		if (is_null($fromUser)) {
			global $wgUser;
			$fromUser = $wgUser;
		} else {
			$fromUser = \User::newFromId(intval($fromUser));
		}
		if (!self::canComment($toUser, $fromUser)) {
			return false;
		}

		if (is_null($inReplyTo)) {
			$inReplyTo = 0;
		} else {
			$inReplyTo = intval($inReplyTo);
		}

		$success = $dbw->insert(
			'user_board',
			array(
				'ub_in_reply_to' => $inReplyTo,
				'ub_user_id_from' => $fromUser->getId(),
				'ub_user_name_from' => $fromUser->getName(),
				'ub_user_id' => $this->user_id,
				'ub_user_name' => $toUser->getName(),
				'ub_message' => $commentText,
				'ub_type' => self::PUBLIC_MESSAGE,
				'ub_date' => date( 'Y-m-d H:i:s' ),
			),
			__METHOD__
		);

		if ($success) {
			$newCommentId = $dbw->insertId();
		} else {
			$newCommentId = 0;
		}

		if ($newCommentId) {
			$action = 'created';
			$extra['comment_id'] = $newCommentId;

			if ($inReplyTo) {
				$dbw->update(
					'user_board',
					[
						'ub_last_reply' => date('Y-m-d H:i:s')
					],
					['ub_id = ' . $inReplyTo],
					__METHOD__
				);
				$action = 'replied';
			}

			wfRunHooks('CurseProfileAddComment', [$fromUser, $this->user_id, $inReplyTo, $commentText]);

			if ($toUser->getId() != $fromUser->getId()) {
				\EchoEvent::create([
					'type' => 'profile-comment',
					'agent' => $fromUser,
					'title' => $toUser->getUserPage(),
					'extra' => [
						'target_user_id' => $toUser->getId(),
						'comment_text' => substr($commentText, 0, MWEcho\NotificationFormatter::MAX_PREVIEW_LEN),
						'comment_id' => $newCommentId,
					]
				]);
			}

			//Insert an entry into the Log.
			$log = new \LogPage('curseprofile');
			$log->addEntry(
				'comment-'.$action,
				\Title::newFromURL('User:'.$toUser->getName()),
				null,
				$extra,
				$fromUser
			);
		}

		return $newCommentId;
	}

	/**
	 * Checks if a user has permissions to reply to a comment
	 *
	 * @param	mixed	int id of comment to check, or array row from user_board table
	 * @param	obj		[Optional] mw User object, defaults to $wgUser
	 * @return	bool
	 */
	public static function canReply($commentId, $user = null) {
		global $wgCPEditsToComment;

		if (is_null($user)) {
			global $wgUser;
			$user = $wgUser;
		}

		if (is_array($commentId)) {
			$comment = $commentId;
		} else {
			$comment = self::queryCommentById($commentId);
		}

		$boardOwner = \User::newFromId($comment['ub_user_id']);

		// comment must not be deleted and user must be logged in
		return $comment['ub_type'] > self::DELETED_MESSAGE && !$user->isAnon() && !$user->isBlocked() && (($user->getEditCount() >= $wgCPEditsToComment && !$boardOwner->isBlocked()) || $user->isAllowed('block'));
	}

	/**
	 * Replaces the text content of a comment. Permissions are not checked. Use canEdit() to check.
	 *
	 * @param	integer	id of a user board comment
	 * @param	string	new text to use for the comment
	 * @return	bool	true if successful
	 */
	public static function editComment($commentId, $message) {
		global $wgUser;

		$DB = CP::getDb(DB_MASTER);
		$commentId = intval($commentId);

		// Preparing stuff for the Log Entry
		$comment = self::getCommentById($commentId);
		$toUser = \User::newFromId($comment[0]['ub_user_id']);
		$title = \Title::newFromURL('User:'.$toUser->getName());
		$fromUser = $wgUser;
		$extra['comment_id'] = $commentId;

		// Throwing an addition into the edit log
		$log = new \LogPage('curseprofile');
		$log->addEntry(
			'comment-edited',
			$title,
			null,
			$extra,
			$fromUser
		);

		return $DB->update(
			'user_board',
			[
				'ub_message' => $message,
				'ub_edited' => date( 'Y-m-d H:i:s' ),
			],
			['ub_id' => $commentId],
			__METHOD__
		);
	}

	/**
	 * Checks if a user has permissions to edit a comment
	 *
	 * @param	mixed	int id of comment to check, or array row from user_board table
	 * @param	obj		[Optional] mw User object, defaults to $wgUser
	 * @return	bool
	 */
	public static function canEdit($commentId, $user = null) {
		if (is_null($user)) {
			global $wgUser;
			$user = $wgUser;
		}

		if (is_array($commentId)) {
			$comment = $commentId;
		} else {
			$comment = self::queryCommentById($commentId);
		}

		// comment must not be deleted and must be written by this user
		return $comment['ub_type'] > self::DELETED_MESSAGE && $comment['ub_user_id_from'] == $user->getId();
	}

	/**
	 * Remove a comment from the board. Permissions are not checked. Use canRemove() to check.
	 * TODO: if comment is a reply, update the parent's ub_last_reply field (would that behavior be too surprising?)
	 *
	 * @param	integer	ID of the comment to remove.
	 * @param	integer	[Optional] User object of the admin acting, defaults to $wgUser.
	 * @param	string	[Optional] Timestamp in the format of date('Y-m-d H:i:s').
	 * @return	stuff	whatever DB->update() returns
	 */
	public static function removeComment($commentId, $user = null, $time = null) {
		if (is_a($user, 'User')) {
			$curseUser = \CurseAuthUser::getInstance($user);
		} else {
			global $wgUser;

			$curseUser = \CurseAuthUser::getInstance($wgUser);
		}
		if (!$time) {
			$time = date('Y-m-d H:i:s');
		}

		$db = CP::getDb(DB_MASTER);
		return $db->update(
			'user_board',
			[
				'ub_type' => self::DELETED_MESSAGE,
				'ub_admin_acted' => $curseUser->getId(),
				'ub_admin_acted_at' => $time,
			],
			['ub_id' => $commentId]
		);
	}

	/**
	 * Checks if a user has permissions to remove a comment
	 *
	 * @param	mixed	int id of comment to check, or array row from user_board table
	 * @param	obj		[Optional] mw User object, defaults to $wgUser
	 * @return	bool
	 */
	public static function canRemove($commentId, $user = null) {
		if (is_null($user)) {
			global $wgUser;
			$user = $wgUser;
		}

		if (is_array($commentId)) {
			$comment = $commentId;
		} else {
			$comment = self::queryCommentById($commentId);
		}

		// user must not be blocked, comment must either be authored by current user or on user's profile
		return $comment['ub_type'] != self::DELETED_MESSAGE && !$user->isBlocked() &&
			( $comment['ub_user_id'] == $user->getId()
				|| $comment['ub_user_from_id'] == $user->getId()
				|| $user->isAllowed('profile-moderate') );
	}

	/**
	 * Remove a comment from the board. Permissions are not checked. Use canRemove() to check.
	 * TODO: if comment is a reply, update the parent's ub_last_reply field (would that behavior be too surprising?)
	 *
	 * @param	integer	id of a comment to remove
	 * @param	integer	[Optional] curse ID or User instance of the admin acting, defaults to $wgUser
	 * @return	stuff	whatever DB->update() returns
	 */
	public static function restoreComment($commentId) {
		$db = CP::getDb(DB_MASTER);
		return $db->update(
			'user_board',
			[
				'ub_type' => self::PUBLIC_MESSAGE,
				'ub_admin_acted' => null,
				'ub_admin_acted_at' => null,
			],
			[ 'ub_id='.intval($commentId) ]
		);
	}

	/**
	 * Checks if a user has permissions to restore a deleted comment
	 *
	 * @param	mixed	int id of comment to check, or array row from user_board table
	 * @param	obj		[Optional] mw User object, defaults to $wgUser
	 * @return	bool
	 */
	public static function canRestore($commentId, $user = null) {
		if (is_null($user)) {
			global $wgUser;
			$user = $wgUser;
		}

		if (is_array($commentId)) {
			$comment = $commentId;
		} else {
			$comment = self::queryCommentById($commentId);
		}

		// comment must be deleted, user has mod permissions or was the original author and deleter
		return $comment['ub_type'] == self::DELETED_MESSAGE &&
			( $user->isAllowed('profile-moderate')
				|| $comment['ub_user_id'] == $user->getId() && $comment['ub_admin_acted'] == $user->getId() );
	}

	/**
	 * Permanently remove a comment from the board. Permissions are not checked. Use canPurge() to check.
	 *
	 * @param	integer	id of a comment to remove
	 * @return	stuff	whatever DB->update() returns
	 */
	public static function purgeComment($commentId) {
		$db = CP::getDb(DB_MASTER);
		return $db->delete(
			'user_board',
			[ 'ub_id ='.intval($commentId) ]
		);
	}

	/**
	 * Checks if a user has permissions to permanently comments
	 *
	 * @param	obj		[Optional] mw User object, defaults to $wgUser
	 * @return	bool
	 */
	public static function canPurge($user = null) {
		if (is_null($user)) {
			global $wgUser;
			$user = $wgUser;
		} elseif (!is_a($user, 'User')) {
			return false;
		}

		// only Curse group has this right
		return $user->isAllowed('profile-purgecomments');
	}

	/**
	 * Send a comment to the moderation queue. Does not check permissions.
	 *
	 * @param	integer	id of the comment to report
	 * @return	mixed	CommentReport instance or null for failure
	 */
	public static function reportComment($commentId) {
		if ($commentId) {
			return CommentReport::newUserReport($commentId);
		}
	}

	/**
	 * Checks if a user has permissions to report a comment
	 *
	 * @param	mixed	int id of comment to check, or array row from user_board table
	 * @param	obj		[Optional] mw User object, defaults to $wgUser
	 * @return	bool
	 */
	public static function canReport($commentId, $user = null) {
		if (is_null($user)) {
			global $wgUser;
			$user = $wgUser;
		}

		if (is_array($commentId)) {
			$comment = $commentId;
		} else {
			$comment = self::queryCommentById($commentId);
		}

		// user must be logged-in to report, comment must be public (not deleted), and no point in reporting if user can remove it themselves
		return !$user->isAnon() && !$user->isAllowed('profile-moderate') && $comment['ub_user_id_from'] != $user->getId() && $comment['ub_type'] == self::PUBLIC_MESSAGE;
	}

	/**
	 * Filter text through abuse filters.
	 *
	 * @access	private
	 * @param	string	Text to check against abuse filters.
	 * @return	boolean Passed abuse filters.
	 */
	private function checkAbuseFilters($text) {
		/*if (class_exists("AbuseFilterHooks") && method_exists("AbuseFilterHooks::filterEdit")) {
			$status = Status::newGood();

			AbuseFilterHooks::filterEdit($context, null, $text, $status, '', true);

			if (!$status->isOK()) {
				$msg = $status->getErrorsArray();
				$msg = $msg[0];

				// Use the error message key name as error code, the first parameter is the filter description.
				if ($msg instanceof Message) {
					// For forward compatibility: In case we switch over towards using Message objects someday.
					// (see the todo for AbuseFilter::buildStatus)
					$code = $msg->getKey();
					$filterDescription = $msg->getParams();
					$filterDescription = $filterDescription[0];
					$warning = $msg->parse();
				} else {
					$code = array_shift($msg);
					$filterDescription = $msg[0];
					$warning = wfMessage($code)->params($msg)->parse();
				}

				$result = [
					'code' => $code,
					'info' => 'Hit AbuseFilter: '.$filterDescription,
					'warning' => $warning
				];
			}

			return $status->isOK();
		}*/
	}
}
