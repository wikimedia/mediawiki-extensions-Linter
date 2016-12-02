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
use MediaWiki\Linter\RecordLintJob;
use Title;

/**
 * @group Database
 * @covers MediaWiki\Linter\RecordLintJob
 */
class RecordLintJobTest extends \MediaWikiTestCase {

	/**
	 * @param int $articleId
	 * @param int $revId
	 * @return Title
	 */
	private function getMockTitle( $articleId = 1, $revId = 2 ) {
		$mock = $this->getMock( Title::class, [ 'getLatestRevID', 'getArticleID' ] );
		$mock->expects( $this->any() )->method( 'getLatestRevID' )->willReturn( $revId );
		$mock->expects( $this->any() )->method( 'getArticleID' )->willReturn( $articleId );

		return $mock;
	}

	public function testRun() {
		$error = [
			'type' => 'fostered',
			'location' => [ 0, 10 ],
			'params' => [],
		];
		$job = new RecordLintJob( $this->getMockTitle(), [
			'errors' => [ $error ],
			'revision' => 2,
		] );
		$this->assertTrue( $job->run() );
		/** @var LintError[] $errorsFromDb */
		$errorsFromDb = array_values( ( new Database( 1 ) )->getForPage() );
		$this->assertCount( 1, $errorsFromDb );
		$this->assertInstanceOf( LintError::class, $errorsFromDb[0] );
		$this->assertEquals( $error['type'], $errorsFromDb[0]->category );
		$this->assertEquals( $error['location'], $errorsFromDb[0]->location );
		$this->assertEquals( $error['params'], $errorsFromDb[0]->params );
	}
}
