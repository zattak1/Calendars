<?php

/**
 * Calendars 0.5.6: grant Users/admins readLevel=40 on user name streams.
 *
 * Creates a Streams/access stream published by the community with a
 * Users/admins access row at readLevel=40. Then creates mutable streams
 * (publisherId='', streamName='Streams/user/firstName*' etc.) that
 * inheritAccess from it.
 *
 * Finally, rebuilds avatar rows so that admin viewers see the full names
 * they now have access to.
 */
function Calendars_0_5_6_Streams_mysql()
{
	$communityId = Users::communityId();
	$accessStreamName = 'Calendars/access/adminNames';

	// 1. Create the access stream published by the community
	$accessStream = new Streams_Stream();
	$accessStream->publisherId = $communityId;
	$accessStream->name = $accessStreamName;
	if (!$accessStream->retrieve()) {
		$accessStream->type = 'Streams/access';
		$accessStream->title = 'Admin access to user names';
		$accessStream->readLevel = 0;
		$accessStream->writeLevel = 0;
		$accessStream->adminLevel = 0;
		$accessStream->insertedTime = $accessStream->updatedTime = date('Y-m-d H:i:s');
		$accessStream->save(true);
		echo "  created access stream\n";
	} else {
		echo "  access stream exists\n";
	}

	// 2. Grant Users/admins readLevel=40 on that stream
	$row = new Streams_Access(array(
		'publisherId' => $communityId,
		'streamName' => $accessStreamName,
		'ofUserId' => '',
		'ofContactLabel' => 'Users/admins',
		'ofParticipantRole' => ''
	));
	$row->retrieve();
	$row->readLevel = 40;
	$row->writeLevel = -1;
	$row->adminLevel = -1;
	$row->grantedByUserId = $communityId;
	$row->save();
	echo "  granted Users/admins readLevel=40 on access stream\n";

	// 3. Create mutable streams that inheritAccess from it
	foreach (array('Streams/user/firstName', 'Streams/user/lastName') as $type) {
		$mutableName = $type . '*';
		$mutable = new Streams_Stream();
		$mutable->publisherId = '';
		$mutable->name = $mutableName;
		if (!$mutable->retrieve()) {
			$mutable->type = 'Streams/mutable';
			$mutable->title = "Mutable access for $type";
			$mutable->readLevel = -1;
			$mutable->writeLevel = -1;
			$mutable->adminLevel = -1;
			$mutable->inheritAccess = '[]';
			$mutable->insertedTime = $mutable->updatedTime = date('Y-m-d H:i:s');
		}
		if ($mutable->inheritAccessSet($communityId, $accessStreamName)) {
			$mutable->save();
			echo "  $mutableName now inherits from $accessStreamName\n";
		} else {
			echo "  $mutableName already inherits\n";
		}
	}

	// 4. Rebuild avatar rows for all (admin, user) pairs so cached
	//    firstName/lastName reflect the new access.
	$adminIds = Users_Contact::select('contactUserId')
		->where(array(
			'userId' => $communityId,
			'label' => 'Users/admins'
		))
		->fetchAll(PDO::FETCH_COLUMN, 0);
	echo "  " . count($adminIds) . " admins to update avatars for\n";

	$offset = 0;
	$i = 0;
	while (true) {
		$userIds = Users_User::select('id')
			->limit(100, $offset)
			->fetchAll(PDO::FETCH_COLUMN, 0);
		if (!$userIds) {
			break;
		}
		foreach ($userIds as $userId) {
			foreach ($adminIds as $adminId) {
				if ($adminId === $userId) {
					continue; // publisher sees their own streams already
				}
				Streams_Avatar::updateAvatar($adminId, $userId);
				++$i;
			}
		}
		$offset += 100;
		echo "\033[100D";
		echo "  updated $i avatar rows";
	}
	echo "\n  done: $i avatar rows updated\n";
}

Calendars_0_5_6_Streams_mysql();