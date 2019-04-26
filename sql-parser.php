<?php

/**
 * Remove all comments from the SQL file
 * 
 * @param string $sql_file The SQL data.
 * 
 * return string $sql_file The SQl data without any comments.
 */
function remove_comment( $sql_file ) {
	$sql_file = preg_replace( '/^#.*$/', '', $sql_file );
	// Remove /*! */; comments.
	$sql_file = preg_replace( '#/\*!(.|[\r\n])*?\*/;#', '', $sql_file );
	// Remove /* */ comments.
	$sql_file = preg_replace( '#/\*(.|[\r\n])*?\*/#', '', $sql_file );
	// Remove # style comments.
	$sql_file = preg_replace( '/\n{2,}/', '', preg_replace( '/^#.*$/m', '', $sql_file ) );
	return $sql_file;
}

/**
 * Transform values of an INSERT statement into an array of entries
 * 
 * @param string $query The SQL query.
 * @param string $table The table we want to get the values from.
 * 
 * return array $blogs_array The entries of the specific table as an array or an empty array.
 */
function insert_to_array( $query, $table ) {
	
	$blogs_array = array();

	if ( preg_match( '/INSERT INTO.*' . $table . '/i', $query ) ) {
		preg_match_all( '/\((?>[^)(]+|(?R))*+\)/', $query, $matches );
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
	return $blogs_array;
}
