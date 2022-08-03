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
use stdClass;
use Title;
use User;

/**
 * @group Database
 * @covers MediaWiki\Linter\RecordLintJob
 */
class RecordLintJobTest extends \MediaWikiIntegrationTestCase {
	/**
	 * @param string $titleText
	 * @return array
	 */
	private function createTitleAndPage( string $titleText ) {
		$userName = 'LinterUser';
		$baseText = 'wikitext test content';

		$ns = $this->getDefaultWikitextNS();
		$title = Title::newFromText( $titleText, $ns );
		$user = User::newFromName( $userName );
		if ( $user->getId() === 0 ) {
			$user->addToDatabase();
		}
		$page = $this->getServiceContainer()->getWikiPageFactory()->newFromTitle( $title );

		$content = ContentHandler::makeContent( $baseText, $title );
		$page->doUserEditContent( $content, $user, "base text for test" );

		return [ 'title' => $title,
			'pageID' => $page->getRevisionRecord()->getPageId(),
			'revID' => $page->getRevisionRecord()->getID()
		];
	}

	/**
	 * Get just the lint error linter_tag field value for a page
	 *
	 * @param int $pageId
	 * @return stdClass|bool
	 */
	private static function getTagForPage( int $pageId ) {
		return Database::getDBConnectionRef( DB_REPLICA )->selectRow(
			'linter',
			[
				'linter_tag'
			],
			[ 'linter_page' => $pageId ],
			__METHOD__
		);
	}

	/**
	 * Get just the lint error linter_template field value for a page
	 *
	 * @param int $pageId
	 * @return stdClass|bool
	 */
	private static function getTemplateForPage( int $pageId ) {
		return Database::getDBConnectionRef( DB_REPLICA )->selectRow(
			'linter',
			[
				'linter_template'
			],
			[ 'linter_page' => $pageId ],
			__METHOD__
		);
	}

	public function testRun() {
		$error = [
			'type' => 'fostered',
			'location' => [ 0, 10 ],
			'params' => [],
			'dbid' => null,
		];
		$titleAndPage = $this->createTitleAndPage( 'TestPage' );
		$job = new RecordLintJob( $titleAndPage[ 'title' ], [
			'errors' => [ $error ],
			'revision' => $titleAndPage[ 'revID' ]
		] );
		$this->assertTrue( $job->run() );

		$db = new Database( $titleAndPage[ 'pageID' ] );
		/** @var LintError[] $errorsFromDb */
		$errorsFromDb = array_values( $db->getForPage() );
		$this->assertCount( 1, $errorsFromDb );
		$this->assertInstanceOf( LintError::class, $errorsFromDb[ 0 ] );
		$this->assertEquals( $error[ 'type' ], $errorsFromDb[ 0 ]->category );
		$this->assertEquals( $error[ 'location' ], $errorsFromDb[ 0 ]->location );
		$this->assertEquals( $error[ 'params' ], $errorsFromDb[ 0 ]->params );
	}

	public function testWriteTagAndTemplate() {
		$this->overrideConfigValue( 'LinterWriteTagAndTemplateColumnsStage', true );

		$error = [
			'type' => 'obsolete-tag',
			'location' => [ 0, 10 ],
			'params' => [ "name" => "center",
				"templateInfo" => [ "name" => "Template:Echo" ] ],
			'dbid' => null,
		];
		$titleAndPage = $this->createTitleAndPage( 'TestPage2' );
		$job = new RecordLintJob( $titleAndPage[ 'title' ], [
			'errors' => [ $error ],
			'revision' => $titleAndPage[ 'revID' ]
		] );
		$this->assertTrue( $job->run() );

		$pageId = $titleAndPage[ 'pageID' ];
		$db = new Database( $pageId );
		$errorsFromDb = array_values( $db->getForPage() );
		$this->assertCount( 1, $errorsFromDb );
		$this->assertInstanceOf( LintError::class, $errorsFromDb[0] );
		$this->assertEquals( $error[ 'type' ], $errorsFromDb[0]->category );
		$this->assertEquals( $error[ 'location' ], $errorsFromDb[0]->location );
		$this->assertEquals( $error[ 'params' ], $errorsFromDb[0]->params );
		$tag = self::getTagForPage( $pageId )->linter_tag ?? '';
		$this->assertEquals( $error[ 'params' ][ 'name' ], $tag );
		$template = self::getTemplateForPage( $pageId )->linter_template ?? '';
		$this->assertEquals( $error[ 'params' ][ 'templateInfo' ][ 'name' ], $template );
	}

	public function testDropInlineMediaCaptionLints() {
		$error = [
			'type' => 'inline-media-caption',
			'location' => [ 0, 10 ],
			'params' => [],
			'dbid' => null,
		];
		$titleAndPage = $this->createTitleAndPage( 'TestPage3' );
		$job = new RecordLintJob( $titleAndPage['title'], [
			'errors' => [ $error ],
			'revision' => $titleAndPage['revID']
		] );
		$this->assertTrue( $job->run() );
		/** @var LintError[] $errorsFromDb */
		$errorsFromDb = array_values( ( new Database( $titleAndPage['pageID'] ) )->getForPage() );
		$this->assertCount( 0, $errorsFromDb );
	}
}
