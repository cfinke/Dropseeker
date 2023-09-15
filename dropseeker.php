<?php

/**
 * Parse default options from dropseeker.conf
 *
 * See README.md for instructions on formatting dropseeker.conf.
 *
 * @return array An array of options, like how getopt() would format them.
 */
function default_options() {
	$options = array();

	if ( file_exists( "dropseeker.conf" ) ) {
		$conf_lines = file( "dropseeker.conf" );

		if ( $conf_lines ) {
			$conf_lines = array_map( 'trim', $conf_lines );
			$conf_lines = array_filter( $conf_lines );

			foreach ( $conf_lines as $conf_line ) {
				$parts = explode( "=", $conf_line, 2 );

				if ( count( $parts ) == 2 ) {
					$options[ $parts[0] ] = $parts[1];
				} else {
					$options[ $parts[0] ] = false;
				}
			}
		}
	}

	return $options;
}