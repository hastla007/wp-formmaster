<?php
/**
 * Values of a submission are written with one query per 100 values (SET-FC-P6), with the same
 * checks as before (field id length, valid UTF-8).
 *
 * @package FormCaptain
 */

declare(strict_types=1);

namespace FormCaptain\Tests\Integration\Entries;

use FormCaptain\Repository\EntryRepository;
use FormCaptain\Tests\Integration\TestCase;

final class EntryValuesInsertTest extends TestCase {

	private function repo(): EntryRepository {
		return $this->plugin()->get( EntryRepository::class );
	}

	public function test_values_are_written_in_one_query_and_read_back(): void {
		global $wpdb;
		$values = array(
			'f_name01' => 'Änne 😀',
			'f_tags01' => array( 'a', 'b' ),
			'f_msg001' => "Line 1\nLine 2 'quoted' %s",
		);
		$this->repo()->insert_values( 4710, array( 'f_warm001' => 'é' ) ); // $wpdb reads the column charsets once.
		$before = $wpdb->num_queries;
		$this->assertTrue( $this->repo()->insert_values( 4711, $values ) );
		$this->assertSame( 1, $wpdb->num_queries - $before );
		$read = $this->repo()->values_for_many( array( 4711 ) )[4711];
		ksort( $read );
		ksort( $values );
		$this->assertSame( $values, $read );

		$many = array();
		for ( $i = 0; $i < 150; $i++ ) {
			$many[ sprintf( 'f_%07d', $i ) ] = (string) $i;
		}
		$before = $wpdb->num_queries;
		$this->assertTrue( $this->repo()->insert_values( 4712, $many ) );
		$this->assertSame( 2, $wpdb->num_queries - $before, '100 values per query' );
		$this->assertCount( 150, $this->repo()->values_for_many( array( 4712 ) )[4712] );
	}

	public function test_invalid_values_write_nothing(): void {
		$this->assertFalse( $this->repo()->insert_values( 4713, array( 'f_ok00001' => 'x', 'f_bad0001' => "\xC3\x28" ) ) );
		$this->assertFalse( $this->repo()->insert_values( 4713, array( str_repeat( 'f', 41 ) => 'x' ) ) );
		$this->assertSame( array(), $this->repo()->values_for_many( array( 4713 ) )[4713] ?? array() );
	}
}
