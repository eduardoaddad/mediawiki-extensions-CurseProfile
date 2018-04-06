<?php
/**
 * Curse Inc.
 * Curse Profile
 * A modular, multi-featured user profile system.
 *
 * @author		Noah Manneschmidt
 * @copyright	(c) 2015 Curse Inc.
 * @license		All Rights Reserved
 * @package		CurseProfile
 * @link		http://www.curse.com/
 *
**/
namespace CurseProfile;

class SpecialCommentModeration extends \HydraCore\SpecialPage {
	public function __construct() {
		parent::__construct( 'CommentModeration', 'profile-moderate' );
	}

	/**
	 * Return the group name for this special page.
	 *
	 * @access	protected
	 * @return	string
	 */
	protected function getGroupName() {
		return 'users';
	}

	private $sortStyle;

	/**
	 * Show the special page
	 *
	 * @param $params Mixed: parameter(s) passed to the page or null
	 */
	public function execute( $sortBy ) {
		$this->checkPermissions();
		$wgRequest = $this->getRequest();

		$this->output->setPageTitle(wfMessage('commentmoderation-title')->plain());
		$this->output->addModules('ext.curseprofile.commentmoderation');
		$this->output->addModules('ext.hydraCore.pagination.styles');
		$templateCommentModeration = new \TemplateCommentModeration;
		$this->setHeaders();

		$this->sortStyle = $sortBy;
		if (!$this->sortStyle) {
			$this->sortStyle = 'byVolume';
		}

		$start = $wgRequest->getInt('st');
		$itemsPerPage = 25;

		$total = $this->countModQueue();

		if (!$total) {
			$this->output->addWikiMsg('commentmoderation-empty');
			return;
		} else {
			$content = $templateCommentModeration->renderComments(CommentReport::getReports($this->sortStyle, $itemsPerPage, $start));
		}

		$pagination = \HydraCore::generatePaginationHtml($this->getFullTitle(), $total, $itemsPerPage, $start);

		$this->output->addHTML($templateCommentModeration->sortStyleSelector($this->sortStyle));
		$this->output->addHTML($pagination);
		$this->output->addHTML($content);
		$this->output->addHTML($pagination);
	}

	private function countModQueue() {
		// TODO: pass extra param for byWiki or byUser
		return CommentReport::getCount($this->sortStyle);
	}
}
