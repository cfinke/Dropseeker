<?php

set_time_limit( 0 );

error_reporting( E_ALL );

require "dropseeker.php";
$default_options = default_options();

$default_episode_dir = __DIR__ . '/episodes/';
$default_transcript_dir = __DIR__ . '/transcripts/';

$options = getopt( "", array(
	"feed:",
	"title:",
	"help",
	"match:",
	"fetch_only",
	"transcribe_only",
	"confirm",
	"episode_dir:",
	"transcript_dir:",
	"exclude:",
	"before_date:",
	"after_date:",
	"whisper_cpp:", // Path to whisper.cpp
) );

$options = array_merge( $default_options, $options );

if ( isset( $options['help'] ) ) {
	usage();

	die;
}

if ( isset( $options['confirm'] ) ) {
	$options['confirm'] = true;
}

if ( isset( $options['exclude'] ) ) {
	if ( ! is_array( $options['exclude'] ) ) {
		$options['exclude'] = array( $options['exclude'] );
	}
}

if ( isset( $options['before_date'] ) ) {
	$options['before_date'] = date( "Y-m-d", strtotime( $options['before_date'] ) );
}

if ( isset( $options['after_date'] ) ) {
	$options['after_date'] = date( "Y-m-d", strtotime( $options['after_date'] ) );
}

if ( isset( $options['match'] ) ) {
	if ( ! is_array( $options['match'] ) ) {
		$options['match'] = array( $options['match'] );
	}
}

if ( isset( $options['whisper_cpp'] ) ) {
	$options['whisper_cpp'] = rtrim( $options['whisper_cpp'], '/' );

	if ( ! is_dir( $options['whisper_cpp'] ) ) {
		die( "The path to whisper.cpp does not exist.\n" );
	}

	if ( ! file_exists( $options['whisper_cpp'] . '/main' ) ) {
		die( "Could not find the `main` executable in " . $options['whisper_cpp'] . "/\n" );
	}
}

if ( isset( $options['episode_dir'] ) ) {
	$episode_dir = $options['episode_dir'];

	if ( substr( $episode_dir, 0, 1 ) != '/' ) {
		$episode_dir = getcwd() . '/' . $episode_dir;
	}
} else {
	$episode_dir = $default_episode_dir;
}

$episode_dir = rtrim( $episode_dir, '/' ) . '/';

if ( isset( $options['transcript_dir'] ) ) {
	$transcript_dir = $options['transcript_dir'];

	if ( substr( $transcript_dir, 0, 1 ) != '/' ) {
		$transcript_dir = getcwd() . '/' . $transcript_dir;
	}
} else {
	$transcript_dir = $default_transcript_dir;
}

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

	if ( isset( $options['before_date'] ) ) {
		if ( date( "Y-m-d", strtotime( $item->pubDate ) ) >= $options['before_date'] ) {
			echo "Skipping " . $item->pubDate . " " . $item->title . "\n";
			continue;
		}
	}

	if ( isset( $options['after_date'] ) ) {
		if ( date( "Y-m-d", strtotime( $item->pubDate ) ) <= $options['after_date'] ) {
			echo "Skipping " . $item->pubDate . " " . $item->title . "\n";
			continue;
		}
	}

	if ( isset( $options['match'] ) ) {
		$searchable_text = $date . " " . $item->title;

		if ( false === stripos( $searchable_text, $options['match'] ) ) {
			echo date( "Y-m-d H:i:s: " ) . $options['match'] . " not in " . $searchable_text . "\n";
			continue;
		}
	}

	if ( ! empty( $options['exclude'] ) ) {
		foreach ( $options['exclude'] as $exclude_option ) {
			if ( false !== stripos( $item->pubDate . " " . $item->title, $exclude_option ) ) {
				echo "Skipping " . $item->pubDate . " " . $item->title . "\n";
				continue 2;
			}
		}
	}

	$title = str_replace( '/', ' ', $item->title );
	$filename_base = substr( trim( $date . ' - ' . $title ), 0, 200 ). " (guid=" . $guid . ")";

	$mp3_url = $item->enclosure['url'];

	echo "Checking " . $filename_base . "...\n";

	$matching_episodes = glob( $episode_dir . "* (guid=" . $guid . ").*" );

	if ( empty( $matching_episodes ) ) {
		if ( isset( $options['transcribe_only'] ) ) {
			echo "Missing " . $filename_base . "\n";
			continue;
		}

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
			$matching_episodes[] = $episode_dir . $filename_base . '.mp3';
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
			$audio_file = $matching_episodes[0];

			echo "Transcribing " . $audio_file . "...\n";

			$cwd = getcwd();

			chdir( $episode_dir );

			$whisper_args = array(
				'model' => 'tiny',
				'output_dir' => $transcript_dir,
			);

			foreach ( $argv as $idx => $arg ) {
				if ( strpos( $arg, "--whisper_" ) === 0 && $arg != '--whisper_cpp' ) {
					$whisper_args[ substr( $arg, 10 ) ] = $argv[ $idx + 1 ];
				}
			}

			if ( isset( $options['whisper_cpp'] ) ) { // whisper.cpp is a much faster version that uses the GPU on Apple Silicon Macs.
				if ( ! file_exists( $options['whisper_cpp'] . '/models/ggml-' . $whisper_args['model'] . '.bin' ) ) {
					//die( "Could not find necessary file " . $options['whisper_cpp'] . '/models/ggml-' . $whisper_args['model'] . '.bin' . "\n" );
				}

				// whisper.cpp requires wav format.
				$tmp_file = tempnam( sys_get_temp_dir(), "dropseeker-" );

				// The filename has to end in .wav or ffmpeg will complain. In theory, this file isn't guaranteed to not exist already, but come on.
				rename( $tmp_file, $tmp_file . '.wav' );
				$tmp_file .= '.wav';

				$ffmpeg_command = "ffmpeg -y -i " . escapeshellarg( basename( $audio_file ) ) . " -ar 16000 -ac 1 -c:a pcm_s16le " . escapeshellarg( $tmp_file );
				$whisper_command = escapeshellarg( $options['whisper_cpp'] . '/main' );

				foreach ( $whisper_args as $arg => $val ) {
					// These are handled as special cases.
					if ( 'model' == $arg ) {
						continue;
					}

					if ( 'output_dir' == $arg ) {
						continue;
					}


					$whisper_command .= ' --' . $arg . ' ' . escapeshellarg( $val );
				}

				$whisper_command .= ' -m ' . escapeshellarg( $options['whisper_cpp'] . '/models/ggml-' . $whisper_args['model'] . '.bin' ) . ' --output-txt --output-vtt  --output-srt --output-json -f ' . escapeshellarg( $tmp_file ) . ' --output-file ' . escapeshellarg( rtrim( $whisper_args['output_dir'], '/' ) . '/' . pathinfo( $audio_file, PATHINFO_FILENAME ) );

//				echo "Temp file is " . $tmp_file . "\n";
//				echo "ffmpeg command is " . $ffmpeg_command . "\n";

				system( $ffmpeg_command );

//				echo "Whisper command is " . $whisper_command . "\n";

				system( $whisper_command );

				unlink( $tmp_file );
			}
			else {

				$whisper_command = 'whisper';

				foreach ( $whisper_args as $arg => $val ) {
					$whisper_command .= ' --' . $arg . ' ' . escapeshellarg( $val );
				}

				$whisper_command .= ' ' . escapeshellarg( basename( $audio_file ) );

				system( $whisper_command );
			}

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
	echo "\t--feed            The URL of the RSS feed to process.\n\n";
	echo "\t--title           The title to give this podcast, used to name the episode and transcript directories. Defaults to the title given in the RSS feed.\n\n";
	echo "\t--help            Show this instructional output.\n\n";
	echo "\t--match           Only download or transcribe episodes that match this string.\n\n";
	echo "\t--fetch_only      Don't try to transcribe anything; just download episodes.\n\n";
	echo "\t--transcribe_only Don't try to download anything; just transcribe downloaded episodes.\n\n";
	echo "\t--confirm         Require confirmation before downloading or transcribing anything.\n\n";
	echo "\t--episode_dir     Set a different output directory for episode audio files. Defaults to ./episodes/\n\n";
	echo "\t--transcript_dir  Set a different output directory for episode transcripts. Defaults to ./transcripts/\n\n";
	echo "\t--exclude         Exclude episodes that include this string in their date/title.\n\n";
	echo "\t--before-date     Only download episodes that were published before this date.\n\n";
	echo "\t--after-date      Only download episodes that were published after this date.\n\n";

	echo "\t--whisper_[arg]   Pass the command line option `arg` to the `whisper` transcription tool. e.g., `--whisper_model medium`\n";
	echo "\tDefault whisper args:\n";
	echo "\t\t--model         tiny\n";
	echo "\t\t--output_dir    The subdirectory of --transcript_dir with the same name as --title.\n\n";
}
