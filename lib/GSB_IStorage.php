<?php

/**
 * Storage Interface
 * */
interface GSB_IStorage {
	function list2id($name);

	/**
	 * Reverse mapping of list id to list name
	 * I'm sure PHP has a snazzy way of doing this
	 */
	function id2list($id);

	/**
	 * Transaction Abstraction
	 *
	 */
	function transaction_begin();

	function transaction_commit();

	function transaction_rollback();

	/**
	 * Resets the tables in the GSB schema
	 */
	function delete_all_data(&$data);

	function rekey(&$data);

	/**
	 * Fetch the chunk numbers from the database for the given list and mode.
	 *
	 * @param string $listname
	 * @param string $mode
	 */
	function add_chunk_get_nums($listname);

	function sub_chunk_get_nums($listname);

	/**
	 * Finds all prefixes the matches the host key.
	 *
	 * @param array[int]string|string $hostkeys
	 * @return multitype:|array
	 */
	function hostkey_select_prefixes($hostkeys);

	function add_insert(&$data);

	function add_delete(&$data);

	function sub_delete(&$data);

	function add_empty(&$data);

	function sub_insert(&$data);

	function sub_empty(&$data);

	/**
	 * Delete all obsolete fullhashs
	 */
	function fullhash_delete_old($now = null);

	/** INSERT or Replace full fash
	 */
	function fullhash_insert(&$data, $now = null);

	function fullhash_exists(&$data, $now = null);

	function rfd_get();

	function rfd_set(&$data);

	/**
	 * This is a bit tricky.  Here instead of saving data, we just
	 *  store it locally.  We'll update the gsb_rfd state table
	 *  all at once later
	 *
	 */
	function set_timeout(&$data);

	function get_timeout();
}