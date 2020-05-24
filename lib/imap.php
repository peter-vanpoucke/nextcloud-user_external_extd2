<?php
/**
 * @author Robin Appelman <icewind@owncloud.com>
 * @author Jonas Sulzer <jonas@violoncello.ch>
 * @copyright (c) 2012 Robin Appelman <icewind@owncloud.com>
 * This file is licensed under the Affero General Public License version 3 or
 * later.
 * See the COPYING-README file.
 */

/**
 * User authentication against an IMAP mail server
 *
 * @category Apps
 * @package  UserExternal
 * @author   Robin Appelman <icewind@owncloud.com>
 * @license  http://www.gnu.org/licenses/agpl AGPL
 * @link     http://github.com/owncloud/apps
 */
class OC_User_IMAP_extd2 extends \OCA\user_external_extd2\Base {
	private $mailbox;
	private $port;
	private $sslmode;
	private $domain;
	private $stripeDomain;
	private $groupDomain;
	private $domainSplitter;

	/**
	 * Create new IMAP authentication provider
	 *
	 * @param string $mailbox IMAP server domain/IP
	 * @param int $port IMAP server $port
	 * @param string $sslmode
	 * @param string $domain  If provided, loging will be restricted to this domain
	 * @param boolean $stripeDomain (whether to stripe the domain part from the username or not)
	 * @param boolean $groupDomain (whether to add the usere to a group corresponding to the domain of the address)
	 * @param boolean $domainSplitter (consider this the domain splitter, default '@')
	 */
	public function __construct($mailbox, $port = null, $sslmode = null, $domain = null, $stripeDomain = true, $groupDomain = false, $domainSplitter = null) {
		parent::__construct($mailbox);
		$this->mailbox = $mailbox;
		$this->port = $port === null ? 143 : $port;
		$this->sslmode = $sslmode;
		$this->domain = $domain === null ? '' : $domain;
		$this->stripeDomain = $stripeDomain;
		$this->groupDomain = $groupDomain;
		$this->domainSplitter = $domainSplitter === null ? '@' : $domainSplitter;
	}

	/**
	 * Check if the password is correct without logging in the user
	 *
	 * @param string $uid      The username
	 * @param string $password The password
	 *
	 * @return true/false
	 */
	public function checkPassword($uid, $password) {
		$domainSplitterEncoded = urlencode($this->domainSplitter);

		// Replace escaped splitter in uid
		// but only if there is no splitter symbol and if there is a escaped splitter inside the uid
		if (($this->domainSplitter != $domainSplitterEncoded) 
				&& !(strpos($uid, $this->domainSplitter) !== false) 
				&& (strpos($uid, $domainSplitterEncoded) !== false)) {
			$uid = str_replace($domainSplitterEncoded,$this->domainSplitter,$uid);
		}

		$pieces = explode($this->domainSplitter, $uid);
		if ($this->domain !== '') {
			if (count($pieces) === 1) {
				$username = $uid . $this->domainSplitter . $this->domain;
			} else if(count($pieces) === 2 && $pieces[1] === $this->domain) {
				$username = $uid;
				if ($this->stripeDomain) {
					$uid = $pieces[0];
				}
			} else {
				OC::$server->getLogger()->error(
					'ERROR: User has a wrong domain! Expecting: '.$this->domain,
					['app' => 'user_external']
				);
				return false;
			}
		} else {
			$username = $uid;
 		}

		$groups = [];
		if ($this->groupDomain && $pieces[1]) {
					$groups[] = $pieces[1];
		}

		$protocol = ($this->sslmode === "ssl") ? "imaps" : "imap";
		$url = "{$protocol}://{$this->mailbox}:{$this->port}";
		$ch = curl_init();
		if ($this->sslmode === 'tls') {
			curl_setopt($ch, CURLOPT_USE_SSL, CURLUSESSL_ALL);
		}
		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_USERPWD, $username.":".$password);
		curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
		curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'CAPABILITY');

		$canconnect = curl_exec($ch);

		if($canconnect) {
			curl_close($ch);
			$uid = mb_strtolower($uid);
			$this->storeUser($uid, $groups);
			return $uid;
		} else {
			OC::$server->getLogger()->error(
				'ERROR: Could not connect to imap server via curl: '.curl_error($ch),
				['app' => 'user_external']
			);
		}

		curl_close($ch);

		return false;
	}
}
