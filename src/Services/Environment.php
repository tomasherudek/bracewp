<?php
/**
 * Environment verdict service.
 *
 * @package Brace
 */

namespace Brace\Services;

/**
 * Decides whether this installation is a staging/development copy or
 * production. Shared by every module that must never act on production.
 *
 * Version 0 is deliberately conservative: the verdict is "staging" only on an
 * explicit signal (a constant, WP_ENVIRONMENT_TYPE, or a host that cannot
 * be public). Everything else reads as production and destructive modules
 * refuse to run. The full detection with a hashed production baseline
 * ships with 001-staging-guard and will replace the internals here; the
 * public surface stays.
 */
final class Environment {

	/**
	 * Constant that force-marks a copy as staging from wp-config.php.
	 */
	public const CONSTANT = 'BRACE_ENVIRONMENT';

	/**
	 * Whether the current installation is a staging/development copy.
	 *
	 * @return bool
	 */
	public function isStaging(): bool {
		return null !== $this->stagingReason();
	}

	/**
	 * Human sentence explaining why this reads as staging, null on production.
	 *
	 * @return ?string
	 */
	public function stagingReason(): ?string {
		if ( defined( self::CONSTANT ) && 'staging' === constant( self::CONSTANT ) ) {
			return __( 'the BRACE_ENVIRONMENT constant marks this site as staging', 'brace' );
		}

		$type = function_exists( 'wp_get_environment_type' ) ? wp_get_environment_type() : 'production';
		if ( in_array( $type, [ 'staging', 'development', 'local' ], true ) ) {
			return sprintf(
				/* translators: %s: WordPress environment type. */
				__( 'WP_ENVIRONMENT_TYPE is "%s"', 'brace' ),
				$type
			);
		}

		$host = $this->host();
		if ( '' !== $host && ! $this->isPublicHost( $host ) ) {
			return sprintf(
				/* translators: %s: hostname. */
				__( 'the host "%s" cannot be a production hostname', 'brace' ),
				$host
			);
		}

		return null;
	}

	/**
	 * The normalized host this site believes it is served on.
	 *
	 * Uses home_url() rather than the request host so the verdict is
	 * identical under WP-CLI and HTTP.
	 *
	 * @return string
	 */
	public function host(): string {
		$host = wp_parse_url( home_url(), PHP_URL_HOST );

		if ( ! is_string( $host ) || '' === $host ) {
			return '';
		}

		$host = strtolower( $host );

		return 0 === strpos( $host, 'www.' ) ? substr( $host, 4 ) : $host;
	}

	/**
	 * Whether a host could legitimately serve a production site.
	 *
	 * Localhost, IP literals, single-label hosts, and the reserved
	 * development TLDs cannot.
	 *
	 * @param string $host Normalized lowercase host.
	 * @return bool
	 */
	private function isPublicHost( string $host ): bool {
		if ( 'localhost' === $host || false !== filter_var( $host, FILTER_VALIDATE_IP ) ) {
			return false;
		}

		if ( false === strpos( $host, '.' ) ) {
			return false;
		}

		$tld = substr( (string) strrchr( $host, '.' ), 1 );

		return ! in_array( $tld, [ 'test', 'local', 'localhost', 'invalid', 'example', 'internal' ], true );
	}
}
