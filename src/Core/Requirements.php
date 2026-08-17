<?php
/**
 * Declarative requirements checker for modules.
 *
 * @package Brace
 */

namespace Brace\Core;

/**
 * A module declares what it needs from the server; core checks the list
 * before every boot (hosting changes). Unmet requirements disable the
 * toggle in the UI with a human sentence and soft-skip the module at boot.
 */
final class Requirements {

	/**
	 * Declared requirements.
	 *
	 * @var list<array{type: RequirementType, value: string|int}>
	 */
	private array $requirements = [];

	/**
	 * A module with no requirements.
	 *
	 * @return self
	 */
	public static function none(): self {
		return new self();
	}

	/**
	 * Require a loaded PHP extension.
	 *
	 * @param string $extension Extension name as reported by extension_loaded().
	 * @return self
	 */
	public function phpExtension( string $extension ): self {
		return $this->add( RequirementType::PhpExtension, $extension );
	}

	/**
	 * Require a minimum WordPress version.
	 *
	 * @param string $version Minimum version, e.g. "6.7".
	 * @return self
	 */
	public function wpVersion( string $version ): self {
		return $this->add( RequirementType::WpVersion, $version );
	}

	/**
	 * Require a writable path.
	 *
	 * @param string $path Absolute path that must be writable.
	 * @return self
	 */
	public function writablePath( string $path ): self {
		return $this->add( RequirementType::WritablePath, $path );
	}

	/**
	 * Require a minimum PHP memory limit.
	 *
	 * @param int $bytes Minimum memory limit in bytes.
	 * @return self
	 */
	public function memory( int $bytes ): self {
		return $this->add( RequirementType::Memory, $bytes );
	}

	/**
	 * Require an executable binary on the server PATH.
	 *
	 * @param string $binary Binary name, e.g. "mysqldump".
	 * @return self
	 */
	public function binary( string $binary ): self {
		return $this->add( RequirementType::Binary, $binary );
	}

	/**
	 * Require a multisite installation.
	 *
	 * @return self
	 */
	public function multisite(): self {
		return $this->add( RequirementType::Multisite, 'multisite' );
	}

	/**
	 * Whether every declared requirement is met right now.
	 *
	 * @return bool
	 */
	public function satisfied(): bool {
		return [] === $this->unmet();
	}

	/**
	 * Human sentences for every unmet requirement.
	 *
	 * @return list<string>
	 */
	public function unmet(): array {
		$failures = [];

		foreach ( $this->requirements as $requirement ) {
			$reason = $this->check( $requirement['type'], $requirement['value'] );
			if ( null !== $reason ) {
				$failures[] = $reason;
			}
		}

		return $failures;
	}

	/**
	 * Convert a php.ini shorthand memory value to bytes.
	 *
	 * @param string $shorthand Value like "256M", "1G", "-1".
	 * @return int Bytes, or -1 for unlimited.
	 */
	public static function bytes( string $shorthand ): int {
		$value = trim( $shorthand );

		if ( '' === $value || '-1' === $value ) {
			return -1;
		}

		$number = (int) $value;

		return match ( strtoupper( substr( $value, -1 ) ) ) {
			'G'     => $number * 1024 * 1024 * 1024,
			'M'     => $number * 1024 * 1024,
			'K'     => $number * 1024,
			default => $number,
		};
	}

	/**
	 * Store one requirement.
	 *
	 * @param RequirementType $type  What kind of requirement.
	 * @param string|int      $value The required value.
	 * @return self
	 */
	private function add( RequirementType $type, $value ): self {
		$this->requirements[] = [
			'type'  => $type,
			'value' => $value,
		];

		return $this;
	}

	/**
	 * Check a single requirement.
	 *
	 * @param RequirementType $type  What kind of requirement.
	 * @param string|int      $value The required value.
	 * @return ?string Human sentence when unmet, null when met.
	 */
	private function check( RequirementType $type, $value ): ?string {
		return match ( $type ) {
			RequirementType::PhpExtension => extension_loaded( (string) $value ) ? null : sprintf(
				/* translators: %s: PHP extension name. */
				__( 'Your server does not support this module (missing PHP extension %s).', 'brace' ),
				(string) $value
			),
			RequirementType::WpVersion => version_compare( get_bloginfo( 'version' ), (string) $value, '>=' ) ? null : sprintf(
				/* translators: %s: required WordPress version. */
				__( 'This module needs WordPress %s or newer.', 'brace' ),
				(string) $value
			),
			RequirementType::WritablePath => wp_is_writable( (string) $value ) ? null : sprintf(
				/* translators: %s: filesystem path. */
				__( 'Your server does not support this module (path %s is not writable).', 'brace' ),
				(string) $value
			),
			RequirementType::Memory => $this->memorySatisfied( (int) $value ) ? null : sprintf(
				/* translators: %s: required memory amount in megabytes. */
				__( 'Your server does not support this module (PHP memory limit below %s MB).', 'brace' ),
				(string) round( ( (int) $value ) / ( 1024 * 1024 ) )
			),
			RequirementType::Binary => self::binaryExists( (string) $value ) ? null : sprintf(
				/* translators: %s: command line binary name. */
				__( 'Your server does not support this module (missing binary %s).', 'brace' ),
				(string) $value
			),
			RequirementType::Multisite => is_multisite() ? null : __( 'This module only works on a multisite installation.', 'brace' ),
		};
	}

	/**
	 * Whether the current PHP memory limit covers the required bytes.
	 *
	 * @param int $bytes Required bytes.
	 * @return bool
	 */
	private function memorySatisfied( int $bytes ): bool {
		$limit = self::bytes( (string) ini_get( 'memory_limit' ) );

		return -1 === $limit || $limit >= $bytes;
	}

	/**
	 * Whether an executable binary exists on the PATH.
	 *
	 * @param string $binary Binary name.
	 * @return bool
	 */
	private static function binaryExists( string $binary ): bool {
		$path = (string) getenv( 'PATH' );

		foreach ( explode( PATH_SEPARATOR, $path ) as $directory ) {
			if ( '' !== $directory && is_executable( $directory . DIRECTORY_SEPARATOR . $binary ) ) {
				return true;
			}
		}

		return false;
	}
}
