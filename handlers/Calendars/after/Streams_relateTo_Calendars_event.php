<?php
function Calendars_after_Streams_relateTo_Calendars_event ($params) {
	$event = $params['category'];
	$toPublisherId = $event->publisherId;
	$toStreamName = $event->name;

	$stream = $params['stream'];
	$fromPublisherId = $stream->publisherId;
	$fromStreamName = $stream->name;

	// check if related stream type belong to the list of paid stream types
	$fromStreamType = $stream->type;
	$paidStreamTypes = Q_Config::get("Assets", "service", "relatedParticipants", null);
	if (!is_array($paidStreamTypes) || !in_array($fromStreamType, array_keys($paidStreamTypes))) {
		return true;
	}

	// check if stream payment required
	$amount = Q::ifset($event->getAttribute("payment"), "amount", null);
	$currency = Q::ifset($event->getAttribute("payment"), "currency", null);
	$isPublisher = $stream->publisherId == $event->publisherId;
	$isAdmin = Calendars_Event::isAdmin($stream->publisherId, $event->getAttribute("communityId"));
	if (!$amount || !$currency) {
		return true;
	}

	// if user not participating to event, don't spend credits
	// will spend when user participated
	$participant = new Streams_Participant();
	$participant->publisherId = $event->publisherId;
	$participant->streamName = $event->name;
	$participant->userId = $stream->publisherId;
	$participant->state = 'participating';
	if (!$participant->retrieve()) {
		return true;
	}

	if ($isPublisher || $isAdmin) {
		Streams_Message::post($stream->publisherId, $stream->publisherId, "Calendars/user/reminders", array(
			"type" => "Calendars/payment/skip",
			"instructions" => array(
				"publisherId" => $event->publisherId,
				"streamName" => $event->name,
				"reason" => $isPublisher ? "publisher" : "admin"
			)
		), true);

		return true;
	}

	if (class_exists("Assets_Credits")) {
		// The participant who owns the related stream (checked above to be
		// participating) pays the event's publisher for it, in the main
		// community's credits, as Calendars_Event::going() pays for the
		// participant's own place. The ledger row records the related stream
		// as fromPublisherId/fromStreamName, which is what getPaymentsInfo()
		// and going()'s related-participants check look for.
		$fromUserId = $stream->publisherId;
		// Never charge someone for a relation another user made
		$asUserId = Q::ifset($params, 'asUserId', null);
		if (!isset($asUserId)) {
			$asUserId = Q::ifset(Users::loggedInUser(), 'id', null);
		}
		if ((string)$asUserId !== (string)$fromUserId) {
			throw new Users_Exception_NotAuthorized();
		}
		$relatedStream = array('publisherId' => $fromPublisherId, 'streamName' => $fromStreamName);
		if (Assets_Credits::getPaymentsInfo($fromUserId, $event, $relatedStream)["conclusion"]["fullyPaid"]) {
			return true;
		}
		$needCredits = Assets_Credits::convert($amount, $currency, "credits");
		// spend() does not buy missing credits (autoCharge is Assets::pay()'s
		// option): it throws Assets_Exception_NotEnoughCredits, after the
		// relation has been saved.
		Assets_Credits::spend(
			Users::communityId(),
			$needCredits,
			Assets::JOINED_PAID_STREAM,
			$fromUserId,
			@compact("toPublisherId", "toStreamName", "fromPublisherId", "fromStreamName")
		);
	}
}