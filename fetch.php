<?php

set_time_limit( 0 );

error_reporting( E_ALL );

$default_episode_dir = __DIR__ . '/episodes/';
$default_transcript_dir = __DIR__ . '/transcripts/';

$options = getopt( "", array( "feed:", "title:", "help", "search:", "fetch_only", "confirm", "episode_dir:", "transcript_dir:" ) );

if ( isset( $options['help'] ) ) {
	usage();

	die;
}

if ( isset( $options['confirm'] ) ) {
	$options['confirm'] = true;
}

if ( isset( $options['episode_dir'] ) ) {
	$episode_dir = $options['episode_dir'];
} else {
	$episode_dir = $default_episode_dir;
}

if ( isset( $options['transcript_dir'] ) ) {
	$transcript_dir = $options['transcript_dir'];
} else {
	$transcript_dir = $default_transcript_dir;
}

$episode_dir = rtrim( $episode_dir, '/' ) . '/';
$transcript_dir = rtrim( $transcript_dir, '/' ) . '/';

if ( empty( $options['feed'] ) ) {
	usage();

	die( "You must provide a RSS feed URL.\n" );
}

echo "Fetching feed...\n";

// Create a stream
$opts = array(
	"http" => array(
		"method" => "GET",
		"header" => "User-Agent: Podscriber\r\n" .
			"Cookie: foo=bar\r\n"
	)
);

$context = stream_context_create( $opts );

$feed_contents = file_get_contents( $options['feed'], false, $context );

if ( ! $feed_contents ) {
	die( "Could not fetch feed." );
}

$xml = new SimpleXMLElement( $feed_contents );

if ( ! $xml ) {
	die( "Could not parse feed XML.\n" );
}

if ( isset( $options['title'] ) ) {
	$feed_title = trim( $options['title'] );
} else {
	$feed_title = trim( $xml->channel->title );
}

$feed_title_for_files = trim( str_replace( array( "/", ":", ), " ", $feed_title ) );

$episode_dir .= $feed_title_for_files . '/';
$transcript_dir .= $feed_title_for_files . '/';

if ( ! file_exists( $episode_dir ) ) {
	mkdir( $episode_dir, 0777, true );
}

if ( ! file_exists( $transcript_dir ) ) {
	mkdir( $transcript_dir, 0777, true );
}

foreach ( $xml->channel->item as $item ) {
	$date = date( "Y-m-d", strtotime( $item->pubDate ) );
	$guid = $item->guid;

	if ( ! $guid ) {
		$guid = md5( $item->pubDate . " " . $item->title );
	} else if ( ! preg_match( '/^[0-9a-z]+$/i', $guid ) ) {
		$guid = md5( $guid );
	}

	if ( isset( $options['search'] ) ) {
		$searchable_text = $date . " " . $item->title;

		if ( false === stripos( $searchable_text, $options['search'] ) ) {
			echo date( "Y-m-d H:i:s: " ) . $options['search'] . " not in " . $searchable_text . "\n";
			continue;
		}
	}

	$title = str_replace( '/', ' ', $item->title );
	$filename_base = substr( trim( $date . ' - ' . $title ), 0, 200 ). " (guid=" . $guid . ")";

	$mp3_url = $item->enclosure['url'];

	echo "Checking " . $filename_base . "...\n";

	$mp3_files = glob( $episode_dir . "* (guid=" . $guid . ").mp3" );

	if ( empty( $mp3_files ) ) {
		if ( isset( $options['confirm'] ) ) {
			echo "\007";
			$answer = readline( "Download " . $filename_base . "? [y/n] " );
		} else {
			$answer = 'y';
		}

		if ( 'y' === strtolower( $answer ) ) {
			echo "Downloading " . $filename_base . " from " . $mp3_url . "...\n";
			curl_to_file( $mp3_url, $episode_dir . $filename_base . '.mp3' );
			echo "--------\n";
		}
	}

	$transcript_files = glob( $transcript_dir . "*(guid=" . $guid . ")*.vtt" );

	if ( ! isset( $options['fetch_only'] ) && empty( $transcript_files ) ) {
		if ( isset( $options['confirm'] ) ) {
			echo "\007";
			$answer = readline( "Transcribe " . $filename_base . "? [y/n] " );
		} else {
			$answer = 'y';
		}

		if ( 'y' === strtolower( $answer ) ) {
			echo "Transcribing " . $filename_base . "...\n";

			$cwd = getcwd();

			chdir( $episode_dir );

			$whisper_args = array(
				'model' => 'tiny',
				'output_dir' => $transcript_dir,
			);

			foreach ( $argv as $idx => $arg ) {
				if ( strpos( $arg, "--whisper_" ) === 0 ) {
					$whisper_args[ substr( $arg, 10 ) ] = $argv[ $idx + 1 ];
				}
			}

			$whisper_command = 'whisper';

			foreach ( $whisper_args as $arg => $val ) {
				$whisper_command .= ' --' . $arg . ' ' . escapeshellarg( $val );
			}

			$whisper_command .= ' ' . escapeshellarg( $filename_base . '.mp3' );

			system( $whisper_command );

			chdir( $cwd );

			echo "------\n";
		}
	}
}

function curl_to_file( $url, $path ) {
	// Use a temporary file so we aren't left with partial files if the download doesn't complete.
	$temp_file_path = tempnam( sys_get_temp_dir(), "dropseeker-" );

	$fp = fopen( $temp_file_path, 'w+' );

	$ch = curl_init( $url );
	curl_setopt( $ch, CURLOPT_FILE, $fp ); 
	curl_setopt( $ch, CURLOPT_FOLLOWLOCATION, true );
	curl_exec( $ch ); 
	curl_close( $ch );

	fclose( $fp );

	// Move the temporary file to the real file.
	rename( $temp_file_path, $path );
}

function usage() {
	echo "\n";
	echo "Usage: php " . $_SERVER['PHP_SELF'] . " --feed http://example.com/rss.xml\n";
	echo "\n";
	echo "\t--feed           The URL of the RSS feed to process.\n\n";
	echo "\t--title          The title to give this podcast, used to name the episode and transcript directories. Defaults to the title given in the RSS feed.\n\n";
	echo "\t--help           Show this instructional output.\n\n";
	echo "\t--search         Only download or transcribe episodes that match this string.\n\n";
	echo "\t--fetch_only     Don't try to transcribe anything; just download episodes.\n\n";
	echo "\t--confirm        Require confirmation before downloading or transcribing anything.\n\n";
	echo "\t--episode_dir    Set a different output directory for episode audio files. Defaults to ./episodes/\n\n";
	echo "\t--transcript_dir Set a different output directory for episode transcripts. Defaults to ./transcripts/\n\n";

	echo "\t--whisper_[arg]  Pass the command line option `arg` to the `whisper` transcription tool. e.g., `--whisper_model medium`\n";
	echo "\tDefault whisper args:\n";
	echo "\t\t--model        tiny\n";
	echo "\t\t--output_dir   The subdirectory of --transcript_dir with the same name as --title.\n\n";
}
