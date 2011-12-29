<?php

/**
 * Storage based on an existing WordPress database class.
 */
class GSB_WP_Storage implements GSB_IStorage {
	/**
	 * initialize the database connection
	 */
	function __construct() {
		$this->timeout = 0;

		/* maps GSB name to Int */
		$this->LIST_ENUM = array (
			'goog-malware-shavar'     => 1,
			'goog-regtest-shavar'     => 2,
			'goog-whitedomain-shavar' => 3,
			'googpub-phish-shavar'    => 4
		);
	}

	/**
	 * Datalist mapping
	 */
	function list2id($name) {
		return $this->LIST_ENUM[$name];
	}

	/**
	 * Reverse mapping of list id to list name
	 * I'm sure PHP has a snazzy way of doing this
	 */
	function id2list($id) {
		foreach ( $this->LIST_ENUM as $k => $v ) {
			if ( $id == $v ) {
				return $k;
			}
		}
		return '???';
	}

	/**
	 * Transaction Abstraction
	 *
	 */
	function transaction_begin() {
		global $wpdb;
		$wpdb->query( 'SET autocommit = 0;' );
		$wpdb->query( 'SET SESSION TRANSACTION ISOLATION LEVEL SERIALIZABLE;' );
		$wpdb->query( 'START TRANSACTION;' );
	}

	function transaction_commit() {
		global $wpdb;
		$wpdb->query( 'COMMIT' );
	}

	function transaction_rollback() {
		global $wpdb;
		$wpdb->query( 'ROLLBACK' );
	}

	/**
	 * Resets the tables in the GSB schema
	 */
	function delete_all_data(&$data) {
		global $wpdb;

		$wpdb->query( 'TRUNCATE TABLE gsb_add' );
		$wpdb->query( 'TRUNCATE TABLE gsb_sub' );
		$wpdb->query( 'TRUNCATE TABLE gsb_fullhash' );
	}

	function rekey(&$data) {
		// TBD
	}

	/**
	 * Fetch the chunk numbers from the database for the given list and mode.
	 *
	 * @param string $listname
	 * @param string $mode
	 */
	function add_chunk_get_nums($listname) {
		global $wpdb;

		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT DISTINCT(add_chunk_num) FROM gsb_add WHERE list_id = %s', $this->LIST_ENUM[$listname] ), ARRAY_N );

		$chunks = array ();
		foreach ( $rows as $row ) {
			array_push( $chunks, (int) $row[0] );
		}
		asort( $chunks );
		return $chunks;
	}

	function sub_chunk_get_nums($listname) {
		global $wpdb;

		$rows = $wpdb->get_row( $wpdb->prepare( 'SELECT DISTINCT(sub_chunk_num) FROM gsb_sub WHERE list_id = %s', $this->LIST_ENUM[$listname] ), ARRAY_N );

		$chunks = array ();
		foreach ( $rows as $row ) {
			array_push( $chunks, (int) $row[0] );
		}
		asort( $chunks );
		return $chunks;
	}

	/**
	 * Finds all prefixes the matches the host key.
	 *
	 * @param array[int]string|string $hostkeys
	 * @return multitype:|array
	 */
	function hostkey_select_prefixes($hostkeys) {
		global $wpdb;
		// build the where clause
		if ( empty( $hostkeys ) ) {
			return array ();
		} else if ( is_array( $hostkeys ) ) {
			$hostkeys = array_map( 'addslashes', $hostkeys ); // replace with esc_sql
			$where = "WHERE a.host_key IN ('" . implode( "','", $hostkeys ) . "') ";
		} else {
			$where = $wpdb->prepare( 'WHERE a.host_key = %s', $hostkeys );
		}

		// build the query, filter out lists that were "subtracted"
		$results = $wpdb->get_results(
			'SELECT a.* FROM gsb_add a ' .
				'LEFT OUTER JOIN gsb_sub s ' .
				'    ON s.list_id        = a.list_id ' .
				'    AND s.host_key      = a.host_key ' .
				'    AND s.add_chunk_num = a.add_chunk_num ' .
				'    AND s.prefix        = a.prefix ' .
				$where .
				'AND s.sub_chunk_num IS NULL', ARRAY_A );

		return $results;
	}

	function add_insert(&$data) {
		global $wpdb;

		// $wpdb->insert does not add IGNORE
		$wpdb->query(
			$wpdb->prepare( 'INSERT IGNORE INTO gsb_add(list_id, add_chunk_num, host_key, prefix) VALUES (%d, %d, %s, %s)',
				$this->LIST_ENUM[$data['listname']],
				$data['add_chunk_num'],
				$data['host_key'],
				$data['prefix']
			) );
	}

	function add_delete(&$data) {
		global $wpdb;

		foreach ( array( 'gsb_add', 'gsb_sub', 'gsb_fullhash' ) as $table )
			$wpdb->query(
				$wpdb->prepare( "DELETE FROM `$table` where list_id=%s AND add_chunk_num >= %s and add_chunk_num <= %s",
					$this->LIST_ENUM[$data['listname']],
					$data['min'],
					$data['max']
				)
			);
	}

	function sub_delete(&$data) {
		global $wpdb;
		$wpdb->query(
			$wpdb->prepare( 'DELETE FROM gsb_sub where list_id=%s AND add_chunk_num >= %s and add_chunk_num <= %s',
				$this->LIST_ENUM[$data['listname']],
				$data['min'],
				$data['max']
			)
		);
	}

	function add_empty(&$data) {
		$data['host_key'] = '';
		$data['prefix'] = '';
		$this->add_insert( $data );
	}

	function sub_insert(&$data) {
		global $wpdb;

		// $wpdb->insert does not add IGNORE
		$wpdb->query(
			$wpdb->prepare( 'INSERT IGNORE INTO gsb_sub(list_id, add_chunk_num, sub_chunk_num, host_key, prefix) VALUES (%d, %d, %d, %s, %s)',
				$this->LIST_ENUM[$data['listname']],
				$data['add_chunk_num'],
				$data['sub_chunk_num'],
				$data['host_key'],
				$data['prefix']
			) );
	}

	function sub_empty(&$data) {
		$data['host_key'] = '';
		$data['prefix'] = '';

		// ??
		$data['add_chunk_num'] = 0;
		$this->sub_insert( $data );
	}

	/**
	 * Delete all obsolete fullhashs
	 */
	function fullhash_delete_old($now = null) {
		global $wpdb;

		if ( is_null( $now ) )
			$now = time();

		$wpdb->query( $wpdb->prepare( 'DELETE FROM gsb_fullhash WHERE create_ts < %s', $now - ( 60 * 45 ) ) );
	}

	/** INSERT or Replace full fash
	 *
	 *
	 */
	function fullhash_insert(&$data, $now = null) {
		global $wpdb;
		if ( is_null( $now ) )
			$now = time();
		$wpdb->query(
			$wpdb->prepare( 'REPLACE INTO gsb_fullhash VALUES(%d,%d,%s,%d)',
				$this->LIST_ENUM[$data['listname']],
				$data['add_chunk_num'],
				$data['hash'],
				$now
			)
		);
	}

	/**
	 *
	 *
	 */
	function fullhash_exists(&$data, $now = null) {
		global $wpdb;

		if ( is_null( $now ) )
			$now = time();

		// HACK -- need to pick
		if ( isset( $data['list_id'] ) )
			$list_id = $data['list_id'];
		else
			$list_id = $this->LIST_ENUM[$data['listname']];

		$exp = $now - 60 * 45;
		$count = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM gsb_fullhash WHERE list_id = %d AND fullhash = %s AND create_ts > %s', $list_id, $data['hash'], $exp ) );
		return ( $count > 0 );
	}

	function rfd_get() {
		global $wpdb;
		return $wpdb->get_row( 'SELECT * FROM gsb_rfd WHERE id = 1', ARRAY_A );
	}

	function rfd_set(&$data) {
		global $wpdb;
		$wpdb->query(
			$wpdb->prepare( 'REPLACE INTO gsb_rfd VALUES(1, %d, %d, %d, %d)',
				$data['next_attempt'],
				$data['error_count'],
				$data['last_attempt'],
				$data['last_success']
			)
		);
		// see below.
		$this->timeout = 0;
	}

	/**
	 * This is a bit tricky.  Here instead of saving data, we just
	 *  store it locally.  We'll update the gsb_rfd state table
	 *  all at once later
	 *
	 */
	function set_timeout(&$data) {
		$this->timeout = $data['next'];
	}

	function get_timeout() {
		return $this->timeout;
	}


}
