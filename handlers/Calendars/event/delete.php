<?php

/**
 * @module Calendars
 * @class HTTP Calendars event
 */

/**
 * Close event stream, as the logged-in user.
 * The user needs the "close" write level on the stream, which must be a Calendars/event.
 * @method delete
 * @param {array} $_REQUEST
 * @param {string} [$_REQUEST.publisherId] Required. Event stream publisher id.
 * @param {string} [$_REQUEST.streamName] Required. Event stream name.
 * @param {string} [$_REQUEST.stopRecurring] Optional. Pass true to also stop creating recurring events (and things associated to them).
 */
function Calendars_event_delete($params)
{
	$user = Users::loggedInUser(true);
	// Q/delete checks the nonce without throwing, and only AJAX requests are
	// refused for it later, so check it here as Streams/access PUT does.
	Q_Valid::nonce(true);
	$r = array_merge($_REQUEST, $params);
	$required = array('streamName', 'publisherId');
	Q_Valid::requireFields($required, $r, true);

	// Act as the logged-in user only. This used to take a "userId" from the
	// request, so passing the publisher's id passed the check below, and the
	// stream was then closed as its publisher, which skips the access check
	// in Streams::close(): anyone could close any stream.
	$stream = Streams_Stream::fetch($user->id, $r['publisherId'], $r['streamName'], true);
	if ($stream->type !== 'Calendars/event') {
		throw new Q_Exception_WrongType(array(
			'field' => 'streamName',
			'type' => 'a Calendars/event stream'
		));
	}

	// check if user have permission to close stream (publisher or Community admin or app admin)
	if (!$stream->testWriteLevel('close')) {
		throw new Users_Exception_NotAuthorized();
	}
	// Streams::close() also reads the stream's relations as this user and
	// refuses without "relations" read access. Refuse that here, before the
	// "state" attribute and Streams/changed below are saved, or the event
	// would look closed to every viewer while it stays open.
	if (!$stream->testReadLevel('relations')) {
		throw new Users_Exception_NotAuthorized();
	}

	// if recurring category exist - close one
	if (Q::ifset($r, 'stopRecurring', false)) {
		// Authorized by the event: this finds only a series of the event's
		// own publisher (Streams::related() fetches only related streams of
		// the same publisher), and it is closed as that publisher, as before.
		$recurringCategory = Calendars_Recurring::fromStream($stream);
		if ($recurringCategory) {
			$recurringCategory->close($recurringCategory->publisherId);
		}
	}

	// set state to closed and send message to handle this event on client
	$stream->setAttribute("state", "closed");
	$stream->changed();

	// close stream (Streams::close() checks the access again)
	$stream->close($user->id);
}
