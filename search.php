<?php

error_reporting( E_ALL );

set_time_limit( 0 );

$options = getopt( "", array( "search:", "podcast:", "before:", "after:", "output:", "match:", "extract", 'exclude:', 'prefix_words:', 'suffix_words:', ) );

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

if ( empty( $options['match'] ) ) {
	$options['match'] = '*';
}

if ( ! isset( $options['before'] ) ) {
	$options['before'] = .1;
}

if ( ! isset( $options['after'] ) ) {
	$options['after'] = .1;
}

if ( empty( $options['output'] ) ) {
	$options['output'] = 'search-results/';
}

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

if ( empty( $options['search'] ) ) {
	usage();

	die( "You must supply at least one search term.\n" );
}

foreach ( array( "before", "after", "output", "file" ) as $arg ) {
	if ( isset( $options[ $arg ] ) && is_array( $options[ $arg ] ) ) {
		$options[ $arg ] = array_pop( $options[ $arg ] );
	}
}

$options['output'] = trim( $options['output'] );
$options['output'] = preg_replace( '/^~/', $_SERVER['HOME'], $options['output'] );

$options['output'] = rtrim( $options['output'], '/' ) . '/';

if ( ! file_exists( $options['output'] ) ) {
	mkdir( $options['output'] );
}

if ( ! file_exists( $options['output'] ) ) {
	die( "Could not create directory: " . $options['output'] . "\n" );
}

$transcripts = glob( "transcripts/*" . $options['podcast'] . "*/" . "*" . $options['match'] . "*.vtt" );

$last_start_time = '0:00.000';
$last_end_time = '0:00.000';

foreach ( $transcripts as $transcript_file ) {
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
					preg_replace( '/[^a-z]/', '', strtolower( $word ) ),
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

				echo $transcript_file . " @ " . $start . " - " . $end . ":\n\t" . $exclusion_search_string . "\n";

				preg_match_all( '/\(guid=(.+)\)/', $transcript_file, $m );
				$guid = $m[1][0];

				if ( ! $guid ) {
					die( "Could not extract guid from " . $transcript_file . "\n" );
				}

				$mp3_files = glob( "episodes/*" . $options['podcast'] . "*/*guid=" . $guid . "*.mp3" );

				if ( empty( $mp3_files ) ) {
					die( "Could not find mp3 file for " . $transcript_file . "\n" );
				}

				$mp3_file = $mp3_files[0];

				$start_in_seconds = 0;
				$start_parts = explode( ":", $start );
				for ( $i = 0, $len = count( $start_parts ); $i < $len; $i++ ) {
					$start_in_seconds += array_pop( $start_parts ) * ( pow( 60, $i ) );
				}

				$end_in_seconds = 0;
				$end_parts = explode( ":", $end );
				for ( $i = 0, $len = count( $end_parts ); $i < $len; $i++ ) {
					$end_in_seconds += array_pop( $end_parts ) * ( pow( 60, $i ) );
				}

				if ( isset( $options['extract'] ) ) {
					shell_exec(
						"ffmpeg -hide_banner -loglevel error -y -ss "
							. escapeshellarg( floatval( $start_in_seconds - $options['before'] ) )
							. " -t "
							. ( $end_in_seconds - $start_in_seconds + $options['before'] + $options['after'] )
							. " -i " . escapeshellarg( $mp3_file )
							. " "
							. escapeshellarg(
								rtrim( $options['output'], '/' )
								. '/' . $search_term
								. " - " . basename( $mp3_file )
								. " - " . $word_entry[2] . ".aif"
							)
					);
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
	if ( $word === $search_term ) {
		return true;
	}

	if ( '*' === $search_term ) {
		return true;
	}

	if ( strpos( $search_term, '*' ) !== false ) {
		$last_match_end = 0;
		$parts = explode( "*", $search_term );

		foreach ( $parts as $part ) {
			$match_location = strpos( $word, $part, $last_match_end );

			if ( $match_location === false ) {
				return false;
			}
			else {
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
	echo "\t--search   The search term to find. Supports wildcards; `po*st` will match both `podcast` and `post`. You can specify multiple search terms at once.\n\n";
	echo "\t--podcast  Which podcast(s) to search; this is matched against the directories containing the transcripts.\n\n";
	echo "\t--match    A search string for filtering which episodes are searched.\n\n";
	echo "\t--exclude  A search string that, if it matches text around the search result, will be excluded from the final results.\n\n";
	echo "\t--extract  Whether to extract audio clips of each search result. Requires ffmpeg.\n\n";
	echo "\t--output   The output directory for extracted clips. Defaults to `./search-results/`\n\n";
	echo "\t--before   How many seconds before the matched search term that will be included in extracted clips.\n\n";
	echo "\t--after    How many seconds after the matched search term will be included in extracted clips.\n\n";
	echo "\t--prefix_words How many words to display before the matched term in the output.\n\n";
	echo "\t--suffix_words How many words to display after the matched term in the output.\n\n";
}