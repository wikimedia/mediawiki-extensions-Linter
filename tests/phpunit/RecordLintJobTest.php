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

use ContentHandler;
use MediaWiki\Linter\Database;
use MediaWiki\Linter\LintError;
use MediaWiki\Linter\RecordLintJob;
use Title;
use User;
use WikiPage;

/**
 * @group Database
 * @covers MediaWiki\Linter\RecordLintJob
 */
class RecordLintJobTest extends \MediaWikiTestCase {
	/**
	 * @return array
	 */
	private function createTitleAndPage() {
		$titleText = 'TestPage';
		$userName = 'LinterUser';
		$baseText = 'wikitext test content';

		$ns = $this->getDefaultWikitextNS();
		$title = Title::newFromText( $titleText, $ns );
		$user = User::newFromName( $userName );
		if ( $user->getId() === 0 ) {
			$user->addToDatabase();
		}
		$page = WikiPage::factory( $title );

		$content = ContentHandler::makeContent( $baseText, $title );
		$page->doUserEditContent( $content, $user, "base text for test" );

		return [ 'title' => $title,
			'pageID' => $page->getRevisionRecord()->getPageId(),
			'revID' => $page->getRevisionRecord()->getID()
		];
	}

	public function testRun() {
		$error = [
			'type' => 'fostered',
			'location' => [ 0, 10 ],
			'params' => [],
			'dbid' => null,
		];
		$titleAndPage = $this->createTitleAndPage();
		$job = new RecordLintJob( $titleAndPage['title'], [
			'errors' => [ $error ],
			'revision' => $titleAndPage['revID']
		] );
		$this->assertTrue( $job->run() );
		/** @var LintError[] $errorsFromDb */
		$errorsFromDb = array_values( ( new Database( $titleAndPage['pageID'] ) )->getForPage() );
		$this->assertCount( 1, $errorsFromDb );
		$this->assertInstanceOf( LintError::class, $errorsFromDb[0] );
		$this->assertEquals( $error['type'], $errorsFromDb[0]->category );
		$this->assertEquals( $error['location'], $errorsFromDb[0]->location );
		$this->assertEquals( $error['params'], $errorsFromDb[0]->params );
	}
}
