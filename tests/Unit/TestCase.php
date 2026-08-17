<?php
/**
 * Base class for unit tests.
 *
 * @package Brace
 */

namespace Brace\Tests\Unit;

use Brain\Monkey;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase as PHPUnitTestCase;

/**
 * Sets up and tears down Brain Monkey around every test and stubs the
 * translation and escaping functions so production code can call them
 * without WordPress loaded.
 */
abstract class TestCase extends PHPUnitTestCase {

	use MockeryPHPUnitIntegration;

	/**
	 * Set up Brain Monkey and the common WP function stubs.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Monkey\Functions\stubTranslationFunctions();
		Monkey\Functions\stubEscapeFunctions();
	}

	/**
	 * Tear down Brain Monkey.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}
}
