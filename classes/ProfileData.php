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
 * Class for reading and saving custom user-set profile data
 */
class ProfileData {
	/**
	 * @var		integer
	 */
	protected $user_id;

	/**
	 * @var		object
	 */
	protected $user;

	public static function insertProfilePrefs(&$preferences) {
		$wikiOptions = [
			'---' => '',
		];
		$wikiSites = self::getWikiSites();
		if ($wikiSites) {
			foreach ($wikiSites['data']['wikis'] as $wiki) {
				$wikiOptions[$wiki['wiki_name']] = $wiki['md5_key'];
			}
		}

		$preferences['profile-pref'] = [
			'type' => 'select',
			'label-message' => 'profileprefselect',
			'section' => 'personal/info/public',
			'options' => [
				wfMessage('profilepref-profile')->plain() => 1,
				wfMessage('profilepref-wiki')->plain() => 0,
			],
		];
		$preferences['profile-favwiki'] = [
			'type' => 'select',
			'label-message' => 'favoritewiki',
			'section' => 'personal/info/public',
			'options' => $wikiOptions,
		];
		$preferences['profile-aboutme'] = [
			'type' => 'textarea',
			'label-message' => 'aboutme',
			'section' => 'personal/info/public',
			'rows' => 6,
			'placeholder' => wfMessage('aboutmeplaceholder')->plain(),
		];
		$preferences['profile-city'] = [
			'type' => 'text',
			'label-message' => 'citylabel',
			'section' => 'personal/info/location',
		];
		$preferences['profile-state'] = [
			'type' => 'text',
			'label-message' => 'statelabel',
			'section' => 'personal/info/location',
		];
		$preferences['profile-country'] = [
			'type' => 'text',
			'label-message' => 'countrylabel',
			'section' => 'personal/info/location',
		];
		$preferences['profile-link-steam'] = [
			'type' => 'text',
			'label-message' => 'steamlink',
			'section' => 'personal/info/profiles',
			'placeholder' => wfMessage('steamlinkplaceholder')->plain(),
		];
		$preferences['profile-link-xbl'] = [
			'type' => 'text',
			'label-message' => 'xbllink',
			'section' => 'personal/info/profiles',
			'placeholder' => wfMessage('xbllinkplaceholder')->plain(),
		];
		$preferences['profile-link-psn'] = [
			'type' => 'text',
			'label-message' => 'psnlink',
			'section' => 'personal/info/profiles',
			'placeholder' => wfMessage('psnlinkplaceholder')->plain(),
		];


		$preferences['commentemail'] = [
			'type' => 'check',
			'label-message' => 'commentemailpref',
			'section' => 'personal/email'
		];
		$preferences['friendreqemail'] = [
			'type' => 'check',
			'label-message' => 'friendreqemailpref',
			'section' => 'personal/email'
		];
	}

	public static function insertProfilePrefsDefaults(&$defaultOptions) {
		$defaultOptions['commentemail']   = 1;
		$defaultOptions['friendreqemail'] = 1;

		// Allow overriding by setting the value in the global $wgDefaultUserOptions
		if (!isset($defaultOptions['profile-pref'])) {
			$defaultOptions['profile-pref'] = 1;
		}
	}

	public function __construct($user_id) {
		$this->user_id = intval($user_id);
		if ($this->user_id < 1) {
			// if a user hasn't saved a profile yet, just use the default values
			$this->user_id = 0;
		}
		$this->user = \User::newFromId($user_id);
	}

	public function getAboutText() {
		return $this->user->getOption('profile-aboutme');
	}

	public function getLocations() {
		$profile = [
			'city' => $this->user->getOption('profile-city'),
			'state' => $this->user->getOption('profile-state'),
			'country' => $this->user->getOption('profile-country'),
		];
		return array_filter($profile);
	}

	public function getProfileLinks() {
		$profile = [
			'Steam' => $this->user->getOption('profile-link-steam'),
			'XBL' => $this->user->getOption('profile-link-xbl'),
			'PSN' => $this->user->getOption('profile-link-psn'),
		];
		return array_filter($profile);
	}

	public function getFavoriteWikiHash() {
		return $this->user->getOption('profile-favwiki');
	}

	public function getFavoriteWiki() {
		$mouse = CP::loadMouse(['curl' => 'mouseTransferCurl']);
		$sites = self::getWikiSites();
		if ($sites) {
			foreach ($sites['data']['wikis'] as $wiki) {
				if ($wiki['md5_key'] == $this->user->getOption('profile-favwiki')) {
					return $wiki;
				}
			}
		}
		return [];
	}

	public static function getWikiSites() {
		global $wgServer;
		$mouse = CP::loadMouse();
		$jsonSites = $mouse->curl->fetch($wgServer.'/extensions/AllSites/api.php?action=siteInformation&task=getSiteStats');
		return json_decode($jsonSites, true);
	}

	/**
	 * Returns true if the profile page should be used, false if the wiki should be used
	 */
	public function getTypePref() {
		return $this->user->getIntOption('profile-pref');
	}


	public function toggleTypePref() {
		$this->user->setOption('profile-pref', !$this->getTypePref());
		$this->user->saveSettings();
	}
}
