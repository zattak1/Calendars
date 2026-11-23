<?php

/**
 * A card payment made to book an event was fully refunded, or lost in a
 * dispute (ro#1246): cancel that booking so the member leaves and the seat
 * is freed. Assets::refunded() has already taken back the payment's credits;
 * leaving gives back the booking's credits (Calendars_after_Streams_leave,
 * LeftPaidStream), which nets the member's balance to exactly the refund.
 *
 * Only the event the payment was made for (the target of the Users_Intent it
 * continued) is cancelled, never the member's other bookings, and only if
 * it has not started: after the start there is no seat to free and leaving
 * refunds nothing, so the member keeps the booking and the balance stays
 * below zero, which blocks spending until it is back to zero (owner's
 * policy, 2026-11-19). A partial refund cancels nothing. Nor is the booking
 * cancelled when its host could not return the credits paid for it.
 */
function Calendars_after_Assets_refunded($params)
{
	$userId = Q::ifset($params, 'userId', null);
	$publisherId = Q::ifset($params, 'toPublisherId', null);
	$streamName = Q::ifset($params, 'toStreamName', null);
	if (empty($params['fullyReversed']) or !$userId or !$publisherId
	or !Q::startsWith((string)$streamName, 'Calendars/event/')) {
		return;
	}
	$stream = Streams_Stream::fetch($publisherId, $publisherId, $streamName);
	if (!$stream) {
		return;
	}
	$start = (int)$stream->getAttribute('startTime');
	if (!$start or $start <= time()) {
		return;
	}
	if (Calendars_Event::getGoing($stream, $userId) === 'no') {
		return;
	}
	// Leaving returns the booking's credits from the host. If the host can't
	// cover them, cancelling would put the member out of the class AND
	// leave the whole refund debited: keep the booking and say so instead.
	$payments = Assets_Credits::getPaymentsInfo($userId, array(
		'publisherId' => $publisherId,
		'streamName' => $streamName
	));
	$paid = (float)Q::ifset($payments, 'conclusion', 'amount', 0);
	$hostId = Q::ifset($payments, 'conclusion', 'toUserId', $publisherId);
	if ($paid > 0 and Assets_Credits::amount(null, $hostId) < $paid) {
		Q::log("Calendars_after_Assets_refunded: not cancelling $userId's booking of"
			. " $streamName: its host $hostId cannot return the $paid credits paid", 'stripe');
		return;
	}
	Calendars_Event::going($stream, $userId, 'no');
}
