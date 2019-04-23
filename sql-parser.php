<?php

/**
 * Remove all comments from the SQL file
 * 
 * @param string $sql_file The SQL data.
 * 
 * return string $sql_file The SQl data without any comments.
 */
function remove_comments( $sql_file ) {
	// Remove /* */ comments.
	$sql_file = preg_replace( '#/\*(.|[\r\n])*?\*/#', "\n", $sql_file );
	// Remove # style comments.
	$sql_file = preg_replace( '/\n{2,}/', "\n", preg_replace( '/^#.*$/m', "\n", $sql_file ) );
	return $sql_file;
}

/**
 * Transform the SQL file into an array of SQL statements
 * 
 * @param string $sql_file The SQL data.
 * @param string $delimiter The delimiter used in the SQL file.
 * 
 * return array $data The SQL data in an array.
 */
function split_sql_file( $sql_file, $delimiter ) {
	$sql_file = str_replace( "\r", '', $sql_file );
	$data     = preg_split( '/' . preg_quote( $delimiter, '/' ) . '$/m', $sql_file );
	$data     = array_map( 'trim', $data );

	// The empty case.
	$end_data = end( $data );

	if ( empty( $end_data ) ) {
		unset( $data[ key( $data ) ] );
	}

	return $data;
}

/**
 * Transform values of an INSERT statement into an array of entries
 * 
 * @param array  $sql_array The SQL data as an array of statements.
 * @param string $table The table we want to get the values from.
 * 
 * return array $blogs_array The entries of the specific table as an array or an empty array.
 */
function insert_to_array( $sql_array, $table ) {
	
	$blogs_array = array();

	foreach ( $sql_array as $statement ) {
		if ( preg_match( '/INSERT INTO.*' . $table . '/i', $statement ) ) {
			preg_match_all( '/\((?>[^)(]+|(?R))*+\)/', $statement, $matches );
			$indexes = preg_replace( '/[(\'" `)]/', '', $matches[0][0] );
			$indexes = explode( ',', $indexes );
			unset( $matches[0][0] );
			
			$blogs_array_size = count( $blogs_array );
			foreach ( $matches[0] as $key => $match ) {
				$entry = trim( $match, '/[()]/' );
				$entry = explode( ',', $entry );
				foreach ( $indexes as $index_key => $index ) {
					if ( isset( $entry[ $index_key ] ) ) {
						$blogs_array[ $blogs_array_size + $key ][ $index ] = trim( $entry[ $index_key ], '/[\'" `]/' );
					} else {
						$blogs_array[ $blogs_array_size + $key ][ $index ] = null;
					}
				}
			}
		}
	}

	return $blogs_array;
}
