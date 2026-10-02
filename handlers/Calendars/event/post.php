<?php

/**
 * @module Streams
 * @class HTTP Streams event
 */

/**
 * Used to plan a new event. Fills slots "event" and "participant".
 * @method post
 *
 * @param {array} $_REQUEST
 * @param {string} $_REQUEST.interestTitle Required. Title of an interest that exists in the system.
 * @param {string} $_REQUEST.placeId Required. Pass the id of a location where people will gather.
 * @param {string} $_REQUEST.localStartDateTime Local datetime when the people should gather at the location.
 * @param {string} [$_REQUEST.localEndDateTime]  Optional. Local datetime when people should start to disperse
 * @param {string} [$_REQUEST.startTime] If set, uses this time directly instead of localStartDateTime
 * @param {string} [$_REQUEST.endTime] If set, uses this time directly instead of localEndDateTime
 * @param {string} [$_REQUEST.icon] Event illustration image: an http(s) image URL (fetched through Q_Fetch),
 *   a data:image/...;base64, URI, or image contents data. Never read as a local path.
 * @param {string} [$_REQUEST.timezone=null] Optional. The timezone offset on the browser of the user who created the event.
 * @param {string} [$_REQUEST.timezoneName=null] Optional. The name of the timezone, out of the common ones e.g. "America/New_York".
 * @param {string} [$_REQUEST.labels=''] Optional. You can specify a tab-delimited string of labels to which access is granted. Otherwise access is public.
 * @param {string} [$_REQUEST.asUserId=Users::loggedInUser()] The user who is taking the action
 * @param {string} [$_REQUEST.publisherId=Users::loggedInUser()->id] Optional. The user who would publish the event. Defaults to the logged-in user.
 * @param {string} [$_REQUEST.communityId=Users::communityId()] Optional. The user id of the community, which will publish the streams to which this event would be related. Defaults to the main community's id.
 * @param {string|array} [$_REQUEST.experienceId="main"] Can set one or more ids of community experiences the event will be related to.
 * @optional
 */
function Calendars_event_post($params) {
	$params = array_merge($_REQUEST, $params);
	$availability = Q::ifset($params, "availability", null);
	if ($availability) {
		$availabilityStream = Streams_Stream::fetch(null, $availability['publisherId'], $availability['streamName']);
		if (!$availabilityStream) {
			throw new Exception("Availability stream not found!");
		}

		$params["recurring"] = filter_var($params["recurring"], FILTER_VALIDATE_BOOLEAN);
		$userId = Users::loggedInUser(true)->id;
		$creditsAmount = Assets_Credits::amount(null, $userId);

		// if user not admin check payments
		if (!Calendars_Availability::isAdmin($userId)) {
			// check if user have enough credits to participate events
			$params["paymentCheck"] = true;
			$params["userId"] = $userId;
			$paymentCheck = Calendars_Availability::createEvents($availabilityStream, $params);
			if ($paymentCheck["needCredits"] > $creditsAmount) {
				$paymentCheck["needCredits"] = $paymentCheck["needCredits"] - $creditsAmount;
				Q_Response::setSlot('exception', $paymentCheck);
				Q_Response::setSlot('stream', false); // don't change `false` here, if set "stream" slot to null, Q_Response return exception "missing slot event Calendars/event/response/stream"
				return;
			}
		}

		// if user have enought credits, start to create events
		$params["paymentCheck"] = false;
		$events = Calendars_Availability::createEvents($availabilityStream, $params);

		// try to join user to all events created
		foreach ($events as $event) {
			try {
				Calendars_Event::going($event, $userId, 'yes', array("autoCharge" => true));
			} catch (Exception $e) {
				// if payment required and user short of credits and exception throws during charge
			}
		}

		// get nearest event to display on client
		$location = $availabilityStream->getAttribute('location');
		if ($location) {
			$locationStream = Places_Location::stream(null, Users::communityId(), $location['placeId']);
			$nearestDate = Calendars_Event::getNearestDate($params['timeSlots'], $locationStream);
			$eventExists = Streams_RelatedTo::select()->where(array(
				'toPublisherId' => $availabilityStream->publisherId,
				'toStreamName' => $availabilityStream->name,
				'type' => 'Calendars/event',
				'weight' => $nearestDate[0]
			))->fetchDbRows();
			if (count($eventExists)) {
				$event = Streams_Stream::fetch(null, $eventExists[0]->fromPublisherId, $eventExists[0]->fromStreamName);
			} else {
				throw new Exception("Calendars/event/post: nearest event not found");
			}
		}
	} else {
		$event = Calendars_Event::create($params);
	}

	Q_Response::setSlot('exception', false); // don't change `false` here, if set null, Q_Response return exception "missing slot event Calendars/event/response/exception"
	Q_Response::setSlot('stream', $event->exportArray());
}