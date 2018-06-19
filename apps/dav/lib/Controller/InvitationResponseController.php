<?php
/**
 * @copyright 2018, Georg Ehrke <oc.list@georgehrke.com>
 *
 * @author Georg Ehrke <oc.list@georgehrke.com>
 *
 * @license GNU AGPL version 3 or any later version
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 */
namespace OCA\DAV\Controller;

use OCA\DAV\CalDAV\InvitationResponse\InvitationResponseServer;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IDBConnection;
use OCP\IRequest;
use Sabre\VObject\ITip\Message;
use Sabre\VObject\Reader;

class InvitationResponseController extends Controller {

	/** @var IDBConnection */
	private $db;

	/**
	 * InvitationResponseController constructor.
	 *
	 * @param string $appName
	 * @param IRequest $request
	 * @param IDBConnection $db
	 */
	public function __construct(string $appName, IRequest $request,
								IDBConnection $db) {
		parent::__construct($appName, $request);
		$this->db = $db;
	}

	/**
	 * @PublicPage
	 * @NoCSRFRequired
	 *
	 * @param {string} $token
	 * @return TemplateResponse
	 */
	public function accept($token) {
		$row = $this->getTokenInformation($token);
		if (!$row) {
			// TODO show error message
			return new TemplateResponse($this->appName, 'schedule-response-error-page');
		}

		$iTipMessage = $this->buildITipResponse($row, 'ACCEPTED');
		$this->handleITipMessage($iTipMessage);
		// TODO: check $iTipMessage->scheduleStatus

		if ($iTipMessage->getScheduleStatus() === '1.2') {
			return new TemplateResponse($this->appName, 'schedule-response-success');
		}

		return new TemplateResponse($this->appName, 'schedule-response-error');
	}

	/**
	 * @PublicPage
	 * @NoCSRFRequired
	 *
	 * @param {string} $token
	 * @return TemplateResponse
	 */
	public function decline($token) {
		$row = $this->getTokenInformation($token);
		if (!$row) {
			// TODO show error message
			return new TemplateResponse($this->appName, 'schedule-response-error-page');
		}

		$iTipMessage = $this->buildITipResponse($row, 'DECLINED');
		$this->handleITipMessage($iTipMessage);
		// TODO: check $iTipMessage->scheduleStatus

		if ($iTipMessage->getScheduleStatus() === '1.2') {
			return new TemplateResponse($this->appName, 'schedule-response-success');
		}

		return new TemplateResponse($this->appName, 'schedule-response-error');
	}

	/**
	 * @PublicPage
	 * @NoCSRFRequired
	 *
	 * @param {string} $token
	 * @return TemplateResponse
	 */
	public function tentative($token) {
		$row = $this->getTokenInformation($token);
		if (!$row) {
			// TODO show error message
			return new TemplateResponse($this->appName, 'schedule-response-error');
		}

		$iTipMessage = $this->buildITipResponse($row, 'TENTATIVE');
		$this->handleITipMessage($iTipMessage);
		// TODO: check $iTipMessage->scheduleStatus

		if ($iTipMessage->getScheduleStatus() === '1.2') {
			return new TemplateResponse($this->appName, 'schedule-response-success');
		}

		return new TemplateResponse($this->appName, 'schedule-response-error');
	}

	/**
	 * @PublicPage
	 * @NoCSRFRequired
	 *
	 * @param {string} $token
	 * @param {string} $attendee
	 * @return TemplateResponse
	 */
	public function options($token, $attendee) {

	}

	/**
	 * @param string $token
	 * @return array|null
	 */
	private function getTokenInformation($token):array {
		$query = $this->db->getQueryBuilder();
		$query->select('*')
			->from('calendar_invitation_tokens')
			->where($query->expr()->eq('token', $query->createNamedParameter($token)));
		$stmt = $query->execute();
		$row = $stmt->fetch(\PDO::FETCH_ASSOC);

		if(!$row) {
			return null;
		}

		return $row;
	}

	/**
	 * @param array $row
	 * @param string $partStat participation status of attendee - SEE RFC 5545
	 * @return Message
	 */
	private function buildITipResponse(array $row, string $partStat):Message {
		$iTipMessage = new Message();
		$iTipMessage->uid = $row['uid'];
		$iTipMessage->component = 'VEVENT';
		$iTipMessage->method = 'REPLY';
		$iTipMessage->sequence = $row['sequence'];
		$iTipMessage->sender = $row['attendee'];
		$iTipMessage->recipient = $row['organizer'];

		$message = <<<EOF
BEGIN:VCALENDAR
PRODID:-//Nextcloud/Nextcloud CalDAV Server//EN
METHOD:REPLY
VERSION:2.0
BEGIN:VEVENT
ATTENDEE;PARTSTAT=%s:%s
ORGANIZER:%s
UID:%
SEQUENCE:%
REQUEST-STATUS:2.0;Success
END:VEVENT
END:VCALENDAR
EOF;

		$vObject = Reader::read(vsprintf($message, [
			$partStat, $row['attendee'], $row['organizer'],
			$row['uid'], $row['sequence'] ?? 0,
		]));
		$vObject->{'VEVENT'}->DTSTAMP = date('Ymd\\THis\\Z');

		if ($row['recurrenceid']) {
			$vObject->{'VEVENT'}->{'RECURRENCE-ID'} = $row['recurrenceid'];
		}

		$iTipMessage->message = $vObject;

		return $iTipMessage;
	}

	/**
	 * @param Message $iTipMessage
	 * @return void
	 */
	private function handleITipMessage(Message $iTipMessage) {
		$server = new InvitationResponseServer(\OC::$WEBROOT . '/remote.php/dav/');
		// Don't run `$server->exec()`, because we just need access to the
		// fully initialized schedule plugin, but we don't want Sabre/DAV
		// to actually reply to the request

		/** @var \OCA\DAV\CalDAV\Schedule\Plugin $schedulingPlugin */
		$schedulingPlugin = $server->server->getPlugin('caldav-schedule');
		$schedulingPlugin->scheduleLocalDelivery($iTipMessage);
	}
}
