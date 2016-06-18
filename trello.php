<?php

	// Your Slack oAuth Token
	$slack_token = '[Slack auth token]';

	// Trello
	// https://trello.com/app-key
	$trello_key = '[Trello key]';
	$trello_token = '[Trello token]';
	$trello_default_board = '[Default board id]';

	// Trigger Word
	$trigger_phrase = 'add to trello';

	// Get the channel ID
	$channelID = $_POST['channel_id'];

	// Include slack library from https://github.com/10w042/slack-api
	include 'Slack.php';
	include 'trello/src/Trello/OAuthSimple.php';
	include 'trello/src/Trello/Trello.php';

	use \Trello\Trello;

	// Create new Slack instance
	$Slack = new Slack($slack_token);
	$Trello = new Trello($trello_key, null, $trello_token);

	// Get the last 11 messages of the channel conversation
	$slack_data = $Slack->call('channels.history', array(
		'channel' => $channelID,
		'count' => 5,
		'parse' => 'none'
	));

	// Remove the last message - which will contain the trigger phrase
	$slack_trigger_message = array_shift($slack_data['messages']);

	// Remove trigger_phrase from message to get board name
	$slack_board_name = trim(
		str_replace(
			strtolower($trigger_phrase),
			'',
			strtolower($slack_trigger_message['text'])
		)
	);

	// Find the next message in the channel - strip URL formatting
	foreach($slack_data['messages'] as $message) {
		$message['text'] = preg_replace("/\<(http[^ ]+)>/", "\\1", $message['text']);
		if($message['type'] == 'message') {
			$trello_card = $message;
			break;
		}
	}

	// Get the trello user
	$trello_user = $Trello->members->get('me');

	// Get all the boards the user has access to that are open
	$trello_boards = $Trello->members->get($trello_user->id . '/boards', array('filter' => 'open'));

	// Find the board that matches the message text
	foreach ($trello_boards as $board) {
		if(strpos(strtolower($board->name), $slack_board_name) !== false) {
			$trello_board = $board;
			break;
		}
	}

	// if there is no board - fall back to the default one
	if(!$trello_board) {
		$trello_board = $Trello->boards->get($trello_default_board);
	}

	// Get all the lists in the board
	$trello_lists = $Trello->boards->get($trello_board->id . '/lists');

	// Get the first list of the board
	$trello_list = $trello_lists[0];

	// Get the Slack user who noted the bug
	$slack_user = $Slack->call('users.info', array(
		'user' => $trello_card['user']
	));

	// Get the name of the user - if there is a real_name, use that
	$slack_name = $slack_user['user']['name'];
	if(count($slack_user['user']['real_name'])) {
		$slack_name = $slack_user['user']['real_name'];
	}

	// Post the card to trello
	$done = $Trello->lists->post($trello_list->id . '/cards', array(
		'name' => $trello_card['text'],
		'desc' => 'Added by **' . $slack_name . '** on ' . date('jS F', $trello_card['ts'])
	));

	// Post back to board it was posted to
	header('Content-Type: application/json');
	echo json_encode(array(
		'text' => 'Added the card to the <' .
			$trello_board->shortUrl .
			'|' . $trello_board->name .
			'> board'
	));
