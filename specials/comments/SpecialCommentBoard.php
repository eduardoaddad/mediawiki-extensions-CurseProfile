<?php
/**
 * Curse Inc.
 * Curse Profile
 * A modular, multi-featured user profile system.
 *
 * @author		Noah Manneschmidt
 * @copyright	(c) 2014 Curse Inc.
 * @license		All Rights Reserved
 * @package		CurseProfile
 * @link		http://www.curse.com/
 *
**/
namespace CurseProfile;

class SpecialCommentBoard extends \UnlistedSpecialPage {
	public function __construct() {
		parent::__construct( 'CommentBoard' );
	}

	/**
	 * Show the special page
	 *
	 * @param $params Mixed: parameter(s) passed to the page or null
	 */
	public function execute( $path ) {
		$wgRequest = $this->getRequest();
		$wgOut = $this->getOutput();
		$this->setHeaders();
		if (empty($path)) {
			$wgOut->addWikiMsg('commentboard-invalid');
			$wgOut->setStatusCode(404);
			return;
		}

		// parse path segment for special page url similar to:
		// /Special:CommentBoard/4/Cathadan
		list($user_id, $user_name) = explode('/', $path);
		$user = \User::newFromId($user_id);
		$user->load();
		if (!$user || $user->isAnon()) {
			$wgOut->addWikiMsg('commentboard-invalid');
			$wgOut->setStatusCode(404);
			return;
		}

		// Fix missing or incorrect username segment in the path
		if ($user->getName() != $user_name) {
			$fixedPath = '/Special:CommentBoard/'.$user_id.'/'.$user->getTitleKey();
			if (!empty($_SERVER['QUERY_STRING'])) { // don't destroy any extra params
				$fixedPath .= '?'.$_SERVER['QUERY_STRING'];
			}
			$wgOut->redirect($fixedPath);
			return;
		}

		$start = $wgRequest->getInt('st');
		$itemsPerPage = 50;
		$mouse = CP::loadMouse(['output' => 'mouseOutputOutput']);
		$wgOut->setPageTitle(wfMessage('commentboard-title', $user->getName())->plain());
		$wgOut->addModules('ext.curseprofile.profilepage');
		$mouse->output->addTemplateFolder(dirname(dirname(__DIR__)).'/templates');
		$mouse->output->loadTemplate('commentboard');

		$wgOut->addHTML($mouse->output->commentboard->header($user, $wgOut->getPageTitle()));

		$board = new CommentBoard($user_id);

		$total = $board->countComments();
		if ($total == 0) {
			$wgOut->addWikiMsg('commentboard-empty');
			return;
		}

		$comments = $board->getComments(null, $start, $itemsPerPage, -1);
		$pagination = $mouse->output->generatePagination($total, $itemsPerPage, $start);
		$pagination = $mouse->output->paginationTemplate($pagination);

		$wgOut->addHTML($mouse->output->commentboard->comments($comments, $user_id, $pagination));

		return;
	}
}
