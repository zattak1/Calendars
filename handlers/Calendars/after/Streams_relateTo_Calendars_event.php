<?php
function Calendars_after_Streams_relateTo_Calendars_event ($params) {
	$event = $params['category'];
	$stream = $params['stream'];

	// The charge for a paid participant stream was made by
	// Calendars/before/Streams_relateTo_Calendars_event, inside a
	// transaction that Streams::relate() has now inserted the relation into:
	// commit both together (ro#1039).
	// It is rolled back instead if the relation is not there: relate() fires
	// this hook even for a stream a relateFrom before hook vetoed.
	Calendars_Event::settleChargeBeforeRelating($event, $stream, $params['type'], true);

	// check if related stream type belong to the list of paid stream types
	$fromStreamType = $stream->type;
	$paidStreamTypes = Q_Config::get("Assets", "service", "relatedParticipants", null);
	if (!is_array($paidStreamTypes) || !in_array($fromStreamType, array_keys($paidStreamTypes))) {
		return true;
	}

	// check if stream payment required
	$amount = Q::ifset($event->getAttribute("payment"), "amount", null);
	$currency = Q::ifset($event->getAttribute("payment"), "currency", null);
	if (!$amount || !$currency) {
		return true;
	}
	$isPublisher = $stream->publisherId == $event->publisherId;
	$isAdmin = Calendars_Event::isAdmin($stream->publisherId, $event->getAttribute("communityId"));

	// if user not participating to event, nothing was charged
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
	}

	return true;
}
