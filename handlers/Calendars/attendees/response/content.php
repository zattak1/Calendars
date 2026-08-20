<?php

/**
 * Event attendees panel: who came, who invited them, whether they paid.
 *
 * Visibility:
 *  - Regular user: people THEY invited + themselves. Payment info for own invitees.
 *  - Calendars/promoters: all attendees, who invited them, payment for OWN invitees only.
 *  - Calendars/admins or publisher or adminLevel>=manage: everything including all payment.
 *
 * Route: Calendars/attendees/:publisherId/:eventId
 */
function Calendars_attendees_response_content($params)
{
	$user = Users::loggedInUser();
	if (!$user) {
		Q_Response::redirect(Q_Request::baseUrl(true));
		return '';
	}

	$publisherId = Q::ifset($params, 'publisherId',
		Communities::requestedId($params, 'publisherId'));
	$streamName = Q::ifset($params, 'streamName', null);
	if (!$streamName) {
		$eventId = Q::ifset($params, 'eventId',
			Communities::requestedId($params, 'eventId'));
		if ($eventId) {
			$streamName = "Calendars/event/$eventId";
		}
	}
	if (!$publisherId || !$streamName) {
		throw new Q_Exception_RequiredField(array(
			'field' => 'publisherId and streamName (or eventId)'
		));
	}
	$stream = Streams_Stream::fetch($user->id, $publisherId, $streamName);
	if (!$stream) {
		throw new Q_Exception_MissingRow(array(
			'table' => 'stream', 'criteria' => $streamName
		));
	}

	//---- permission level ----

	$isAdmin = ($user->id === $publisherId)
		|| $stream->testAdminLevel('manage');

	if (!$isAdmin) {
		$communityId = $stream->getAttribute('communityId', $publisherId);
		$labels = Users::roles($communityId, array('Calendars/admins'), array(), $user->id);
		if (!empty($labels)) {
			$isAdmin = true;
		}
	}

	$isPromoter = false;
	if (!$isAdmin) {
		$communityId = Q::ifset($communityId, $stream->getAttribute('communityId', $publisherId));
		$labels = Users::roles($communityId, array('Calendars/promoters'), array(), $user->id);
		if (!empty($labels)) {
			$isPromoter = true;
		}
	}

	//---- participants ----

	$participants = Streams_Participant::select()
		->where(array(
			'publisherId' => $publisherId,
			'streamName' => $streamName,
			'state' => 'participating'
		))
		->fetchDbRows(null, '', 'userId');

	$userIds = array_keys($participants);
	if (empty($userIds)) {
		return _Calendars_attendees_render(
			array(), array('total'=>0,'going'=>0,'paid'=>0,'totalCharged'=>0),
			$stream, $isAdmin, $isPromoter, $user
		);
	}

	//---- avatars ----

	$avatars = Streams_Avatar::fetch($user->id, $userIds, 'publisherId');
	// For admins: also fetch as the community so firstName/lastName are
	// readable (the migration script grants readLevel=40 to Users/admins
	// on Streams/user/firstName and Streams/user/lastName).

	//---- who invited whom ----

	$invitesByUser = array();

	$personalInvites = Streams_Invite::select()
		->where(array(
			'publisherId' => $publisherId,
			'streamName' => $streamName,
			'state' => 'accepted',
			'userId' => $userIds
		))
		->fetchDbRows();
	foreach ($personalInvites as $inv) {
		$invitesByUser[$inv->userId] = $inv->invitingUserId;
	}

	$generalInvites = Streams_Invite::select()
		->where(array(
			'publisherId' => $publisherId,
			'streamName' => $streamName,
			'userId' => ''
		))
		->fetchDbRows();
	if ($generalInvites) {
		$tokens = array();
		$tokenToInviter = array();
		foreach ($generalInvites as $gi) {
			$tokens[] = $gi->token;
			$tokenToInviter[$gi->token] = $gi->invitingUserId;
		}
		$invitedRows = Streams_Invited::select()
			->where(array('token' => $tokens, 'state' => 'accepted'))
			->fetchDbRows();
		foreach ($invitedRows as $ir) {
			if (!isset($invitesByUser[$ir->userId])
			and isset($tokenToInviter[$ir->token])) {
				$invitesByUser[$ir->userId] = $tokenToInviter[$ir->token];
			}
		}
	}

	//---- payment data (credits → currency) ----

	$charges = array(); // userId => amount in display currency
	$currency = 'USD';
	$exchangeRate = 100; // default: 100 credits = $1
	if (class_exists('Assets_Credits')) {
		$exchangeRate = Q_Config::get('Assets', 'credits', 'exchange', 'USD', 100);
		$currency = Q_Config::get('Assets', 'credits', 'exchange', '_default', 'USD');
		if ($currency === 'USD') {
			$exchangeRate = Q_Config::get('Assets', 'credits', 'exchange', 'USD', 100);
		} else {
			$exchangeRate = Q_Config::get('Assets', 'credits', 'exchange', $currency, 100);
		}

		$creditRows = Assets_Credits::select()
			->where(array(
				'toPublisherId' => $publisherId,
				'toStreamName' => $streamName
			))
			->fetchDbRows();
		foreach ($creditRows as $cr) {
			$uid = Q::ifset($cr->fields, 'fromUserId', null);
			if ($uid && in_array($uid, $userIds)) {
				if (!isset($charges[$uid])) { $charges[$uid] = 0; }
				$charges[$uid] += floatval($cr->amount);
			}
		}
	}

	//---- inviter avatars ----

	$inviterIds = array_unique(array_values($invitesByUser));
	$inviterAvatars = $inviterIds
		? Streams_Avatar::fetch($user->id, $inviterIds, 'publisherId')
		: array();

	//---- build rows ----

	$rows = array();
	$summaryGoing = 0;
	$summaryPaid = 0;
	$summaryCharged = 0;

	foreach ($participants as $uid => $participant) {
		$going = $participant->getExtra('going', 'no');
		if ($going === 'yes') { ++$summaryGoing; }

		$invitedBy = Q::ifset($invitesByUser, $uid, null);
		$isMine = ($invitedBy === $user->id);
		$credits = Q::ifset($charges, $uid, 0);
		$displayAmount = $exchangeRate > 0 ? ($credits / $exchangeRate) : $credits;

		if (!$isAdmin && !$isPromoter && !$isMine && $uid !== $user->id) {
			continue;
		}

		$canSeePayment = $isAdmin || $isMine;

		$avatar = Q::ifset($avatars, $uid, null);
		$inviterAvatar = $invitedBy
			? Q::ifset($inviterAvatars, $invitedBy, null) : null;

		$row = array(
			'userId' => $uid,
			'displayName' => $avatar
				? $avatar->displayName($isAdmin ? array() : array('short' => true)) : $uid,
			'icon' => $avatar ? $avatar->iconUrl(40) : null,
			'going' => $going,
			'invitedBy' => $invitedBy,
			'inviterName' => $inviterAvatar
				? $inviterAvatar->displayName($isAdmin ? array() : array('short' => true)) : null,
			'isMine' => $isMine,
			'paid' => $canSeePayment ? $displayAmount : null,
			'paidCredits' => $canSeePayment ? $credits : null,
			'canSeePayment' => $canSeePayment
		);

		if ($canSeePayment && $credits > 0) {
			++$summaryPaid;
			$summaryCharged += $displayAmount;
		}

		$rows[] = $row;
	}

	usort($rows, function ($a, $b) {
		if ($a['isMine'] !== $b['isMine']) {
			return $b['isMine'] ? 1 : -1;
		}
		return strcmp($a['displayName'], $b['displayName']);
	});

	$summary = array(
		'total' => count($rows),
		'going' => $summaryGoing,
		'paid' => $summaryPaid,
		'totalCharged' => $summaryCharged,
		'currency' => $currency
	);

	return _Calendars_attendees_render(
		$rows, $summary, $stream, $isAdmin, $isPromoter, $user
	);
}

function _Calendars_attendees_render($rows, $summary, $stream, $isAdmin, $isPromoter, $user)
{
	Q_Response::addStylesheet('{{Calendars}}/css/attendees.css', 'Calendars');
	Q_Response::setSlot('title', $stream->title . ' — Attendees');

	Q_Response::addScript('{{Calendars}}/js/attendees-sort.js', 'Calendars');

	return Q::view('Calendars/content/attendees.php', @compact(
		'rows', 'summary', 'stream', 'isAdmin', 'isPromoter', 'user'
	));
}
