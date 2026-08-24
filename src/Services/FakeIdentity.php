<?php
/**
 * Deterministic fake identity derivation.
 *
 * @package Brace
 */

namespace Brace\Services;

/**
 * Every fake value is a pure function of an entity's primary key: same id,
 * same fake, on every run and every re-clone. No randomness, no state, no
 * mapping table.
 *
 * Traceback lives in the email address itself. With the target mailbox
 * admin@example.com, user 42 becomes admin+userid.42@example.com: unique
 * per entity (plus-addressing), deliverable only to the operator's own
 * mailbox, and carrying the production primary key in plain sight.
 *
 * Names are deliberately not plausible. An earlier pool held ordinary Czech
 * names, and on a Czech shop the result was unreadable: nobody looking at
 * the orders screen could tell an anonymized record from a live one, which
 * is the exact question staging has to answer at a glance. Every name now
 * carries the NAME_MARKER prefix — "FJohnny FMarlowe" — so a fake is
 * obvious on sight and greppable across the whole database.
 */
final class FakeIdentity {

	/**
	 * Prefix stamped onto every generated name.
	 *
	 * Chosen to be something no real first or last name starts with, so
	 * `grep -r '\bF[A-Z]'` over a dump finds every anonymized name and
	 * nothing else. Changing it changes every derived name, which is fine:
	 * derivation is a pure function, so a re-run rewrites them all.
	 */
	private const NAME_MARKER = 'F';

	/**
	 * First name pool. Index is derived from the entity id. Count is prime
	 * so the id walks the whole pool before repeating.
	 */
	private const FIRST_NAMES = [
		'Aaron',
		'Abigail',
		'Adrian',
		'Alice',
		'Amber',
		'Andrew',
		'Angela',
		'Arthur',
		'Ashley',
		'Benjamin',
		'Beverly',
		'Brandon',
		'Brenda',
		'Caleb',
		'Carmen',
		'Chloe',
		'Clara',
		'Colin',
		'Damon',
		'Deborah',
		'Derek',
		'Dorothy',
		'Edith',
		'Elliot',
		'Emily',
		'Ethan',
		'Felicity',
		'Fiona',
		'Gavin',
		'Gloria',
		'Harold',
		'Hazel',
		'Isaac',
		'Ivy',
		'Jasper',
		'Jenna',
		'Johnny',
		'Julian',
		'Kelvin',
		'Laura',
		'Marius',
		'Melvin',
		'Nadia',
		'Oscar',
		'Pamela',
		'Quentin',
		'Rosalind',
	];

	/**
	 * Last name pool. Index is derived from the entity id. Count is prime
	 * and coprime with the lastName() multiplier, so first and last names
	 * do not walk their pools in lockstep.
	 */
	private const LAST_NAMES = [
		'Ashford',
		'Bancroft',
		'Barlow',
		'Beckett',
		'Blackwood',
		'Bramley',
		'Carrington',
		'Chandler',
		'Cromwell',
		'Dalton',
		'Ellery',
		'Fairbanks',
		'Fenwick',
		'Garrick',
		'Grimsby',
		'Halloway',
		'Harding',
		'Hollis',
		'Ingram',
		'Kendrick',
		'Langley',
		'Lockhart',
		'Marlowe',
		'Merrick',
		'Norwood',
		'Oakley',
		'Pemberton',
		'Prescott',
		'Quimby',
		'Radcliffe',
		'Rutherford',
		'Sinclair',
		'Stanton',
		'Thackeray',
		'Thornbury',
		'Underhill',
		'Vandermeer',
		'Whitfield',
		'Winslow',
		'Yardley',
		'Ziegler',
	];

	/**
	 * Local part of the target mailbox (before the @).
	 *
	 * @var string
	 */
	private string $localPart;

	/**
	 * Domain of the target mailbox (after the @).
	 *
	 * @var string
	 */
	private string $domain;

	/**
	 * Set up the derivation around a target mailbox.
	 *
	 * @param string $mailbox The real mailbox all fake addresses deliver to.
	 * @throws \InvalidArgumentException When the mailbox is not a plain email address.
	 */
	public function __construct( string $mailbox ) {
		$at = strrpos( $mailbox, '@' );

		if ( false === $at || 0 === $at || strlen( $mailbox ) - 1 === $at ) {
			throw new \InvalidArgumentException(
				sprintf( 'FakeIdentity needs a valid target mailbox, got "%s".', esc_html( $mailbox ) )
			);
		}

		$this->localPart = substr( $mailbox, 0, $at );
		$this->domain    = substr( $mailbox, $at + 1 );
	}

	/**
	 * The plus-addressed email for one entity.
	 *
	 * @param string $entity Entity kind: userid, order, customer, comment, coupon.
	 * @param int    $id     The entity's primary key on production.
	 * @return string
	 */
	public function email( string $entity, int $id ): string {
		return sprintf( '%s+%s.%d@%s', $this->localPart, $entity, $id, $this->domain );
	}

	/**
	 * Deterministic first name for an id.
	 *
	 * @param int $id The entity's primary key.
	 * @return string
	 */
	public function firstName( int $id ): string {
		return self::NAME_MARKER . self::FIRST_NAMES[ $id % count( self::FIRST_NAMES ) ];
	}

	/**
	 * Deterministic last name for an id.
	 *
	 * The multiplier decorrelates it from the first-name index, so
	 * consecutive ids do not walk both pools in lockstep.
	 *
	 * @param int $id The entity's primary key.
	 * @return string
	 */
	public function lastName( int $id ): string {
		return self::NAME_MARKER . self::LAST_NAMES[ ( $id * 31 ) % count( self::LAST_NAMES ) ];
	}

	/**
	 * Deterministic display name for an id.
	 *
	 * @param int $id The entity's primary key.
	 * @return string
	 */
	public function fullName( int $id ): string {
		return $this->firstName( $id ) . ' ' . $this->lastName( $id );
	}

	/**
	 * Deterministic login and nicename for a user id.
	 *
	 * @param int $id The user's primary key.
	 * @return string
	 */
	public function login( int $id ): string {
		return 'user' . $id;
	}

	/**
	 * Deterministic phone number for an id. Valid-looking format, keyed to
	 * the id, not guaranteed to be unassigned; staging must not dial it.
	 *
	 * @param int $id The entity's primary key.
	 * @return string
	 */
	public function phone( int $id ): string {
		return '700' . str_pad( (string) ( $id % 1000000 ), 6, '0', STR_PAD_LEFT );
	}

	/**
	 * Deterministic street line for an id. City, postcode, and country are
	 * deliberately not derived here: the caller keeps the originals.
	 *
	 * @param int $id The entity's primary key.
	 * @return string
	 */
	public function street( int $id ): string {
		return 'Testovaci ' . $id;
	}

	/**
	 * Replacement IP address for anonymized records.
	 *
	 * @return string
	 */
	public function ip(): string {
		return '127.0.0.1';
	}

	/**
	 * Replacement user agent string for anonymized records.
	 *
	 * @return string
	 */
	public function userAgent(): string {
		return 'Mozilla/5.0 (anonymized by Brace)';
	}
}
