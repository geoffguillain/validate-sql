<?php
/**
 * WP CLI command that takes a .sql file and validate multiple rules before importing.
 */

if ( defined( 'WP_CLI' ) && WP_CLI ) {

	require_once 'sql-parser.php';

	/**
	 * Check if the .sql file is ready to be imported.
	 */
	class ValidateSQLWPCLI {

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
		 * Process the SQL file trought multiple validation steps.
		 * 
		 * @param string $path Path to SQL file.
		 */
		private function validate_sql_file( $path ) {
			$sql_file  = file_get_contents( $path );
			$sql_file  = remove_comments( $sql_file );
			$sql_array = split_sql_file( $sql_file, ';' );

			$core_tables = array(
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

			$multisite_tables = array(
				'wp_blogs',
				'wp_blogmeta',
				'wp_blog_versions',
				'wp_registration_log',
				'wp_signups',
				'wp_site',
				'wp_sitemeta',
			);

			if ( false === $sql_file ) {
				WP_CLI::error( "We can't find the SQL file" );
			}
			// Common rules.
			$this->validate_prefix( $sql_file );
			$this->validate_charset( $sql_file );
			$this->validate_drop_table( $sql_file, $core_tables, $multisite_tables );
			$this->validate_create_table( $sql_file, $core_tables, $multisite_tables );
			$this->validate_database( $sql_file );
			$this->validate_settings( $sql_array );

			// multisite validation rules.
			WP_CLI::line( '' );
			WP_CLI::confirm( WP_CLI::colorize( '%yIs the provided database for a multisite WordPress?%n' ) );
			$this->validate_drop_table_multisite( $sql_file, $multisite_tables );
			$this->validate_create_table_multisite( $sql_file, $multisite_tables );
			$this->validate_wpblogs( $sql_array );

		}

		/** 
		 * Check the prefix that are set up in the SQL file.
		 * 
		 * @param string $sql_file The SQL data.
		 */
		private function validate_prefix( $sql_file ) {
			
			// Check for tables with wp_ prefix.
			WP_CLI::line( 'Checking for wp_ prefix...' );
			preg_match_all( '/CREATE TABLE ([`\'"]?.*wp_.*[`\'"]?) \(/', $sql_file, $wp_matches );
			if ( count( $wp_matches[0] ) > 0 ) {
				WP_CLI::success( 'We have found ' . count( $wp_matches[0] ) . ' tables with wp_ prefix' );
			} else {
				WP_CLI::warning( 'We have not found any table with the wp prefix.' );
			}
			
			// Check for tables with prefix that is not wp_.
			preg_match_all( '/CREATE TABLE ([`\'"]?(?!.*wp_).*[`\'"]?) \(/', $sql_file, $nonwp_matches );
			if ( count( $nonwp_matches[1] ) > 0 ) {
				WP_CLI::warning( 'Error: We have found ' . count( $nonwp_matches[1] ) . ' tables with a wrong prefix' );
				WP_CLI::error_multi_line( $nonwp_matches[1] );
			} else {
				WP_CLI::success( 'We have not found any table with a wrong prefix' );
			}
		}

		/**
		 * Check for the charset and display warnings if not set to UTF8MB4.
		 * 
		 * @param string $sql_file The SQL data.
		 */
		private function validate_charset( $sql_file ) {
			WP_CLI::line( '' );
			WP_CLI::line( 'Checking for charset...' );

			preg_match_all( '/charset(=| SET )(utf8mb4|latin1|utf8).*/i', $sql_file, $matches );

			if ( count( $matches[0] ) > 0 ) {
				$charset_to_convert = preg_grep( '/(latin1|^utf8$)/i', $matches[2] );
				if ( empty( $charset_to_convert ) ) {
					WP_CLI::success( 'We have found a UTF8MB4 charset' );
				} else {
					WP_CLI::warning( 'We have found some latin1 or UTF8 charsets that should be converted to UTF8MB4' );
				}
			} else {
				WP_CLI::warning( 'We have not found any UTF8MB4 charset, please check your SQL file' );
			}
		}

		/**
		 * Check if there is DROP TABLE statements
		 * 
		 * @param string $sql_file The SQL data.
		 * @param array  $core_tables The WordPress core tables.
		 * @param array  $multisite_tables The WordPress multisite tables.
		 * 
		 * return int Number of DROP TABLE statements.
		 */
		private function validate_drop_table( $sql_file, $core_tables, $multisite_tables ) {
			WP_CLI::line( '' );
			WP_CLI::line( 'Checking for DROP TABLE statements...' );

			$tables_size      = count( $core_tables );
			$core_tables      = implode( '|', $core_tables );
			$multisite_tables = implode( '|', $multisite_tables );
			preg_match_all( '/DROP TABLE.*(' . $core_tables . ')/i', $sql_file, $core_matches );
			
			if ( count( $core_matches[1] ) === $tables_size ) {
				WP_CLI::success( 'We have found a DROP TABLE statement for each core tables' );
			} else {
				WP_CLI::warning( 'There is only ' . count( $core_matches[1] ) . ' DROP TABLE statements while there are ' . $tables_size . ' core tables' );
			}


			preg_match_all( '/DROP TABLE(?!.*?(' . $core_tables . '|' . $multisite_tables . '|wp_\d_.*)).*/i', $sql_file, $extra_matches );
			if ( count( $extra_matches[0] ) > 0 ) {
				WP_CLI::warning( 'We have found some custom tables' );
				WP_CLI::error_multi_line( $extra_matches[0] );
			}
			
			return count( $core_matches[1] ) + count( $extra_matches[0] );
		}

		/**
		 * Check if there is CREATE TABLE statements.
		 * 
		 * @param string $sql_file The SQL data.
		 * @param array  $core_tables The WordPress core tables.
		 * @param array  $multisite_tables The WordPress multisite tables.
		 * 
		 * return int Number of DROP TABLE statements.
		 */
		private function validate_create_table( $sql_file, $core_tables, $multisite_tables ) {
			WP_CLI::line( '' );
			WP_CLI::line( 'Checking for CREATE TABLE statements...' );

			$tables_size      = count( $core_tables );
			$core_tables      = implode( '|', $core_tables );
			$multisite_tables = implode( '|', $multisite_tables );

			preg_match_all( '/CREATE TABLE.*(' . $core_tables . ')/i', $sql_file, $core_matches );
			
			if ( count( $core_matches[1] ) === $tables_size ) {
				WP_CLI::success( 'We have found a CREATE TABLE statement for each core tables' );
			} else {
				WP_CLI::warning( 'There is only ' . count( $core_matches[1] ) . ' CREATE TABLE statements while there are ' . $tables_size . ' core tables' );
			}

			preg_match_all( '/CREATE TABLE(?!.*?(' . $core_tables . '|' . $multisite_tables . '|wp_\d_.*)).*/i', $sql_file, $extra_matches );
			if ( count( $extra_matches[0] ) > 0 ) {
				WP_CLI::warning( 'We have found some custom tables' );
				WP_CLI::error_multi_line( $extra_matches[0] );
			}
			
			return count( $core_matches[1] ) + count( $extra_matches[0] );
		}

		/**
		 * Display a warning if there is a CREATE or DROP DATABASE statement.
		 * 
		 * @param string $sql_file The SQL data.
		 */
		private function validate_database( $sql_file ) {
			WP_CLI::line( '' );
			WP_CLI::line( 'Checking for CREATE or DROP DATABASE statements...' );
			if ( 0 === preg_match( '/CREATE DATABASE/i', $sql_file ) ) {
				WP_CLI::success( 'There is no CREATE DATABASE statement' );
			} else {
				WP_CLI::warning( 'There is a CREATE DATABASE statement. This is not allowed when importing a database.' );
			}
			if ( 0 === preg_match( '/DROP DATABASE/i', $sql_file ) ) {
				WP_CLI::success( 'There is no DROP DATABASE statement' );
			} else {
				WP_CLI::warning( 'There is a DROP DATABASE statement. This is not allowed when importing a database.' );
			}
		}

		/**
		 * Check if there is DROP TABLE statements for multisite
		 * 
		 * @param string $sql_file The SQL data.
		 * @param array  $multisite_tables The WordPress multisite tables.
		 * 
		 * return int Number of DROP TABLE statements.
		 */
		private function validate_drop_table_multisite( $sql_file, $multisite_tables ) {
			WP_CLI::line( '' );
			WP_CLI::line( 'Checking for DROP TABLE statements for multisite...' );

			$tables_size      = count( $multisite_tables );
			$multisite_tables = ( implode( '|', $multisite_tables ) );

			preg_match_all( '/DROP TABLE.*(' . $multisite_tables . ')/i', $sql_file, $multisite_matches );
			
			if ( count( $multisite_matches[1] ) === $tables_size ) {
				WP_CLI::success( 'We have found a DROP TABLE statement for each multisite tables' );
			} else {
				WP_CLI::warning( 'We have not found any DROP TABLE statement for each multisite tables' );
			}

			return count( $multisite_matches[1] );
		}

		/**
		 * Check if there is CREATE TABLE statements
		 * 
		 * @param string $sql_file The SQL data.
		 * @param array  $multisite_tables The WordPress multisite tables.
		 * 
		 * return int Number of DROP TABLE statements.
		 */
		private function validate_create_table_multisite( $sql_file, $multisite_tables ) {
			WP_CLI::line( '' );
			WP_CLI::line( 'Checking for CREATE TABLE statements for multisite...' );

			$tables_size      = count( $multisite_tables );
			$multisite_tables = ( implode( '|', $multisite_tables ) );

			preg_match_all( '/CREATE TABLE.*(' . $multisite_tables . ')/i', $sql_file, $multisite_matches );
			
			if ( count( $multisite_matches[1] ) === $tables_size ) {
				WP_CLI::success( 'We have found a CREATE TABLE statement for each multisite tables' );
			} else {
				WP_CLI::warning( 'We have not found a CREATE TABLE statement for each multisite tables' );
			}
			
			return count( $multisite_matches[1] );
		}

		/**
		 * Check entries of the wp_blogs table.
		 * 
		 * @param array $sql_array The entries of the specific table as an array.
		 */
		private function validate_wpblogs( $sql_array ) {

			WP_CLI::line( '' );
			WP_CLI::line( 'Checking for wp_blogs table...' );

			$entries = insert_to_array( $sql_array, 'wp_blogs' );
			$lookfor = array( 'blog_id', 'site_id', 'domain', 'path' );
			
			
			// display tables.
			WP_CLI::line( 'We have found ' . count( $entries ) . ' entries.' );
			WP_CLI\Utils\format_items( 'table', $entries, $lookfor );
			foreach ( $entries as $entry ) {
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
		 * @param array $sql_array The entries of the specific table as an array.
		 */
		private function validate_settings( $sql_array ) {

			WP_CLI::line( '' );
			WP_CLI::line( 'Checking for siteurl and home options...' );

			$entries = insert_to_array( $sql_array, 'wp_options' );
			$lookfor = array( 'option_name', 'option_value' );
			
			if ( ! empty( $entries ) ) {
				foreach ( $entries as $key => $entry ) {
					if ( 'siteurl' !== $entry['option_name'] && 'home' !== $entry['option_name'] ) {
						unset( $entries[ $key ] );
					}
				}
				WP_CLI::line( 'We have found ' . count( $entries ) . ' entries.' );
				WP_CLI\Utils\format_items( 'table', $entries, $lookfor );
			} else {
				WP_CLI::warning( 'Unable to find the wp_options table.' );
			}

		}

	}
	WP_CLI::add_command( 'validate-sql', 'ValidateSQLWPCLI' );
}
