<?php
/**
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301, USA.
 * http://www.gnu.org/copyleft/gpl.html
 *
 * @file
 */

namespace MediaWiki\Linter\Test;

use MediaWiki\Linter\Database;
use MediaWiki\Linter\LintError;
use MediaWikiTestCase;

/**
 * @group Database
 * @covers MediaWiki\Linter\Database
 */
class DatabaseTest extends MediaWikiTestCase {

	public function testConstructor() {
		$this->assertInstanceOf( Database::class, new Database( 5 ) );
	}

	private function getDummyLintErrors() {
		return [
			new LintError(
				'fostered', [ 0, 10 ], []
			),
			new LintError(
				'obsolete-tag', [ 15, 20 ], [ 'name' => 'big' ]
			),
		];
	}

	private function assertSetForPageResult( $result, $deleted, $added ) {
		$this->assertArrayHasKey( 'deleted', $result );
		$this->assertEquals( $deleted, $result['deleted'] );
		$this->assertArrayHasKey( 'added', $result );
		$this->assertEquals( $added, $result['added'] );
	}

	private function assertLintErrorsEqual( $expected, $actual ) {
		$expectedIds = array_map( function( LintError $error ) {
			return $error->id();
		}, $expected );
		$actualIds = array_map( function( LintError $error ) {
			return $error->id();
		}, $actual );
		$this->assertArrayEquals( $expectedIds, $actualIds );
	}

	public function testSetForPage() {
		$lintDb = new Database( 5 );
		$dummyErrors = $this->getDummyLintErrors();
		$result = $lintDb->setForPage( $dummyErrors );
		$this->assertSetForPageResult( $result, 0, 2 );
		$this->assertLintErrorsEqual( $dummyErrors, $lintDb->getForPage() );

		// Should delete the second error
		$result2 = $lintDb->setForPage( [ $dummyErrors[0] ] );
		$this->assertSetForPageResult( $result2, 1, 0 );
		$this->assertLintErrorsEqual( [ $dummyErrors[0] ], $lintDb->getForPage() );

		// Insert the second error, delete the first
		$result3 = $lintDb->setForPage( [ $dummyErrors[1] ] );
		$this->assertSetForPageResult( $result3, 1, 1 );
		$this->assertLintErrorsEqual( [ $dummyErrors[1] ], $lintDb->getForPage() );

		// Delete the second (only) error
		$result4 = $lintDb->setForPage( [] );
		$this->assertSetForPageResult( $result4, 1, 0 );
		$this->assertLintErrorsEqual( [], $lintDb->getForPage() );
	}

}
