<?php
/**
 * Seeds neutral sample data for WordPress.org screenshots (run with wp eval-file).
 */
use FormCaptain\Plugin;
use FormCaptain\Service\FormService;
use FormCaptain\Service\SubmissionService;
use FormCaptain\Service\EntryService;
use FormCaptain\Repository\FormRepository;

wp_set_current_user( 1 );
update_option( 'blogname', 'Example Studio' );
update_option( 'blogdescription', 'Design and print workshop' );
switch_theme( 'twentytwentyfive' );

$p     = Plugin::instance();
$forms = $p->get( FormService::class );
$repo  = $p->get( FormRepository::class );

$contact = $forms->create_from_template( 'contact' );
$quote   = $forms->create_from_template( 'quote' );
$support = $forms->create_from_template( 'support' );
foreach ( array( $contact, $quote, $support ) as $id ) {
	if ( is_wp_error( $id ) ) { fwrite( STDERR, $id->get_error_message() ); exit( 1 ); }
}
$forms->publish( $contact );
$forms->publish( $quote );

$page = wp_insert_post(
	array(
		'post_type'    => 'page',
		'post_status'  => 'publish',
		'post_title'   => 'Contact',
		'post_content' => "<!-- wp:paragraph -->\n<p>Questions about a project? Send us a message and we will reply within two working days.</p>\n<!-- /wp:paragraph -->\n\n<!-- wp:form-captain/form {\"formId\":\"" . $contact . "\"} /-->",
	)
);

$people = array(
	array( 'Alex Morgan', 'alex.morgan@example.com', 'Poster series for a café', 'Hello, we are opening a small café in spring and would like a series of three posters. Could you send us an estimate?' ),
	array( 'Sam Rivera', 'sam.rivera@example.com', 'Business cards', 'We need 500 business cards on recycled paper. What is your lead time?' ),
	array( 'Jordan Lee', 'jordan.lee@example.com', 'Workshop dates', 'Are there still places in the letterpress workshop in March?' ),
	array( 'Taylor Kim', 'taylor.kim@example.com', 'Invoice copy', 'Could you send me a copy of my last invoice?' ),
	array( 'Casey Brooks', 'casey.brooks@example.com', 'Wedding invitations', 'We are looking for hand-printed wedding invitations for about 80 guests.' ),
	array( 'Robin Patel', 'robin.patel@example.com', 'Logo refresh', 'Our association needs a refreshed logo and a simple letterhead.' ),
);
$sub   = $p->get( SubmissionService::class );
$cform = $repo->find( $contact );
$ids   = array();
foreach ( $people as $i => $row ) {
	$res = $sub->submit(
		$cform,
		array(
			'f_ctname01' => $row[0],
			'f_ctmail01' => $row[1],
			'f_ctsubj01' => $row[2],
			'f_ctmsg001' => $row[3],
			'f_ctcons01' => '1',
		),
		array( 'trusted' => true, 'idempotency_key' => 'shot-' . $i, 'source_post_id' => $page )
	);
	if ( empty( $res['success'] ) ) { fwrite( STDERR, wp_json_encode( $res ) ); exit( 1 ); }
	$ids[] = (int) $res['entry_id'];
}
$entries = $p->get( EntryService::class );
$entries->mark_read( $ids[1] );
$entries->mark_read( $ids[2] );
$entries->transition( $ids[3], 'archived', 'admin' );
$entries->add_note( $ids[0], 'Estimate sent on Monday. Follow up next week.' );
echo wp_json_encode( array( 'contact' => $contact, 'quote' => $quote, 'support' => $support, 'page' => get_permalink( $page ), 'entries' => $ids ) ), "\n";
