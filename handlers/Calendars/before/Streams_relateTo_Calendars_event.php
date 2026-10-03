<?php

/**
 * Charges the owner of a paid participant stream (a pet, a child) for relating
 * it to a paid event, BEFORE the relation is inserted, in the same database
 * transaction as that insert (ro#1039).
 *
 * The charge used to run in the after hook, once the relation row had been
 * committed: a refused charge (Assets_Exception_NotEnoughCredits) left the
 * stream related and unpaid. Now this hook opens a transaction and spends
 * inside it; Streams::relate() inserts the relation into the same
 * transaction; and Calendars/after/Streams_relateTo_Calendars_event commits
 * both together. So:
 *
 * - a refused charge throws from here, after rolling back: no relation is
 *   inserted and no credits move;
 * - a failed insert (the duplicate key a concurrent relate of the same stream
 *   gets) rolls back the charge with it -- Db_Query_Mysql::execute() rolls
 *   back the connection on a failed statement -- so only one of two racing
 *   relates pays;
 * - any other exception before the commit leaves the transaction open, and
 *   the next failed statement or the shutdown rollback undoes both
 *   (Calendars_Event::relateParticipants() rolls back at once).
 *
 * One transaction needs one DSN: the relation and the balance streams are
 * Streams rows, so they always share it; the ledger row (Assets connection)
 * does too wherever Assets and Streams share a database, as in our apps.
 *
 * @param {array} $params Streams::relate()'s before-hook parameters
 * @throws Users_Exception_NotAuthorized for a relation someone other than
 *   the owner makes, when it would be charged
 * @throws Assets_Exception_NotEnoughCredits
 */
function Calendars_before_Streams_relateTo_Calendars_event($params)
{
	$event = $params['category'];
	$stream = $params['stream'];

	// paid participant types only
	$paidStreamTypes = Q_Config::get("Assets", "service", "relatedParticipants", null);
	if (!is_array($paidStreamTypes) || !in_array($stream->type, array_keys($paidStreamTypes))) {
		return;
	}

	// a priced event only
	$amount = Q::ifset($event->getAttribute("payment"), "amount", null);
	$currency = Q::ifset($event->getAttribute("payment"), "currency", null);
	if (!$amount || !$currency) {
		return;
	}

	// if user not participating to event, don't spend credits:
	// Calendars_Event::going() charges for related streams when they join
	$participant = new Streams_Participant();
	$participant->publisherId = $event->publisherId;
	$participant->streamName = $event->name;
	$participant->userId = $stream->publisherId;
	$participant->state = 'participating';
	if (!$participant->retrieve()) {
		return;
	}

	// the publisher and admins don't pay; the after hook tells them so
	$isPublisher = $stream->publisherId == $event->publisherId;
	$isAdmin = Calendars_Event::isAdmin($stream->publisherId, $event->getAttribute("communityId"));
	if ($isPublisher || $isAdmin || !class_exists("Assets_Credits")) {
		return;
	}

	// The participant who owns the related stream (checked above to be
	// participating) pays the event's publisher for it, in the main
	// community's credits, as Calendars_Event::going() pays for the
	// participant's own place. The ledger row records the related stream
	// as fromPublisherId/fromStreamName, which is what getPaymentsInfo()
	// and going()'s related-participants check look for.
	$fromUserId = $stream->publisherId;
	// Never charge someone for a relation another user made: refuse it
	$asUserId = Q::ifset($params, 'asUserId', null);
	if (!isset($asUserId)) {
		$asUserId = Q::ifset(Users::loggedInUser(), 'id', null);
	}
	if ((string)$asUserId !== (string)$fromUserId) {
		throw new Users_Exception_NotAuthorized();
	}
	$relatedStream = array('publisherId' => $stream->publisherId, 'streamName' => $stream->name);
	if (Assets_Credits::getPaymentsInfo($fromUserId, $event, $relatedStream)["conclusion"]["fullyPaid"]) {
		return;
	}

	Calendars_Event::chargeBeforeRelating($event, $stream, $fromUserId,
		Assets_Credits::convert($amount, $currency, "credits")
	);
}
