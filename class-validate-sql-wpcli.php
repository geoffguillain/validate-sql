<?php
/**
 * WP CLI command that takes a .sql file and validate multiple rules before importing.
 * 
 * @package validate-sql
 */

if ( defined( 'WP_CLI' ) && WP_CLI ) {

	/**
	 * Check if the .sql file is ready to be imported.
	 */
	class Validate_SQL_WPCLI {

		/** 
		 * DROP TABLE statements that are found in the SQL file.
		 * 
		 * @see get_drop_tables()
		 * @var array
		 */
		private $drop_tables = array( 
			'wp'    => array(),
			'nonwp' => array(),
		);

		/** 
		 * CREATE TABLE statements that are found in the SQL file.
		 * 
		 * @see create_drop_tables()
		 * @var array
		 */
		private $create_tables = array( 
			'wp'    => array(),
			'nonwp' => array(),
		);

		/** 
		 * Charsets that are found in the SQL file.
		 * 
		 * @see get_charset()
		 * @var array
		 */
		private $charsets = array();

		/** 
		 * CREATE or DELETE DATABASE statements that are found in the SQL file.
		 * 
		 * @see get_database_statements()
		 * @var array
		 */
		private $database_statements = array();

		/** 
		 * Entries from wp_options that are found in the SQL file.
		 * 
		 * @see validate_options_entries()
		 * @var array
		 */
		private $options_entries = array();

		/** 
		 * Entries from wp_blogs that are found in the SQL file.
		 * 
		 * @see get_blogs()
		 * @var array
		 */
		private $blogs_entries = array();

		/** 
		 * The list of the core tables used by WordPress.
		 * 
		 * @var array
		 */
		private $core_tables = array(
			'wp_commentmeta',
			'wp_comments',
			'wp_links',
			'wp_options',
			'wp_postmeta',
			'wp_posts',
			'wp_terms',
			'wp_termmeta',
			'wp_term_relationships',
			'wp_term_taxonomy',
			'wp_usermeta',
			'wp_users',
		);

		/** 
		 * The list of the multisite tables used by WordPress.
		 * 
		 * @var array
		 */
		private $multisite_tables = array(
			'wp_blogs',
			'wp_blogmeta',
			'wp_blog_versions',
			'wp_registration_log',
			'wp_signups',
			'wp_site',
			'wp_sitemeta',
		);

		/**
		 * CLI command that takes a .sql file (required) and validate multiple rules before importing.
		 *
		 * @synopsis --file=<SQLFILE> 
		 */
		public function __invoke( $args, $assoc_args ) {
			
			$archive_extensions = array( 'gz', 'zip' );

			if ( ! isset( $assoc_args['file'] ) ) {
				WP_CLI::error( 'Must have --file=my_sql_file attached' );
			} else {

				$path = $assoc_args['file'];
				$ext  = pathinfo( $path, PATHINFO_EXTENSION );

				if ( 'sql' === $ext ) {
					$this->validate_sql_file( $path );
				} elseif ( in_array( $ext, $archive_extensions ) ) {
					$archive   = new PharData( $path );
					$sql_count = $this->count_sql( $archive );
					if ( $sql_count > 1 ) {
						WP_CLI::error( 'There are more than one .sql file in the archive. Please provide only one .sql file.' );
					} elseif ( 0 === $sql_count ) {
						WP_CLI::error( 'There is no .sql file in the archive.' );
					} else {
						// Extract the SQL file and return the path.
						WP_CLI::line( 'Extracting the archive...' );
						$path = $this->extract_sql( $archive );
						$this->validate_sql_file( $path );
					}
				} else {
					WP_CLI::error( 'Please provide a .sql file or an archive (gz, or zip) with one .sql file inside.' );
				}
			}
		}

		/**
		 * Count how many sql files are in the archive.
		 * 
		 * @param string $archive Path to the archive containing SQL file.
		 */
		private function count_sql( $archive ) {
			$sql_count = 0;
			foreach ( $archive as $file ) {
				$ext = pathinfo( $file, PATHINFO_EXTENSION );
				if ( 'sql' === $ext ) {
					$sql_count++;
				}
			}
			return $sql_count;
		}

		/**
		 * Extract the SQL in the archive.
		 * 
		 * @param string $archive Path to the archive containing SQL file.
		 */
		private function extract_sql( $archive ) {
			foreach ( $archive as $file ) {
				$ext = pathinfo( $file, PATHINFO_EXTENSION );
				if ( 'sql' === $ext ) {
					try {
						$archive->extractTo( '.', basename( $file ) );
					} catch ( Exception $e ) {
						WP_CLI::error( 'We are unable to extract the archive. ' . $e->getMessage() );
					}
				}
			}
			return $file;
		}

		/**
		 * Remove all comments from the SQL file
		 * 
		 * @param string $sql_file The SQL data.
		 * 
		 * return string $sql_file The SQl data without any comments.
		 */
		private function remove_comment( $sql_file ) {
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
		private function insert_to_array( $query, $table ) {
			
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

		/**
		 * Process the SQL file trought multiple validation steps.
		 * 
		 * @param string $file Path to SQL file.
		 * @param string $delimiter The delimiter used in the SQL file, default ";".
		 */
		private function validate_sql_file( $file, $delimiter = ';' ) {

			set_time_limit( 0 );
			if ( is_file( $file ) === true ) {
				$file = fopen( $file, 'r' );
				if ( is_resource( $file ) === true ) {
					$query = array();
					while ( feof( $file ) === false ) {
						$query[] = fgets( $file );
						if ( preg_match( '/' . preg_quote( $delimiter, '/' ) . '$/m', end( $query ) ) ) {
							$query = trim( implode( '', $query ) );
							$query = $this->remove_comment( $query );

							$this->get_drop_tables( $query );
							$this->get_create_tables( $query );
							$this->get_charset( $query );
							$this->get_database_statements( $query );
							$this->get_options( $query );
							$this->get_blogs( $query );

							while ( ob_get_level() > 0 ) {
								ob_end_flush();
							}
							flush();
						}
						if ( is_string( $query ) === true ) {
							$query = array();
						}
					}

					$this->validate_prefix( $this->drop_tables, $this->create_tables );
					$this->validate_matching_drop( $this->drop_tables, $this->create_tables );
					$this->validate_charset( $this->charsets );
					$this->validate_drop_table( $this->drop_tables );
					$this->validate_create_table( $this->create_tables );
					$this->validate_database_statements( $this->database_statements );
					$this->validate_options_entries( $this->options_entries );

					WP_CLI::line( '' );
					WP_CLI::confirm( WP_CLI::colorize( '%yIs the provided database for a multisite WordPress?%n' ) );
					$this->validate_drop_table_multisite( $this->drop_tables );
					$this->validate_create_table_multisite( $this->create_tables );
					$this->validate_blogs_entries( $this->blogs_entries );

					return fclose( $file );
				}
			} else {
				WP_CLI::error( "We can't find the SQL file" );
			}

		}

		/** 
		 * Check the drop tables that are set up in the SQL file.
		 * 
		 * @param string $sql_file The SQL data.
		 */
		private function get_drop_tables( $sql_file ) {

			// Check for tables with wp_ prefix.
			preg_match( '/DROP TABLE (IF EXISTS )?([`\'"]?.*wp_.*[`\'"]?);/', $sql_file, $wp_match );
			if ( isset( $wp_match[2] ) && ! empty( $wp_match[2] ) ) {
				$this->drop_tables['wp'][] = trim( $wp_match[2], '/[\'" `]/' );
			}
			
			// Check for tables with prefix that is not wp_.
			preg_match( '/DROP TABLE (IF EXISTS )?([`\'"]?(?!.*wp_).*[`\'"]?);/', $sql_file, $nonwp_match );
			if ( isset( $nonwp_match[2] ) && ! empty( $nonwp_match[2] ) ) {
				$this->drop_tables['nonwp'][] = trim( $nonwp_match[2], '/[\'" `]/' );
			}
		}

		/** 
		 * Check the create tables that are set up in the SQL file.
		 * 
		 * @param string $sql_file The SQL data.
		 */
		private function get_create_tables( $sql_file ) {

			// Check for tables with wp_ prefix.
			preg_match( '/CREATE TABLE ([`\'"]?.*wp_.*[`\'"]?) \(/', $sql_file, $wp_match );
			if ( isset( $wp_match[1] ) && ! empty( $wp_match[1] ) ) {
				$this->create_tables['wp'][] = trim( $wp_match[1], '/[\'" `]/' );
			}
			
			// Check for tables with prefix that is not wp_.
			preg_match( '/CREATE TABLE ([`\'"]?(?!.*wp_).*[`\'"]?) \(/', $sql_file, $nonwp_match );
			if ( isset( $nonwp_match[1] ) && ! empty( $nonwp_match[1] ) ) {
				$this->create_tables['nonwp'][] = trim( $nonwp_match[1], '/[\'" `]/' );
			}

		}

		/**
		 * Check for the charset and display warnings if not set to UTF8MB4.
		 * 
		 * @param string $sql_file The SQL data.
		 */
		private function get_charset( $sql_file ) {
			
			if ( preg_match( '/CHARSET(=| SET )(\w*)/', $sql_file, $match ) ) {
				$this->charsets[] = $match[2];
			}

		}

		/**
		 * Display a warning if there is a CREATE or DROP DATABASE statement.
		 * 
		 * @param string $sql_file The SQL data.
		 */
		private function get_database_statements( $sql_file ) {

			if ( preg_match( '/^CREATE DATABASE/i', $sql_file ) ) {
				$this->database_statements[] = $sql_file;
			}
			if ( preg_match( '/^DROP DATABASE/i', $sql_file ) ) {
				$this->database_statements[] = $sql_file;
			}

		}

		/**
		 * Fill the $options_entries array with the values of wp_options table.
		 * 
		 * @param string $query The SQL data.
		 */
		private function get_options( $query ) {

			if ( $this->insert_to_array( $query, 'wp_options' ) ) {
				$this->options_entries = array_merge( $this->options_entries, $this->insert_to_array( $query, 'wp_options' ) );
			}
			
		}

		/**
		 * Fill the $blogs_entries array with the values of wp_options table.
		 * 
		 * @param string $query The SQL data.
		 */
		private function get_blogs( $query ) {

			if ( $this->insert_to_array( $query, 'wp_blogs' ) ) {
				$this->blogs_entries = array_merge( $this->blogs_entries, $this->insert_to_array( $query, 'wp_blogs' ) );
			}
			
		}

		/** 
		 * Check the prefix that are set up in the SQL file.
		 * 
		 * @param array $drop_tables All tables that have a DROP TABLE statement.
		 * @param array $create_tables All tables that have a CREATE TABLE statement.
		 */
		private function validate_prefix( $drop_tables, $create_tables ) {
			
			if ( count( $drop_tables['nonwp'] ) > 0 ) {
				WP_CLI::warning( 'We have found some DROP TABLE statements with a custom prefix.' );
				WP_CLI::error_multi_line( $drop_tables['nonwp'] );
			}
			if ( count( $create_tables['nonwp'] ) > 0 ) {
				WP_CLI::warning( 'We have found some CREATE TABLE statements with a custom prefix.' );
				WP_CLI::error_multi_line( $create_tables['nonwp'] );
			}

		}

		/**
		 * Check the prefix that are set up in the SQL file.
		 * 
		 * @param array $drop_tables All tables that have a DROP TABLE statement.
		 * @param array $create_tables All tables that have a CREATE TABLE statement.
		 */
		private function validate_matching_drop( $drop_tables, $create_tables ) {

			foreach ( $create_tables['wp'] as $create_table ) {
				if ( ! in_array( $create_table, $drop_tables['wp'] ) ) {
					WP_CLI::warning( 'There is a missing drop statement for ' . $create_table );
				}
			}
			foreach ( $create_tables['nonwp'] as $create_table ) {
				if ( ! in_array( $create_table, $drop_tables['nonwp'] ) ) {
					WP_CLI::warning( 'There is a missing drop statement for ' . $create_table );
				}
			}
		}

		/**
		 * Check for the charset and display warnings if not set to UTF8MB4.
		 * 
		 * @param array $charsets Charsets found in the SQL file.
		 */
		private function validate_charset( $charsets ) {
			WP_CLI::line( '' );
			WP_CLI::line( 'Checking for charset...' );

			if ( count( $charsets ) > 0 ) {
				if ( ! empty( preg_grep( '/(utf8mb4|latin1|^utf8$)/i', $charsets ) ) ) {
					if ( ! empty( preg_grep( '/utf8mb4/i', $charsets ) ) ) {
						WP_CLI::success( 'We have found some UTF8MB4 charsets' );
					} 
					if ( ! empty( preg_grep( '/(latin1|^utf8$)/i', $charsets ) ) ) {
						WP_CLI::warning( 'We have found some latin1 or UTF8 charsets that should be converted to UTF8MB4' );
					}
				} 
				if ( ! empty( preg_grep( '/^(?!.*(utf8mb4|latin1|^utf8$)).*/i', $charsets ) ) ) {
					WP_CLI::warning( 'We have found some custom charset, please check your SQL file' );
				}
			}
		}

		/**
		 * Check if there is DROP TABLE statements
		 * 
		 * @param array $drop_tables Tables with a drop statement found in the file.
		 */
		private function validate_drop_table( $drop_tables ) {

			if ( count( $drop_tables['wp'] ) ) {
				if ( array_diff( $this->core_tables, $drop_tables['wp'] ) ) {
					WP_CLI::warning( 'Missing core drop statement: ' );
					WP_CLI::error_multi_line( array_diff( $this->core_tables, $drop_tables['wp'] ) );
				} else {
					WP_CLI::success( 'We have found all required DROP TABLE statements for core tables' );
				}
			} else {
				WP_CLI::warning( 'There is no DROP TABLE statement for all core tables' );
			}

		}

		/**
		 * Check if there is CREATE TABLE statements.
		 * 
		 * @param array $create_tables Tables with a create statement found in the file.
		 */
		private function validate_create_table( $create_tables ) {

			if ( count( $create_tables['wp'] ) ) {
				if ( array_diff( $this->core_tables, $create_tables['wp'] ) ) {
					WP_CLI::warning( 'Missing core create statement: ' );
					WP_CLI::error_multi_line( array_diff( $this->core_tables, $create_tables['wp'] ) );
				} else {
					WP_CLI::success( 'We have found all required CREATE TABLE statements for core tables' );
				}
			} else {
				WP_CLI::warning( 'There is no CREATE TABLE statement for all core tables' );
			}
		}

		/**
		 * Display a warning if there is a CREATE or DROP DATABASE statement.
		 * 
		 * @param array $database_statements Database statements found in the sql file.
		 */
		private function validate_database_statements( $database_statements ) {
			
			WP_CLI::line( '' );
			WP_CLI::line( 'Checking for CREATE or DROP DATABASE statements...' );

			if ( count( $database_statements ) > 0 ) {
				WP_CLI::warning( 'We have found some unwanted statemnents: ' );
				WP_CLI::error_multi_line( $database_statements );
			} else {
				WP_CLI::success( 'There is no DATABASE statements' );
			}

		}

		/**
		 * Check if there is DROP TABLE statements on a multisite SQL import
		 * 
		 * @param array $drop_tables Tables with a drop statement found in the file.
		 */
		private function validate_drop_table_multisite( $drop_tables ) {

			if ( count( $drop_tables['wp'] ) ) {
				if ( array_diff( $this->multisite_tables, $drop_tables['wp'] ) ) {
					WP_CLI::warning( 'Missing multisite drop statement: ' );
					WP_CLI::error_multi_line( array_diff( $this->multisite_tables, $drop_tables['wp'] ) );
				} else {
					WP_CLI::success( 'We have found all required DROP TABLE statements for multisite tables' );
				}
			} else {
				WP_CLI::warning( 'There is no DROP TABLE statement for all multisite tables' );
			}
		}

		/**
		 * Check if there is CREATE TABLE statements.
		 * 
		 * @param array $create_tables Tables with a create statement found in the file.
		 */
		private function validate_create_table_multisite( $create_tables ) {

			if ( count( $create_tables['wp'] ) ) {
				if ( array_diff( $this->multisite_tables, $create_tables['wp'] ) ) {
					WP_CLI::warning( 'Missing multisite create statement: ' );
					WP_CLI::error_multi_line( array_diff( $this->multisite_tables, $create_tables['wp'] ) );
				} else {
					WP_CLI::success( 'We have found all required CREATE TABLE statements for multisite tables' );
				}
			} else {
				WP_CLI::warning( 'There is no create statement for multisite tables' );
			}
		}

		/**
		 * Check entries of the wp_blogs table.
		 * 
		 * @param array $blogs_entries The entries of the wp_blogs table as an array.
		 */
		private function validate_blogs_entries( $blogs_entries ) {

			WP_CLI::line( '' );
			WP_CLI::line( 'Checking for wp_blogs table...' );

			$lookfor = array( 'blog_id', 'site_id', 'domain', 'path' );

			// display tables.
			WP_CLI::line( 'We have found ' . count( $blogs_entries ) . ' entries.' );
			WP_CLI\Utils\format_items( 'table', $blogs_entries, $lookfor );
			foreach ( $blogs_entries as $entry ) {
				if ( ! isset( $entry['domain'] ) || '' === $entry['domain'] ) {
					WP_CLI::warning( 'No domain set up for blog_id ' . $entry['blog_id'] );
				}
				if ( ! isset( $entry['path'] ) || '' === $entry['path'] ) {
					WP_CLI::warning( 'No path set up for blog_id ' . $entry['blog_id'] );
				}
			}
		}

		/**
		 * Check siteurl and home of the wp_otions table.
		 * 
		 * @param array $options_entries The entries of the wp_options table as an array.
		 */
		private function validate_options_entries( $options_entries ) {

			WP_CLI::line( '' );
			WP_CLI::line( 'Checking for siteurl and home options...' );

			$lookfor = array( 'option_name', 'option_value' );
			
			if ( ! empty( $options_entries ) ) {
				foreach ( $options_entries as $key => $entry ) {
					if ( 'siteurl' !== $entry['option_name'] && 'home' !== $entry['option_name'] ) {
						unset( $options_entries[ $key ] );
					}
				}
				WP_CLI::line( 'We have found ' . count( $options_entries ) . ' entries.' );
				WP_CLI\Utils\format_items( 'table', $options_entries, $lookfor );
			} else {
				WP_CLI::warning( 'Unable to find the wp_options table.' );
			}

		}

	}
	WP_CLI::add_command( 'validate-sql', 'Validate_SQL_WPCLI' );
}
