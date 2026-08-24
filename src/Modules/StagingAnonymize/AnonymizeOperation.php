<?php
/**
 * The anonymization operation.
 *
 * @package Brace
 */

namespace Brace\Modules\StagingAnonymize;

use Brace\Services\Backup;
use Brace\Services\Batch;
use Brace\Services\DestructiveOperation;
use Brace\Services\FakeIdentity;

/**
 * Rewrites every identity on a staging copy into a deterministic fake
 * (see FakeIdentity).
 *
 * WordPress core is always in scope: users, their meta, their passwords,
 * and comment authors. WooCommerce is anonymized on top when its data is
 * present — wherever it physically stores orders: HPOS tables, legacy
 * postmeta, or both when compatibility sync is on. Every stage asks the
 * database what exists rather than asking whether a plugin is active, so
 * a plain WordPress site skips the shop stages, a half-migrated store
 * gets both order locations cleaned, and a deactivated WooCommerce still
 * has its leftover customer tables scrubbed.
 *
 * Owner-decided exceptions, kept on purpose and reported honestly:
 * order notes (all of them) and wc-logs files on disk.
 *
 * Runs as a stage machine: execute() advances the current stage in
 * chunks until the Batch budget says stop, and is re-entrant within one
 * process. Idempotent: re-running rewrites fakes to the same fakes.
 */
final class AnonymizeOperation implements DestructiveOperation {

	// phpcs:disable WordPress.DB.DirectDatabaseQuery -- this whole class IS a database operation; caching is meaningless here.
	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- interpolated names are $wpdb table properties and validated prefix tables, never user input.

	/**
	 * Rows processed per chunk before re-checking the batch budget.
	 */
	public const DEFAULT_CHUNK = 200;

	/**
	 * Legacy order postmeta keys rewritten per order. Values derive from
	 * the identity that owns the order.
	 */
	private const LEGACY_ADDRESS_META = [
		'_billing_first_name',
		'_billing_last_name',
		'_billing_company',
		'_billing_address_1',
		'_billing_address_2',
		'_billing_email',
		'_billing_phone',
		'_shipping_first_name',
		'_shipping_last_name',
		'_shipping_company',
		'_shipping_address_1',
		'_shipping_address_2',
		'_shipping_phone',
		'_customer_ip_address',
		'_customer_user_agent',
		'_transaction_id',
	];

	/**
	 * Order meta keys wiped outright: references into real payment gateway
	 * accounts, in both HPOS meta and legacy postmeta.
	 */
	private const GATEWAY_META = [
		'_stripe_customer_id',
		'_stripe_source_id',
		'_stripe_intent_id',
		'_payment_intent_id',
		'_ppcp_paypal_payer_email',
		'_ppcp_paypal_payer_id',
		'_paypal_email',
		'Payer PayPal address',
		'_wc_braintree_customer_id',
	];

	/**
	 * User meta keys rewritten per user, mapped to an identity field.
	 */
	private const USER_META = [
		'first_name'          => 'first',
		'last_name'           => 'last',
		'nickname'            => 'name',
		'description'         => 'empty',
		'billing_first_name'  => 'first',
		'billing_last_name'   => 'last',
		'billing_company'     => 'empty',
		'billing_address_1'   => 'street',
		'billing_address_2'   => 'empty',
		'billing_phone'       => 'phone',
		'billing_email'       => 'email',
		'shipping_first_name' => 'first',
		'shipping_last_name'  => 'last',
		'shipping_company'    => 'empty',
		'shipping_address_1'  => 'street',
		'shipping_address_2'  => 'empty',
		'shipping_phone'      => 'phone',
	];

	/**
	 * Stage order. Coupons run first: they resolve real emails to user ids
	 * while the users table still holds real emails.
	 */
	private const STAGES = [
		'coupons',
		'users',
		'orders_hpos',
		'orders_legacy',
		'customer_lookup',
		'downloads',
		'comments',
		'sessions',
		'tokens',
		'webhooks',
	];

	/**
	 * Identity derivation.
	 *
	 * @var FakeIdentity
	 */
	private FakeIdentity $identity;

	/**
	 * Roles whose users keep their real identity and password.
	 *
	 * @var array<int,string>
	 */
	private array $excludedRoles;

	/**
	 * Rows processed per chunk.
	 *
	 * @var int
	 */
	private int $chunk;

	/**
	 * Index of the stage currently being processed.
	 *
	 * @var int
	 */
	private int $stageIndex = 0;

	/**
	 * Primary-key cursor within the current stage.
	 *
	 * @var int
	 */
	private int $cursor = 0;

	/**
	 * Whether the current stage has run its one-time prelude.
	 *
	 * @var bool
	 */
	private bool $stagePrepared = false;

	/**
	 * Rows changed per stage.
	 *
	 * @var array<string, int>
	 */
	private array $changed = [];

	/**
	 * User ids excluded from anonymization, resolved lazily.
	 *
	 * @var ?list<int>
	 */
	private ?array $excludedUserIds = null;

	/**
	 * The single password hash every anonymized user receives, computed
	 * once per run.
	 *
	 * @var ?string
	 */
	private ?string $passwordHash = null;

	/**
	 * Set up the operation.
	 *
	 * @param FakeIdentity      $identity       Identity derivation around the target mailbox.
	 * @param array<int,string> $excluded_roles Roles whose users are left untouched.
	 * @param int               $chunk          Rows processed per chunk.
	 */
	public function __construct( FakeIdentity $identity, array $excluded_roles = [ 'administrator' ], int $chunk = self::DEFAULT_CHUNK ) {
		$this->identity      = $identity;
		$this->excludedRoles = $excluded_roles;
		$this->chunk         = max( 1, $chunk );
	}

	/**
	 * Row counts per affected table, plus detected storages.
	 *
	 * @return array<string, int>
	 */
	public function estimate(): array {
		global $wpdb;

		$estimate = [
			'users'    => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->users}" ) - count( $this->excludedUserIds() ),
			'comments' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->comments} WHERE comment_type <> 'order_note'" ),
		];

		if ( $this->tableExists( $wpdb->prefix . 'wc_orders' ) ) {
			$estimate['orders_hpos'] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}wc_orders" );
		}

		$estimate['orders_legacy'] = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type IN ('shop_order', 'shop_order_refund')"
		);

		foreach ( [ 'wc_customer_lookup', 'woocommerce_sessions', 'woocommerce_payment_tokens', 'wc_webhooks', 'woocommerce_downloadable_product_permissions' ] as $suffix ) {
			if ( $this->tableExists( $wpdb->prefix . $suffix ) ) {
				$estimate[ $suffix ] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}{$suffix}" );
			}
		}

		return $estimate;
	}

	/**
	 * What would change, without changing anything.
	 *
	 * @return array<string, mixed>
	 */
	public function dryRun(): array {
		global $wpdb;

		$report = [
			'estimate'        => $this->estimate(),
			'storages'        => $this->detectedStorages(),
			'excluded_users'  => count( $this->excludedUserIds() ),
			'sample'          => [
				'user_42_email'    => $this->identity->email( 'userid', 42 ),
				'user_42_name'     => $this->identity->fullName( 42 ),
				'guest_order_1234' => $this->identity->email( 'order', 1234 ),
			],
			'kept_on_purpose' => $this->keptOnPurpose(),
		];

		if ( $this->tableExists( $wpdb->prefix . 'actionscheduler_actions' ) ) {
			$report['pending_scheduled_actions'] = (int) $wpdb->get_var(
				"SELECT COUNT(*) FROM {$wpdb->prefix}actionscheduler_actions WHERE status = 'pending'"
			);
		}

		return $report;
	}

	/**
	 * Back up every affected table that exists.
	 *
	 * The backup necessarily contains the PII this operation removes.
	 * Purge it once the run is verified; the CLI surfaces both the id
	 * and the purge command.
	 *
	 * @return string Backup identifier.
	 */
	public function backup(): string {
		$backup = new Backup();
		$id     = $backup->create();

		foreach ( $this->affectedTables() as $table ) {
			$backup->dumpTable( $table, $id );
		}

		return $id;
	}

	/**
	 * Advance the stage machine until done or out of budget.
	 *
	 * @param Batch $batch The budget for this tick.
	 * @return void
	 */
	public function execute( Batch $batch ): void {
		while ( ! $this->finished() && ! $batch->shouldStop() ) {
			// Explicit dispatch, not a computed method name: a typo in
			// STAGES must be a static error, not a fatal mid-run.
			$done = match ( self::STAGES[ $this->stageIndex ] ) {
				'coupons'         => $this->stageCoupons(),
				'users'           => $this->stageUsers(),
				'orders_hpos'     => $this->stageOrdersHpos(),
				'orders_legacy'   => $this->stageOrdersLegacy(),
				'customer_lookup' => $this->stageCustomerLookup(),
				'downloads'       => $this->stageDownloads(),
				'comments'        => $this->stageComments(),
				'sessions'        => $this->stageSessions(),
				'tokens'          => $this->stageTokens(),
				'webhooks'        => $this->stageWebhooks(),
			};

			if ( $done ) {
				++$this->stageIndex;
				$this->cursor        = 0;
				$this->stagePrepared = false;
			}
		}
	}

	/**
	 * Whether every stage has completed.
	 *
	 * @return bool
	 */
	public function finished(): bool {
		return $this->stageIndex >= count( self::STAGES );
	}

	/**
	 * The stage machine's position, serializable between requests.
	 *
	 * Under WP-CLI one process runs every tick and this is unnecessary.
	 * A browser-driven run is the opposite: each tick is a separate
	 * request with a fresh object, so without carrying this across, every
	 * tick would restart at stage zero and the run would never end.
	 *
	 * The password hash travels with it deliberately — it is generated
	 * once per run, and a run resumed with a new hash would leave users
	 * split across two different passwords.
	 *
	 * @return array<string, mixed>
	 */
	public function state(): array {
		return [
			'stage'    => $this->stageIndex,
			'cursor'   => $this->cursor,
			'prepared' => $this->stagePrepared,
			'changed'  => $this->changed,
			'hash'     => $this->passwordHash,
		];
	}

	/**
	 * Resume from a previously captured state.
	 *
	 * @param array<string, mixed> $state A state array from state().
	 * @return void
	 */
	public function restore( array $state ): void {
		$this->stageIndex    = max( 0, min( count( self::STAGES ), (int) ( $state['stage'] ?? 0 ) ) );
		$this->cursor        = max( 0, (int) ( $state['cursor'] ?? 0 ) );
		$this->stagePrepared = (bool) ( $state['prepared'] ?? false );
		$this->changed       = is_array( $state['changed'] ?? null ) ? array_map( 'intval', $state['changed'] ) : [];
		$this->passwordHash  = is_string( $state['hash'] ?? null ) ? $state['hash'] : null;
	}

	/**
	 * The stage currently being processed, for progress display.
	 *
	 * @return string
	 */
	public function currentStage(): string {
		return $this->finished() ? 'done' : self::STAGES[ $this->stageIndex ];
	}

	/**
	 * What actually changed.
	 *
	 * Carries the kept-on-purpose list with it: this array is what gets
	 * stored and shown weeks later, and a run report that lists only what
	 * was removed reads as "everything was removed".
	 *
	 * @return array<string, mixed>
	 */
	public function report(): array {
		return [
			'changed'         => $this->changed,
			'finished'        => $this->finished(),
			'storages'        => $this->detectedStorages(),
			'kept_on_purpose' => $this->keptOnPurpose(),
		];
	}

	/**
	 * The data classes this module deliberately does not touch.
	 *
	 * Spec section 2.4. Nothing reading a report from this module may claim
	 * "all PII removed" while this list is non-empty.
	 *
	 * @return list<string>
	 */
	private function keptOnPurpose(): array {
		$kept = [
			__( 'The options table and post content are not scanned; plugin settings and published posts can contain personal data.', 'brace' ),
		];

		if ( ! $this->wooDetected() ) {
			return $kept;
		}

		return array_merge(
			$kept,
			[
				__( 'Order notes, including customer-provided notes (owner decision; free text may contain PII).', 'brace' ),
				__( 'wc-logs files in uploads (owner decision; production logs may contain PII).', 'brace' ),
				__( 'City, postcode, country and state on all addresses (shipping zones and taxes keep working).', 'brace' ),
			]
		);
	}

	/**
	 * Whether this site has WooCommerce data to anonymize.
	 *
	 * Asked of the database, not of `class_exists`: a deactivated plugin
	 * leaves its customer tables behind, and those still hold the PII.
	 *
	 * @return bool
	 */
	public function wooDetected(): bool {
		global $wpdb;

		if ( [] !== $this->detectedStorages() ) {
			return true;
		}

		foreach ( [ 'wc_customer_lookup', 'woocommerce_sessions', 'woocommerce_payment_tokens', 'wc_webhooks' ] as $suffix ) {
			if ( $this->tableExists( $wpdb->prefix . $suffix ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Detected order storage locations.
	 *
	 * @return list<string>
	 */
	public function detectedStorages(): array {
		global $wpdb;

		$storages = [];

		if ( $this->tableExists( $wpdb->prefix . 'wc_orders' ) ) {
			$storages[] = 'hpos';
		}

		$legacy = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type IN ('shop_order', 'shop_order_refund') LIMIT 1"
		);
		if ( $legacy > 0 ) {
			$storages[] = 'legacy_postmeta';
		}

		return $storages;
	}

	/**
	 * Coupons stage: `_used_by` meta holds one row per coupon use, valued
	 * with a user id (kept: not PII) or a billing email. Emails still map
	 * to real users at this point, so resolvable ones become that user's
	 * fake address; unresolvable guest emails key off the meta row id.
	 *
	 * @return bool Stage finished.
	 */
	private function stageCoupons(): bool {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT pm.meta_id, pm.meta_value FROM {$wpdb->postmeta} pm
				 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id AND p.post_type = 'shop_coupon'
				 WHERE pm.meta_key = '_used_by' AND pm.meta_id > %d ORDER BY pm.meta_id LIMIT %d",
				$this->cursor,
				$this->chunk
			),
			ARRAY_A
		);

		if ( [] === $rows ) {
			return true;
		}

		foreach ( $rows as $row ) {
			$this->cursor = (int) $row['meta_id'];
			$value        = (string) $row['meta_value'];

			if ( '' === $value || ctype_digit( $value ) ) {
				continue; // A user id: stable and not PII.
			}

			$user = get_user_by( 'email', $value );
			$fake = ( false !== $user )
				? $this->identity->email( 'userid', (int) $user->ID )
				: $this->identity->email( 'coupon', (int) $row['meta_id'] );

			$wpdb->update( $wpdb->postmeta, [ 'meta_value' => $fake ], [ 'meta_id' => (int) $row['meta_id'] ] );
			$this->bump( 'coupons' );
		}

		return count( $rows ) < $this->chunk;
	}

	/**
	 * Users stage: core row, meta, password, session tokens.
	 *
	 * @return bool Stage finished.
	 */
	private function stageUsers(): bool {
		global $wpdb;

		$excluded = $this->excludedUserIds();

		if ( ! $this->stagePrepared ) {
			$this->stagePrepared = true;

			// One hash for the whole run. wp_hash_password is deliberately
			// slow, so hashing per user turns a 100k-user store into hours
			// of CPU. The password is random and never printed: nobody can
			// log in with it either way.
			$this->passwordHash = wp_hash_password( wp_generate_password( 32, true, true ) );

			// Free the deterministic login namespace before assigning into
			// it. A real login that already reads "user7" would collide
			// with the fake login assigned to user 7, and user_login is
			// UNIQUE. Excluded users keep theirs.
			$keep = '' === implode( ',', $excluded ) ? '0' : implode( ',', array_map( 'intval', $excluded ) );
			$wpdb->query(
				"UPDATE {$wpdb->users} SET user_login = CONCAT('brace_pending_', ID)
				 WHERE user_login REGEXP '^user[0-9]+$'
				   AND user_login <> CONCAT('user', ID)
				   AND ID NOT IN ({$keep})"
			);
		}

		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->users} WHERE ID > %d ORDER BY ID LIMIT %d",
				$this->cursor,
				$this->chunk
			)
		);

		if ( [] === $ids ) {
			return true;
		}

		foreach ( $ids as $id ) {
			$id           = (int) $id;
			$this->cursor = $id;

			if ( in_array( $id, $excluded, true ) ) {
				continue;
			}

			$wpdb->update(
				$wpdb->users,
				[
					'user_email'          => $this->identity->email( 'userid', $id ),
					'user_url'            => '',
					'display_name'        => $this->identity->fullName( $id ),
					'user_pass'           => (string) $this->passwordHash,
					'user_activation_key' => '',
				],
				[ 'ID' => $id ]
			);

			// Separate statement on purpose. user_login is UNIQUE, and a
			// collision the prelude could not clear (an excluded user
			// holding that login) must never roll back the identity
			// removal above. A kept login is cosmetic; a kept email is a leak.
			$renamed = $wpdb->update(
				$wpdb->users,
				[
					'user_login'    => $this->identity->login( $id ),
					'user_nicename' => $this->identity->login( $id ),
				],
				[ 'ID' => $id ]
			);

			if ( false === $renamed ) {
				$this->bump( 'login_collisions' );
			}

			foreach ( self::USER_META as $key => $kind ) {
				// Updates only rows that exist: no meta noise on non-customers.
				$wpdb->update(
					$wpdb->usermeta,
					[ 'meta_value' => $this->identityValue( $kind, $id ) ],
					[
						'user_id'  => $id,
						'meta_key' => $key,
					]
				);
			}

			$wpdb->delete(
				$wpdb->usermeta,
				[
					'user_id'  => $id,
					'meta_key' => 'session_tokens',
				]
			);

			clean_user_cache( $id );
			$this->bump( 'users' );
		}

		return count( $ids ) < $this->chunk;
	}

	/**
	 * HPOS stage: wc_orders, wc_order_addresses, gateway meta.
	 *
	 * @return bool Stage finished.
	 */
	private function stageOrdersHpos(): bool {
		global $wpdb;

		$orders    = $wpdb->prefix . 'wc_orders';
		$addresses = $wpdb->prefix . 'wc_order_addresses';
		$meta      = $wpdb->prefix . 'wc_orders_meta';

		if ( ! $this->tableExists( $orders ) ) {
			return true;
		}

		if ( ! $this->stagePrepared ) {
			$this->stagePrepared = true;

			if ( $this->tableExists( $meta ) ) {
				$keys = implode( ',', array_fill( 0, count( self::GATEWAY_META ), '%s' ) );
				$wpdb->query(
					$wpdb->prepare( "UPDATE {$meta} SET meta_value = '' WHERE meta_key IN ({$keys})", self::GATEWAY_META ) // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
				);
			}
		}

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, customer_id FROM {$orders} WHERE id > %d ORDER BY id LIMIT %d",
				$this->cursor,
				$this->chunk
			),
			ARRAY_A
		);

		if ( [] === $rows ) {
			return true;
		}

		$excluded = $this->excludedUserIds();

		foreach ( $rows as $row ) {
			$order_id     = (int) $row['id'];
			$this->cursor = $order_id;
			$customer     = (int) $row['customer_id'];

			if ( $customer > 0 && in_array( $customer, $excluded, true ) ) {
				continue;
			}

			[ $entity, $key ] = $customer > 0 ? [ 'userid', $customer ] : [ 'order', $order_id ];

			$wpdb->update(
				$orders,
				[
					'billing_email'  => $this->identity->email( $entity, $key ),
					'ip_address'     => $this->identity->ip(),
					'user_agent'     => $this->identity->userAgent(),
					'transaction_id' => '',
				],
				[ 'id' => $order_id ]
			);

			if ( $this->tableExists( $addresses ) ) {
				$wpdb->update(
					$addresses,
					[
						'first_name' => $this->identity->firstName( $key ),
						'last_name'  => $this->identity->lastName( $key ),
						'company'    => '',
						'address_1'  => $this->identity->street( $key ),
						'address_2'  => '',
						'email'      => $this->identity->email( $entity, $key ),
						'phone'      => $this->identity->phone( $key ),
					],
					[ 'order_id' => $order_id ]
				);
			}

			$this->bump( 'orders_hpos' );
		}

		return count( $rows ) < $this->chunk;
	}

	/**
	 * Legacy stage: order postmeta for shop_order and shop_order_refund
	 * posts. Runs regardless of HPOS: with compatibility sync on, PII
	 * lives in both storages, and HPOS placeholder posts simply have no
	 * matching meta rows to update.
	 *
	 * @return bool Stage finished.
	 */
	private function stageOrdersLegacy(): bool {
		global $wpdb;

		if ( ! $this->stagePrepared ) {
			$this->stagePrepared = true;

			$keys = implode( ',', array_fill( 0, count( self::GATEWAY_META ), '%s' ) );
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
					 SET pm.meta_value = ''
					 WHERE p.post_type IN ('shop_order', 'shop_order_refund') AND pm.meta_key IN ({$keys})", // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
					self::GATEWAY_META
				)
			);
		}

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT p.ID, COALESCE(pm.meta_value, '0') AS customer FROM {$wpdb->posts} p
				 LEFT JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = '_customer_user'
				 WHERE p.post_type IN ('shop_order', 'shop_order_refund') AND p.ID > %d
				 ORDER BY p.ID LIMIT %d",
				$this->cursor,
				$this->chunk
			),
			ARRAY_A
		);

		if ( [] === $rows ) {
			return true;
		}

		$excluded = $this->excludedUserIds();

		foreach ( $rows as $row ) {
			$order_id     = (int) $row['ID'];
			$this->cursor = $order_id;
			$customer     = (int) $row['customer'];

			if ( $customer > 0 && in_array( $customer, $excluded, true ) ) {
				continue;
			}

			[ $entity, $key ] = $customer > 0 ? [ 'userid', $customer ] : [ 'order', $order_id ];

			foreach ( self::LEGACY_ADDRESS_META as $meta_key ) {
				$wpdb->update(
					$wpdb->postmeta,
					[ 'meta_value' => $this->legacyMetaValue( $meta_key, $entity, $key ) ],
					[
						'post_id'  => $order_id,
						'meta_key' => $meta_key,
					]
				);
			}

			clean_post_cache( $order_id );
			$this->bump( 'orders_legacy' );
		}

		return count( $rows ) < $this->chunk;
	}

	/**
	 * Analytics customer lookup stage.
	 *
	 * @return bool Stage finished.
	 */
	private function stageCustomerLookup(): bool {
		global $wpdb;

		$table = $wpdb->prefix . 'wc_customer_lookup';

		if ( ! $this->tableExists( $table ) ) {
			return true;
		}

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT customer_id, user_id FROM {$table} WHERE customer_id > %d ORDER BY customer_id LIMIT %d",
				$this->cursor,
				$this->chunk
			),
			ARRAY_A
		);

		if ( [] === $rows ) {
			return true;
		}

		$excluded = $this->excludedUserIds();

		foreach ( $rows as $row ) {
			$customer_id  = (int) $row['customer_id'];
			$this->cursor = $customer_id;
			$user_id      = (int) $row['user_id'];

			if ( $user_id > 0 && in_array( $user_id, $excluded, true ) ) {
				continue;
			}

			[ $entity, $key ] = $user_id > 0 ? [ 'userid', $user_id ] : [ 'customer', $customer_id ];

			$wpdb->update(
				$table,
				[
					'first_name' => $this->identity->firstName( $key ),
					'last_name'  => $this->identity->lastName( $key ),
					'email'      => $this->identity->email( $entity, $key ),
					'username'   => $user_id > 0 ? $this->identity->login( $user_id ) : '',
				],
				[ 'customer_id' => $customer_id ]
			);

			$this->bump( 'customer_lookup' );
		}

		return count( $rows ) < $this->chunk;
	}

	/**
	 * Download permissions and download log stage.
	 *
	 * @return bool Stage finished.
	 */
	private function stageDownloads(): bool {
		global $wpdb;

		$permissions = $wpdb->prefix . 'woocommerce_downloadable_product_permissions';

		if ( ! $this->stagePrepared ) {
			$this->stagePrepared = true;

			$log = $wpdb->prefix . 'wc_download_log';
			if ( $this->tableExists( $log ) ) {
				$wpdb->query( $wpdb->prepare( "UPDATE {$log} SET user_ip_address = %s", $this->identity->ip() ) );
			}
		}

		if ( ! $this->tableExists( $permissions ) ) {
			return true;
		}

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT permission_id, order_id, user_id FROM {$permissions} WHERE permission_id > %d ORDER BY permission_id LIMIT %d",
				$this->cursor,
				$this->chunk
			),
			ARRAY_A
		);

		if ( [] === $rows ) {
			return true;
		}

		$excluded = $this->excludedUserIds();

		foreach ( $rows as $row ) {
			$this->cursor = (int) $row['permission_id'];
			$user_id      = (int) $row['user_id'];

			if ( $user_id > 0 && in_array( $user_id, $excluded, true ) ) {
				continue;
			}

			$email = $user_id > 0
				? $this->identity->email( 'userid', $user_id )
				: $this->identity->email( 'order', (int) $row['order_id'] );

			$wpdb->update( $permissions, [ 'user_email' => $email ], [ 'permission_id' => (int) $row['permission_id'] ] );
			$this->bump( 'downloads' );
		}

		return count( $rows ) < $this->chunk;
	}

	/**
	 * Comments stage: product reviews and blog comments carry commenter
	 * PII. Order notes are skipped (owner decision, section 2.4 of the
	 * spec).
	 *
	 * @return bool Stage finished.
	 */
	private function stageComments(): bool {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT comment_ID, user_id FROM {$wpdb->comments}
				 WHERE comment_type <> 'order_note' AND comment_ID > %d ORDER BY comment_ID LIMIT %d",
				$this->cursor,
				$this->chunk
			),
			ARRAY_A
		);

		if ( [] === $rows ) {
			return true;
		}

		$excluded = $this->excludedUserIds();

		foreach ( $rows as $row ) {
			$comment_id   = (int) $row['comment_ID'];
			$this->cursor = $comment_id;
			$user_id      = (int) $row['user_id'];

			if ( $user_id > 0 && in_array( $user_id, $excluded, true ) ) {
				continue;
			}

			[ $entity, $key ] = $user_id > 0 ? [ 'userid', $user_id ] : [ 'comment', $comment_id ];

			$wpdb->update(
				$wpdb->comments,
				[
					'comment_author'       => $this->identity->fullName( $key ),
					'comment_author_email' => $this->identity->email( $entity, $key ),
					'comment_author_url'   => '',
					'comment_author_IP'    => $this->identity->ip(),
				],
				[ 'comment_ID' => $comment_id ]
			);

			clean_comment_cache( $comment_id );
			$this->bump( 'comments' );
		}

		return count( $rows ) < $this->chunk;
	}

	/**
	 * Sessions stage: serialized carts full of addresses. Truncated, they
	 * regenerate on staging.
	 *
	 * @return bool Stage finished.
	 */
	private function stageSessions(): bool {
		global $wpdb;

		$table = $wpdb->prefix . 'woocommerce_sessions';

		if ( $this->tableExists( $table ) ) {
			$this->changed['sessions'] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
			$wpdb->query( "TRUNCATE TABLE {$table}" );
		}

		return true;
	}

	/**
	 * Payment tokens stage: stored cards reference real gateway customers.
	 * Deleted, with their meta.
	 *
	 * @return bool Stage finished.
	 */
	private function stageTokens(): bool {
		global $wpdb;

		$tokens = $wpdb->prefix . 'woocommerce_payment_tokens';
		$meta   = $wpdb->prefix . 'woocommerce_payment_tokenmeta';

		if ( $this->tableExists( $tokens ) ) {
			$this->changed['tokens'] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$tokens}" );
			$wpdb->query( "TRUNCATE TABLE {$tokens}" );
		}

		if ( $this->tableExists( $meta ) ) {
			$wpdb->query( "TRUNCATE TABLE {$meta}" );
		}

		return true;
	}

	/**
	 * Webhooks stage: an anonymized copy must not keep delivering order
	 * data to production endpoints.
	 *
	 * @return bool Stage finished.
	 */
	private function stageWebhooks(): bool {
		global $wpdb;

		$table = $wpdb->prefix . 'wc_webhooks';

		if ( $this->tableExists( $table ) ) {
			$this->changed['webhooks'] = (int) $wpdb->query(
				"UPDATE {$table} SET status = 'disabled' WHERE status = 'active'"
			);
		}

		return true;
	}

	/**
	 * Tables this operation writes to and that exist right now.
	 *
	 * @return list<string>
	 */
	public function affectedTables(): array {
		global $wpdb;

		$candidates = [
			$wpdb->users,
			$wpdb->usermeta,
			$wpdb->comments,
			$wpdb->postmeta,
			$wpdb->prefix . 'wc_orders',
			$wpdb->prefix . 'wc_order_addresses',
			$wpdb->prefix . 'wc_orders_meta',
			$wpdb->prefix . 'wc_customer_lookup',
			$wpdb->prefix . 'woocommerce_sessions',
			$wpdb->prefix . 'woocommerce_payment_tokens',
			$wpdb->prefix . 'woocommerce_payment_tokenmeta',
			$wpdb->prefix . 'woocommerce_downloadable_product_permissions',
			$wpdb->prefix . 'wc_download_log',
			$wpdb->prefix . 'wc_webhooks',
		];

		return array_values( array_filter( $candidates, [ $this, 'tableExists' ] ) );
	}

	/**
	 * User ids excluded from anonymization, from the excluded roles.
	 *
	 * @return list<int>
	 */
	private function excludedUserIds(): array {
		global $wpdb;

		if ( null !== $this->excludedUserIds ) {
			return $this->excludedUserIds;
		}

		$ids = [];

		foreach ( $this->excludedRoles as $role ) {
			$found = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key = %s AND meta_value LIKE %s",
					$wpdb->prefix . 'capabilities',
					'%"' . $wpdb->esc_like( $role ) . '"%'
				)
			);

			foreach ( $found as $id ) {
				$ids[] = (int) $id;
			}
		}

		$this->excludedUserIds = array_values( array_unique( $ids ) );

		return $this->excludedUserIds;
	}

	/**
	 * Identity value for a user meta kind.
	 *
	 * @param string $kind One of first, last, name, street, phone, email, empty.
	 * @param int    $id   The user id.
	 * @return string
	 */
	private function identityValue( string $kind, int $id ): string {
		return match ( $kind ) {
			'first'  => $this->identity->firstName( $id ),
			'last'   => $this->identity->lastName( $id ),
			'name'   => $this->identity->fullName( $id ),
			'street' => $this->identity->street( $id ),
			'phone'  => $this->identity->phone( $id ),
			'email'  => $this->identity->email( 'userid', $id ),
			default  => '',
		};
	}

	/**
	 * Identity value for a legacy order meta key.
	 *
	 * @param string $meta_key The postmeta key.
	 * @param string $entity   Identity entity kind (userid or order).
	 * @param int    $key      Identity primary key.
	 * @return string
	 */
	private function legacyMetaValue( string $meta_key, string $entity, int $key ): string {
		if ( str_ends_with( $meta_key, 'first_name' ) ) {
			return $this->identity->firstName( $key );
		}
		if ( str_ends_with( $meta_key, 'last_name' ) ) {
			return $this->identity->lastName( $key );
		}
		if ( str_ends_with( $meta_key, 'address_1' ) ) {
			return $this->identity->street( $key );
		}
		if ( str_ends_with( $meta_key, 'phone' ) ) {
			return $this->identity->phone( $key );
		}
		if ( '_billing_email' === $meta_key ) {
			return $this->identity->email( $entity, $key );
		}
		if ( '_customer_ip_address' === $meta_key ) {
			return $this->identity->ip();
		}
		if ( '_customer_user_agent' === $meta_key ) {
			return $this->identity->userAgent();
		}

		return ''; // company, address_2, transaction_id.
	}

	/**
	 * Whether a table exists in the current database.
	 *
	 * @param string $table Full table name including prefix.
	 * @return bool
	 */
	private function tableExists( string $table ): bool {
		global $wpdb;

		static $cache = [];

		if ( ! array_key_exists( $table, $cache ) ) {
			$cache[ $table ] = $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		}

		return $cache[ $table ];
	}

	/**
	 * Count one changed row for a stage.
	 *
	 * @param string $stage Stage name.
	 * @return void
	 */
	private function bump( string $stage ): void {
		$this->changed[ $stage ] = ( $this->changed[ $stage ] ?? 0 ) + 1;
	}

	// phpcs:enable
}
