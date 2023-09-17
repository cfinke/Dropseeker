<?php

error_reporting( E_ALL );

set_time_limit( 0 );

require "dropseeker.php";
$default_options = default_options();

$default_episode_dir = __DIR__ . '/episodes/';
$default_transcript_dir = __DIR__ . '/transcripts/';

$options = getopt( "", array(
	"search:",
	"podcast:",
	"before:",
	"after:",
	"output_dir:",
	"match:",
	"extract",
	'exclude:',
	'prefix_words:',
	'suffix_words:',
	'context:',
	'limit_per_episode:',
	'limit:',
	'episode_dir:',
	'transcript_dir:',
	'help',
) );

$options = array_merge( $default_options, $options );

if ( isset( $options['help'] ) ) {
	usage();

	die;
}

if ( ! isset( $options['search'] ) ) {
	$options['search'] = array();
} else if ( ! is_array( $options['search'] ) ) {
	$options['search'] = array( $options['search'] );
}

$options['search'] = array_unique( $options['search'] );
$options['search'] = array_filter( $options['search'] );

if ( empty( $options['podcast'] ) ) {
	$options['podcast'] = '*';
}

if ( isset( $options['match'] ) ) {
	if ( ! is_array( $options['match'] ) ) {
		$options['match'] = array( $options['match'] );
	}
}

if ( ! isset( $options['before'] ) ) {
	$options['before'] = .1;
}
else {
	$options['before'] = floatval( $options['before'] );
}

if ( ! isset( $options['after'] ) ) {
	$options['after'] = .1;
}
else {
	$options['after'] = floatval( $options['after'] );
}

if ( empty( $options['output_dir'] ) ) {
	$options['output_dir'] = 'search-results/';
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

if ( ! isset( $options['prefix_words'] ) ) {
	$options['prefix_words'] = 5;
}

if ( ! isset( $options['suffix_words'] ) ) {
	$options['suffix_words'] = 15;
}

if ( isset( $options['exclude'] ) ) {
	if ( ! is_array( $options['exclude'] ) ) {
		$options['exclude'] = array( $options['exclude'] );
	}
}

if ( isset( $options['context'] ) ) {
	if ( ! is_array( $options['context'] ) ) {
		$options['context'] = array( $options['context'] );
	}
}

if ( empty( $options['search'] ) ) {
	usage();

	die( "You must supply at least one search term.\n\n" );
}

foreach ( array( "before", "after", "output_dir", "file" ) as $arg ) {
	if ( isset( $options[ $arg ] ) && is_array( $options[ $arg ] ) ) {
		$options[ $arg ] = array_pop( $options[ $arg ] );
	}
}

foreach ( $options as $arg => $val ) {
	if ( is_array( $val ) ) {
		$val = array_map( 'trim', $val );
	} else {
		$val = trim( $val );
	}

	$options[ $arg ] = $val;
}

$options['output_dir'] = trim( $options['output_dir'] );
$options['output_dir'] = preg_replace( '/^~/', $_SERVER['HOME'], $options['output_dir'] );

$options['output_dir'] = rtrim( $options['output_dir'], '/' ) . '/';

if ( ! file_exists( $options['output_dir'] ) ) {
	mkdir( $options['output_dir'] );
}

if ( ! file_exists( $options['output_dir'] ) ) {
	die( "Could not create directory: " . $options['output_dir'] . "\n" );
}

$all_transcripts = array_reverse( glob( $transcript_dir . "*" . $options['podcast'] . "*/*.vtt" ) );

$transcripts = array();

foreach ( $all_transcripts as $transcript ) {
	$filename = basename( $transcript );

	if ( isset( $options['match'] ) ) {
		foreach ( $options['match'] as $match ) {
			if ( false !== stripos( $filename, $match ) ) {
				$transcripts[] = $transcript;
				continue 2;
			}
		}
	}
	else {
		$transcripts[] = $transcript;
	}
}

$last_start_time = '0:00.000';
$last_end_time = '0:00.000';

$matches_found = 0;

foreach ( $transcripts as $transcript_file ) {
	$matches_found_in_episode = 0;

	$parsed_transcript = array();

	$lines = file( $transcript_file );
	$lines = array_map( 'trim', $lines );

	foreach ( $lines as $line ) {
		if ( strpos( $line, 'WEBVTT' ) === 0 ) {
			continue;
		}

		if ( empty( $line ) ) {
			continue;
		}

		if ( preg_match( '/^((?:[0-9]+:)*[0-9]+\.[0-9]{3}) --> ((?:[0-9]+:)*[0-9]+\.[0-9]{3})$/', $line, $m ) ) {
			$last_start_time = $m[1];
			$last_end_time = $m[2];
		} else {
			$words = preg_split( '/\s/', $line );

			foreach ( $words as $word ) {
				$parsed_transcript[] = array(
					$word,
					preg_replace( '/[^a-z0-9]/', '', strtolower( $word ) ),
					$last_start_time,
					$last_end_time,
				);
			}
		}
	}

	foreach ( $options['search'] as $search_term ) {
		$search_term = strtolower( $search_term );
		$suffix_word_count = $options['suffix_words'] + substr_count( $search_term, ' ' );

		$keywords = explode( " ", $search_term );

		$num_words = count( $parsed_transcript );

		$suffix_words = array();
		$prefix_words = array();

		for ( $i = 0; $i < min( $num_words, $suffix_word_count ); $i++ ) {
			$suffix_words[] = $parsed_transcript[ $i ];
		}

		$start = INF;
		$end = 0;

		foreach ( $parsed_transcript as $idx => $word_entry ) {
			$prefix_words[] = $word_entry[0];

			if ( $num_words > $idx + $suffix_word_count ) {
				$suffix_words[] = $parsed_transcript[ $idx + $suffix_word_count ];
			}

			array_shift( $suffix_words );

			if ( count( $prefix_words ) > $options['prefix_words'] + 1) {
				array_shift( $prefix_words );
			}

			if ( matches_search_term( $word_entry[1], $keywords[0] ) ) {
				$start = $word_entry[2];
				$end = $word_entry[3];

				if ( count( $keywords ) > 1 ) {
					if ( count( $suffix_words ) < count( $keywords ) - 1 ) {
						break;
					}

					for ( $k = 1; $k < count( $keywords ); $k++ ) {
						if ( ! matches_search_term( $suffix_words[ $k - 1 ][1], $keywords[ $k ] ) ) {
							continue 2;
						}

						$end = $suffix_words[ $k - 1 ][3];
					}
				}

				$suffix_string = '';

				foreach ( $suffix_words as $suffix_word ) { $suffix_string .= $suffix_word[0] . ' '; }

				$exclusion_search_string = join( " ", $prefix_words ) . " " . trim( $suffix_string );

				if ( ! empty( $options['exclude'] ) ) {
					foreach ( $options['exclude'] as $exclude_option ) {
						if ( false !== stripos( $exclusion_search_string, $exclude_option ) ) {
							continue 2;
						}
					}
				}

				if ( ! empty( $options['context'] ) ) {
					foreach ( $options['context'] as $context_includes ) {
						if ( false === stripos( $exclusion_search_string, $context_includes ) ) {
							continue 2;
						}
					}
				}

				$matches_found_in_episode++;
				$matches_found++;

				echo str_replace( $transcript_dir, '', $transcript_file ) . " @ " . $start . " - " . $end . ":\n\t" . $exclusion_search_string . "\n";

				preg_match_all( '/\(guid=(.+)\)/', $transcript_file, $m );
				$guid = $m[1][0];

				if ( ! $guid ) {
					die( "Could not extract guid from " . $transcript_file . "\n" );
				}

				$timestamp_in_filename = '';

				$start_in_seconds = 0;
				$start_parts = explode( ":", $start );

				if ( count( $start_parts ) < 3 ) {
					array_unshift( $start_parts, '0' );
				}

				if ( count( $start_parts ) < 3 ) {
					array_unshift( $start_parts, '0' );
				}

				for ( $i = 0, $len = count( $start_parts ); $i < $len; $i++ ) {
					$start_part = array_pop( $start_parts );
					$start_in_seconds += $start_part * ( pow( 60, $i ) );

					// Use a 01h2m3s timestamp in the filename to avoid using :, which shows up as / on Mac.
					if ( $i == 0 ) {
						$timestamp_in_filename = str_pad( round( $start_part ), 2, '0', STR_PAD_LEFT ) . "s";
					} else if ( $i == 1 ) {
						$timestamp_in_filename = str_pad( round( $start_part ), 2, '0', STR_PAD_LEFT ) . "m" . $timestamp_in_filename;
					} else if ( $i == 2 ) {
						$timestamp_in_filename = str_pad( round( $start_part ), 2, '0', STR_PAD_LEFT ) . "h" . $timestamp_in_filename;
					}
				}

				$end_in_seconds = 0;
				$end_parts = explode( ":", $end );
				for ( $i = 0, $len = count( $end_parts ); $i < $len; $i++ ) {
					$end_in_seconds += array_pop( $end_parts ) * ( pow( 60, $i ) );
				}

				if ( isset( $options['extract'] ) ) {
					$audio_files = glob( $episode_dir . "*" . $options['podcast'] . "*/*guid=" . $guid . "*.*" );

					if ( empty( $audio_files ) ) {
						die( "Could not find audio for " . $transcript_file . "\n" );
					}

					$audio_file = $audio_files[0];

					shell_exec(
						"ffmpeg -hide_banner -loglevel error -y -ss "
							. escapeshellarg( floatval( $start_in_seconds - $options['before'] ) )
							. " -t "
							. ( $end_in_seconds - $start_in_seconds + $options['before'] + $options['after'] )
							. " -i " . escapeshellarg( $audio_file )
							. " "
							. escapeshellarg(
								rtrim( $options['output_dir'], '/' )
								. '/' . substr( $search_term
								. " - " . basename( $audio_file ), 0, 200 )
								. " - " . $timestamp_in_filename . ".aif"
							)
					);
				}

				if ( isset( $options['limit'] ) && $matches_found == $options['limit'] ) {
					break 3;
				}

				if ( isset( $options['limit_per_episode'] ) && $matches_found_in_episode == $options['limit_per_episode'] ) {
					continue 2;
				}
			}
		}
	}
}

function seconds_to_minutes( $seconds ) {
	$string = floor( $seconds / ( 60 * 60 ) );
	$seconds = intval( $seconds ) % ( 60 * 60 );

	$string .= ':' . str_pad( floor( $seconds / 60 ), 2, "0", STR_PAD_LEFT );
	$seconds = $seconds % 60;

	$string .= ':' . str_pad( $seconds, 2, "0", STR_PAD_LEFT );

	return $string;
}

function matches_search_term( $word, $search_term ) {
	$search_term = preg_replace( '/[^a-z0-9\*]/', '', strtolower( $search_term ) );

	if ( $word === $search_term ) {
		return true;
	}

	if ( '*' === $search_term ) {
		return true;
	}

	if ( '.' === $search_term && strlen( $word ) == 1 ) {
		return true;
	}

	if ( strpos( $search_term, '*' ) !== false ) {
		$last_match_end = 0;
		$parts = explode( "*", $search_term );

		foreach ( $parts as $idx => $part ) {
			$match_location = strpos( $word, $part, $last_match_end );

			if ( $match_location === false ) {
				return false;
			} else if ( $idx === 0 && $match_location > 0 ) {
				return false;
			} else {
				$last_match_end = $match_location + strlen( $part );
			}
		}

		if ( $last_match_end == strlen( $word ) || $part === '' ) {
			return true;
		}
	}

	return false;
}


function usage() {
	echo "Usage: php " . $_SERVER['PHP_SELF'] . " --search [search term]\n";
	echo "\n";

	echo "Required arguments:

	--search [string]         What to search for in transcripts. Supports wildcards like 'foo*' (words that start
	                          with foo), 'foo * bar' ('foo' and 'bar' separated by one word), or 'foo*baz*bar' (any
	                          word starting with 'foo', containing 'baz', and ending with 'bar').

Optional arguments:

	--after [float]           Extract an additional __ seconds from after each match.
	--before [float]          Extract an additional __ seconds from before each match.
	--exclude [string]        A search string that, if it matches text around the search result, will be excluded from the final results.
	--episode_dir [path]      The directory in which the episode directories are stored, if not in the default location.
	--extract                 Extract audio clips of each match.
	--context [string]        Only consider a match if the full prefix + match + suffix also includes this string.
	--output_dir [path]       The directory in which to store the extracted audio clips.
	--limit [int]             Stop searching entirely after finding this many total matches.
	--limit_per_episode [int] Stop searching an episode after finding this many matches in it.
	--match [string]          Only check episodes that include this string in their filename.
	--podcast [string]        Only search transcripts from podcasts that include this string in their title.
	--prefix_words [int]      Show this many words before the matching string in the text search results.
	--suffix_words [int]      Show this many words after the matching string in the text search results.
	--transcript_dir [path]   The directory in which the transcript directories are stored, if not in the default location.
";
}