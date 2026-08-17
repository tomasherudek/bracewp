<?php
/**
 * PHPUnit bootstrap for the unit suite.
 *
 * No WordPress is loaded here; Brain Monkey stubs the WP functions the
 * code under test calls. Integration tests (wp-env) get their own
 * bootstrap later.
 *
 * @package Brace
 */

require_once dirname( __DIR__ ) . '/vendor/autoload.php';
