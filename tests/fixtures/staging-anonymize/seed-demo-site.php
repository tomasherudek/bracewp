<?php
/**
 * Seeds a dev site with demo people, comments and orders.
 *
 * Not a test fixture — the automated integration suite described in section 7
 * of the spec does not exist yet. This is the manual stand-in: it fills a
 * throwaway site with realistic-looking Czech personal data so that a run of
 * the module can be judged by eye. Every value here is invented.
 *
 * Run it from the site root:
 *
 *   wp eval-file wp-content/plugins/brace/tests/fixtures/staging-anonymize/seed-demo-site.php
 *
 * Re-running is safe: people are matched by email and orders are only added
 * up to TARGET_ORDERS.
 *
 * @package Brace
 */

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	exit( 1 );
}

const TARGET_USERS  = 30;
const TARGET_ORDERS = 10;

$woo = class_exists( 'WooCommerce' );

WP_CLI::log( $woo ? 'WooCommerce detected: seeding users, comments and orders.' : 'No WooCommerce: seeding users and comments only.' );

/*
 * ---------------------------------------------------------------------------
 * People
 * ---------------------------------------------------------------------------
 */

$people = [
	[ 'Jan', 'Novák', 'jan.novak@seznam.cz', 'Korunní 812/45', 'Praha 2', '12000', '602 118 445' ],
	[ 'Petra', 'Svobodová', 'petra.svobodova@gmail.com', 'Veveří 2033/119', 'Brno', '61600', '731 904 226' ],
	[ 'Tomáš', 'Dvořák', 'tomas.dvorak83@email.cz', 'Nádražní 1102/24', 'Ostrava', '70200', '605 337 810' ],
	[ 'Lucie', 'Černá', 'lucie.cerna@centrum.cz', 'Masarykova 421/7', 'Plzeň', '30100', '777 214 663' ],
	[ 'Martin', 'Procházka', 'm.prochazka@seznam.cz', 'Sokolská 1580/12', 'Olomouc', '77900', '724 561 098' ],
	[ 'Eva', 'Kučerová', 'eva.kucerova@gmail.com', 'Havlíčkova 335/2', 'Liberec', '46001', '608 442 719' ],
	[ 'Jakub', 'Veselý', 'jakub.vesely@post.cz', 'Palackého 907/61', 'Hradec Králové', '50002', '739 128 350' ],
	[ 'Tereza', 'Horáková', 'tereza.horakova@seznam.cz', 'Legionářská 44/9', 'České Budějovice', '37001', '602 883 174' ],
	[ 'Ondřej', 'Němec', 'ondrej.nemec@volny.cz', 'Vinohradská 2165/48', 'Praha 3', '13000', '776 019 542' ],
	[ 'Kateřina', 'Marková', 'k.markova@gmail.com', 'Bezručova 118/3', 'Zlín', '76001', '605 771 236' ],
	[ 'Filip', 'Pospíšil', 'filip.pospisil@seznam.cz', 'Wilsonova 674/22', 'Pardubice', '53002', '731 445 608' ],
	[ 'Veronika', 'Králová', 'veronika.kralova@email.cz', 'Zahradní 1290/16', 'Jihlava', '58601', '724 902 137' ],
	[ 'David', 'Beneš', 'david.benes@centrum.cz', 'Riegrova 503/31', 'Karlovy Vary', '36001', '608 316 774' ],
	[ 'Markéta', 'Fialová', 'marketa.fialova@gmail.com', 'Křížová 2044/8', 'Ústí nad Labem', '40001', '777 630 285' ],
	[ 'Lukáš', 'Sedláček', 'lukas.sedlacek@seznam.cz', 'Nerudova 156/40', 'Kladno', '27201', '739 208 461' ],
	[ 'Barbora', 'Pokorná', 'bara.pokorna@post.cz', 'Lidická 1783/57', 'Most', '43401', '602 594 013' ],
	[ 'Michal', 'Zeman', 'michal.zeman@volny.cz', 'Tyršova 690/11', 'Opava', '74601', '605 128 947' ],
	[ 'Nikola', 'Kolářová', 'nikola.kolarova@gmail.com', 'Dlouhá 342/19', 'Frýdek-Místek', '73801', '724 837 502' ],
	[ 'Adam', 'Urban', 'adam.urban@seznam.cz', 'Jiráskova 1024/6', 'Teplice', '41501', '776 345 190' ],
	[ 'Simona', 'Blažková', 'simona.blazkova@email.cz', 'Hlavní 78/2', 'Děčín', '40502', '731 660 428' ],
	[ 'Vojtěch', 'Říha', 'vojtech.riha@centrum.cz', 'Slezská 1466/93', 'Praha 2', '12000', '608 271 583' ],
	[ 'Klára', 'Malá', 'klara.mala@gmail.com', 'Purkyňova 2810/104', 'Brno', '61200', '777 452 916' ],
	[ 'Štěpán', 'Kříž', 'stepan.kriz@seznam.cz', 'Husova 215/28', 'Chomutov', '43001', '739 813 267' ],
	[ 'Aneta', 'Vaňková', 'aneta.vankova@post.cz', 'Komenského 940/13', 'Přerov', '75002', '602 736 045' ],
	[ 'Radek', 'Bureš', 'radek.bures@volny.cz', 'Rooseveltova 588/35', 'Jablonec nad Nisou', '46601', '605 490 812' ],
	[ 'Denisa', 'Hájková', 'denisa.hajkova@gmail.com', 'Bratislavská 1327/70', 'Znojmo', '66902', '724 158 673' ],
	[ 'Patrik', 'Kratochvíl', 'patrik.kratochvil@seznam.cz', 'Českobratrská 405/9', 'Trutnov', '54101', '776 924 350' ],
	[ 'Michaela', 'Doležalová', 'michaela.dolezalova@email.cz', 'Nábřežní 1671/44', 'Písek', '39701', '731 507 289' ],
	[ 'Roman', 'Vlček', 'roman.vlcek@centrum.cz', 'Zborovská 262/17', 'Tábor', '39001', '608 843 176' ],
	[ 'Gabriela', 'Krejčí', 'gabriela.krejci@gmail.com', 'Smetanova 1108/26', 'Havířov', '73601', '777 361 954' ],
];

$created  = 0;
$user_ids = [];

foreach ( array_slice( $people, 0, TARGET_USERS ) as $index => $person ) {
	[ $first, $last, $email, $street, $city, $postcode, $phone ] = $person;

	$existing = get_user_by( 'email', $email );

	if ( false !== $existing ) {
		$user_ids[] = (int) $existing->ID;
		continue;
	}

	/*
	 * The 8th person gets the login "user7" on purpose. The anonymizer
	 * assigns deterministic logins of exactly that shape, so this seeds a
	 * genuine UNIQUE collision on wp_users.user_login and exercises the
	 * prelude-rename path (spec section 2.1). Without it that branch never
	 * runs on this dataset.
	 */
	$login = 7 === $index
		? 'user7'
		: sanitize_user( strtolower( remove_accents( $first . '.' . $last ) ), true );

	$user_id = wp_insert_user(
		[
			'user_login'   => $login,
			'user_email'   => $email,
			'user_pass'    => wp_generate_password( 16 ),
			'first_name'   => $first,
			'last_name'    => $last,
			'display_name' => $first . ' ' . $last,
			'user_url'     => 'https://' . strtolower( remove_accents( $last ) ) . '.example.cz',
			'description'  => sprintf( 'Zákazník z města %s, telefon %s.', $city, $phone ),
			'role'         => $woo ? 'customer' : 'subscriber',
		]
	);

	if ( is_wp_error( $user_id ) ) {
		WP_CLI::warning( 'Could not create ' . $email . ': ' . $user_id->get_error_message() );
		continue;
	}

	foreach ( [ 'billing', 'shipping' ] as $kind ) {
		update_user_meta( $user_id, $kind . '_first_name', $first );
		update_user_meta( $user_id, $kind . '_last_name', $last );
		update_user_meta( $user_id, $kind . '_address_1', $street );
		update_user_meta( $user_id, $kind . '_city', $city );
		update_user_meta( $user_id, $kind . '_postcode', $postcode );
		update_user_meta( $user_id, $kind . '_country', 'CZ' );
		update_user_meta( $user_id, $kind . '_phone', $phone );
	}
	update_user_meta( $user_id, 'billing_email', $email );

	$user_ids[] = (int) $user_id;
	++$created;
}

WP_CLI::log( sprintf( 'Users: %d created, %d in the demo set.', $created, count( $user_ids ) ) );

/*
 * ---------------------------------------------------------------------------
 * Comments — the comments stage must have work to do on a non-shop site too
 * ---------------------------------------------------------------------------
 */

$posts = get_posts(
	[
		'numberposts' => 1,
		'fields'      => 'ids',
	]
);
$post_id = (int) ( $posts[0] ?? 0 );

if ( $post_id > 0 && count( get_comments( [ 'post_id' => $post_id ] ) ) < 4 ) {
	// One registered commenter, one guest: the stage keys them differently.
	wp_insert_comment(
		[
			'comment_post_ID'      => $post_id,
			'user_id'              => $user_ids[0] ?? 0,
			'comment_author'       => 'Jan Novák',
			'comment_author_email' => 'jan.novak@seznam.cz',
			'comment_author_url'   => 'https://novak.example.cz',
			'comment_author_IP'    => '89.24.112.7',
			'comment_content'      => 'Díky za článek, psal jsem vám na mail.',
			'comment_approved'     => 1,
		]
	);
	wp_insert_comment(
		[
			'comment_post_ID'      => $post_id,
			'user_id'              => 0,
			'comment_author'       => 'Hana Šimková',
			'comment_author_email' => 'hana.simkova@seznam.cz',
			'comment_author_url'   => '',
			'comment_author_IP'    => '213.220.194.51',
			'comment_content'      => 'Volejte mi prosím na 606 771 320.',
			'comment_approved'     => 1,
		]
	);
	WP_CLI::log( 'Comments: 2 created (1 registered, 1 guest).' );
}

if ( ! $woo ) {
	WP_CLI::success( 'Done. Install WooCommerce and re-run to seed orders too.' );
	return;
}

/*
 * ---------------------------------------------------------------------------
 * Product and orders
 * ---------------------------------------------------------------------------
 */

$products   = get_posts(
	[
		'post_type'   => 'product',
		'numberposts' => 1,
		'fields'      => 'ids',
	]
);
$product_id = (int) ( $products[0] ?? 0 );

if ( 0 === $product_id ) {
	$product = new WC_Product_Simple();
	$product->set_name( 'Testovací produkt' );
	$product->set_regular_price( '499' );
	$product->set_sku( 'BRACE-TEST-1' );
	$product->set_catalog_visibility( 'visible' );
	$product->set_status( 'publish' );
	$product_id = (int) $product->save();
	WP_CLI::log( 'Product created: #' . $product_id );
}

$existing_orders = wc_get_orders(
	[
		'limit'  => -1,
		'return' => 'ids',
	]
);

if ( count( $existing_orders ) >= TARGET_ORDERS ) {
	WP_CLI::success( sprintf( 'Done. %d orders already present, none added.', count( $existing_orders ) ) );
	return;
}

// Guests: no user account, so the anonymizer keys them off the order id.
$guests = [
	[ 'Hana', 'Šimková', 'hana.simkova@seznam.cz', 'Úvoz 1129/48', 'Brno', '60200', '606 771 320' ],
	[ 'Pavel', 'Řezníček', 'pavel.reznicek@gmail.com', 'Terezínská 88/5', 'Litoměřice', '41201', '773 205 649' ],
	[ 'Ivana', 'Marešová', 'ivana.maresova@email.cz', 'Sadová 704/33', 'Cheb', '35002', '702 916 384' ],
	[ 'Josef', 'Bartoš', 'josef.bartos@volny.cz', 'Polní 1450/12', 'Kolín', '28002', '605 038 271' ],
];

$statuses = [ 'completed', 'completed', 'processing', 'on-hold', 'completed', 'processing', 'completed', 'cancelled', 'processing', 'completed' ];
$made     = 0;
$guest_at = 6;

for ( $i = count( $existing_orders ); $i < TARGET_ORDERS; $i++ ) {
	$is_guest = $i >= $guest_at;

	if ( $is_guest ) {
		[ $first, $last, $email, $street, $city, $postcode, $phone ] = $guests[ ( $i - $guest_at ) % count( $guests ) ];
		$customer_id = 0;
	} else {
		[ $first, $last, $email, $street, $city, $postcode, $phone ] = $people[ $i ];
		$customer_id = $user_ids[ $i ] ?? 0;
	}

	$order = wc_create_order( [ 'customer_id' => $customer_id ] );
	$order->add_product( wc_get_product( $product_id ), 1 + ( $i % 3 ) );

	$address = [
		'first_name' => $first,
		'last_name'  => $last,
		'company'    => 0 === $i % 4 ? $last . ' s.r.o.' : '',
		'address_1'  => $street,
		'city'       => $city,
		'postcode'   => $postcode,
		'country'    => 'CZ',
		'email'      => $email,
		'phone'      => $phone,
	];

	$order->set_address( $address, 'billing' );
	unset( $address['email'] );
	$order->set_address( $address, 'shipping' );

	$order->set_customer_ip_address( '89.24.' . ( 100 + $i ) . '.' . ( 3 + $i ) );
	$order->set_customer_user_agent( 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/126.0' );
	$order->set_transaction_id( 'ch_3P' . strtoupper( substr( md5( (string) $i ), 0, 14 ) ) );

	if ( 0 === $i % 3 ) {
		// Free text from the customer. Kept on purpose by owner decision
		// (spec section 2.4) — after a run this is exactly where residual
		// personal data is expected to still be readable.
		$order->set_customer_note( sprintf( 'Prosím zavolejte před doručením na %s, jsem %s %s.', $phone, $first, $last ) );
	}

	$order->calculate_totals();
	$order->set_status( $statuses[ $i % count( $statuses ) ] );
	$order->save();

	$order->add_order_note( sprintf( 'Zákazník %s %s volal ohledně doručení, kontakt %s.', $first, $last, $phone ) );

	++$made;
}

WP_CLI::log( sprintf( 'Orders: %d created.', $made ) );
WP_CLI::log( 'HPOS (custom order tables): ' . ( 'yes' === get_option( 'woocommerce_custom_orders_table_enabled' ) ? 'enabled' : 'disabled — orders live in wp_posts/wp_postmeta' ) );

WP_CLI::success( 'Done. Now run: wp brace staging-anonymize dry-run' );
