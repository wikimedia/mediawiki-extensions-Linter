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
use MediaWikiIntegrationTestCase;
use stdClass;
use Title;
use User;
use Wikimedia\Rdbms\SelectQueryBuilder;

/**
 * @group Database
 * @covers \MediaWiki\Linter\RecordLintJob
 */
class RecordLintJobTest extends MediaWikiIntegrationTestCase {
	/**
	 * @param string $titleText
	 * @param int|null $ns
	 * @return array
	 */
	private function createTitleAndPage( string $titleText, ?int $ns = 0 ) {
		$userName = 'LinterUser';
		$baseText = 'wikitext test content';

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

	/**
	 * Get just the linter_namespace field value from the linter table for a page
	 *
	 * @param int $pageId
	 * @return mixed
	 */
	private function getNamespaceForPage( int $pageId ) {
		$queryLinterPageNamespace = new SelectQueryBuilder( $this->db );
		$queryLinterPageNamespace
			->select( 'linter_namespace' )
			->table( 'linter' )
			->where( [ 'linter_page' => $pageId ] )
			->caller( __METHOD__ );
		return $queryLinterPageNamespace->fetchField();
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
			'params' => [
				"name" => "center",
				"templateInfo" => [ "name" => "Template:Echo" ]
			],
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

	/**
	 * @param string $titleText
	 * @param int $namespace
	 * @return array
	 */
	private function createTitleAndPageAndRunJob( string $titleText, int $namespace ): array {
		$titleAndPage = $this->createTitleAndPage( $titleText, $namespace );
		$error = [
			'type' => 'fostered',
			'location' => [ 0, 10 ],
			'params' => [],
			'dbid' => null,
		];
		$job = new RecordLintJob( $titleAndPage[ 'title' ], [
			'errors' => [ $error ],
			'revision' => $titleAndPage[ 'revID' ]
		] );
		$this->assertTrue( $job->run() );
		return $titleAndPage;
	}

	/**
	 * @param array $namespaceIds
	 * @param array $writeEnables
	 * @return array
	 */
	private function createPagesWithNamespace( array $namespaceIds, array $writeEnables ): array {
		$titleAndPages = [];
		foreach ( $namespaceIds as $index => $namespaceId ) {
			// enable/disable writing the namespace field in the linter table during page creation
			$this->overrideConfigValue( 'LinterWriteNamespaceColumnStage', $writeEnables[ $index ] );
			$titleAndPages[] = $this->createTitleAndPageAndRunJob(
				'TestPageNamespace' . $index,
				intval( $namespaceId ) );
		}
		return $titleAndPages;
	}

	/**
	 * @param array $pages
	 * @param array $namespaceIds
	 * @return void
	 */
	private function checkPagesNamespace( array $pages, array $namespaceIds ) {
		foreach ( $pages as $index => $page ) {
			$pageId = $page[ 'pageID' ];
			$namespace = $this->getNamespaceForPage( $pageId );
			$namespaceId = $namespaceIds[ $index ];
			$this->assertSame( "$namespaceId", $namespace );
		}
	}

	public function testMigrateNamespace() {
		$this->overrideConfigValue( 'LinterMigrateNamespaceStage', true );

		// Create groups of records that do not need migrating to ensure batching works properly
		$namespaceIds = [ '0', '1', '2', '3', '4', '5', '4', '3', '2', '1', '0', '1', '2' ];
		$writeEnables = [ false, true, true, true, false, false, true, true, false, false, false, true, false ];

		$titleAndPages = $this->createPagesWithNamespace( $namespaceIds, $writeEnables );

		// Verify the create page function did not populate the linter_namespace field for TestPageNamespace0
		$pageId = $titleAndPages[ 0 ][ 'pageID' ];
		$namespace = $this->getNamespaceForPage( $pageId );
		$this->assertNull( $namespace );

		// migrate unpopulated namespace_id(s) from the page table to linter table
		Database::migrateNamespace( 2, 3, 0, false );

		// Verify all linter records now have proper namespace IDs in the linter_namespace field
		$this->checkPagesNamespace( $titleAndPages, $namespaceIds );
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
