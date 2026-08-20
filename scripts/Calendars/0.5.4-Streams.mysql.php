<?php

/**
 * Calendars 0.5.4: grant Users/admins readLevel=40 on user name streams.
 *
 * Creates a streams_access row so that anyone with the Users/admins label
 * (under the app's communityId) can read Streams/user/firstName and
 * Streams/user/lastName for every user. This lets the attendees panel
 * show full names to admins.
 *
 * The access row is on the TEMPLATE stream (publisherId='', streamName
 * starting with 'Streams/user/firstName') so it applies to all users
 * without needing per-user rows.
 */
function Calendars_0_5_4_Streams_mysql()
{
	$communityId = Users::communityId();

	$streams = array(
		'Streams/user/firstName',
		'Streams/user/lastName'
	);

	foreach ($streams as $streamName) {
		// Insert access row on the template stream (publisherId = '')
		// so it applies to ALL publishers' streams of this name.
		$access = new Streams_Access(array(
			'publisherId' => '',
			'streamName' => $streamName,
			'ofUserId' => '',
			'ofContactLabel' => 'Users/admins',
			'ofParticipantRole' => ''
		));
		if (!$access->retrieve()) {
			$access->readLevel = 40;     // "see" level — enough for name fields
			$access->writeLevel = -1;    // don't change
			$access->adminLevel = -1;    // don't change
			$access->grantedByUserId = $communityId;
			$access->save(true);
			echo "  granted Users/admins readLevel=40 on template $streamName\n";
		} else {
			// ensure readLevel is at least 40
			if ($access->readLevel < 40) {
				$access->readLevel = 40;
				$access->save();
				echo "  updated Users/admins readLevel to 40 on template $streamName\n";
			} else {
				echo "  Users/admins already has readLevel>={$access->readLevel} on $streamName\n";
			}
		}
	}
}

Calendars_0_5_4_Streams_mysql();
