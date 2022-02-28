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
use MediaWiki\Linter\CategoryManager;
use MediaWiki\Linter\Database;
use MediaWiki\Linter\LintError;
use MediaWiki\Linter\RecordLintJob;
use MediaWiki\Linter\SpecialLintErrors;
use Title;
use User;

/**
 * @covers MediaWiki\Linter\SpecialLintErrors
 */
class SpecialLintErrorsTest extends \SpecialPageTestBase {

	protected function newSpecialPage() {
		return new SpecialLintErrors();
	}

	public function testExecute() {
		$category = ( new CategoryManager() )->getVisibleCategories()[0];

		// Basic
		$html = $this->executeSpecialPage( '', null, 'qqx' )[0];
		$this->assertStringContainsString( '(linterrors-summary)', $html );
		$this->assertStringContainsString( "(linter-category-$category)", $html );

		$this->assertStringContainsString(
			"(linter-category-$category-desc)",
			$this->executeSpecialPage( $category, null, 'qqx' )[0]
		);
	}

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
		$page = $this->getServiceContainer()->getWikiPageFactory()->newFromTitle( $title );

		$content = ContentHandler::makeContent( $baseText, $title );
		$page->doUserEditContent( $content, $user, "base text for test" );

		return [
			'title' => $title,
			'pageID' => $page->getRevisionRecord()->getPageId(),
			'revID' => $page->getRevisionRecord()->getID(),
			'page' => $page,
			'user' => $user
		];
	}

	public function testContentModelChange() {
		$error = [
			'type' => 'obsolete-tag',
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

		$db = new Database( $titleAndPage['pageID'] );

		/** @var LintError[] $errorsFromDb */
		$errorsFromDb = array_values( $db->getForPage() );
		$this->assertCount( 1, $errorsFromDb );

		$cssText = 'css content model change test page content';
		$content = ContentHandler::makeContent(
			$cssText,
			$titleAndPage['title'],
			'css'
		);
		$page = $titleAndPage['page'];
		$page->doUserEditContent(
			$content,
			$titleAndPage['user'],
			"update with css content model to trigger onRevisionFromEditComplete hook"
		);

		$errorsFromDb = array_values( $db->getForPage() );
		$this->assertCount( 0, $errorsFromDb );
	}

	public function testContentModelChangeWithBlankPage() {
		$error = [
			'type' => 'obsolete-tag',
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

		$db = new Database( $titleAndPage['pageID'] );

		/** @var LintError[] $errorsFromDb */
		$errorsFromDb = array_values( $db->getForPage() );
		$this->assertCount( 1, $errorsFromDb );

		// This test recreates the doUserEditContent bug mentioned in T280193 of not
		// calling the onRevisionFromEditComplete hook with the "mw-contentmodelchange"
		// tag set when the new content text is literally blank.
		$blankText = '';
		$content = ContentHandler::makeContent(
			$blankText,
			$titleAndPage['title'],
			'text'
		);
		$page = $titleAndPage['page'];
		$page->doUserEditContent(
			$content,
			$titleAndPage['user'],
			"update with blank text content model to trigger onRevisionFromEditComplete hook"
		);

		$errorsFromDb = array_values( $db->getForPage() );
		$this->assertCount( 0, $errorsFromDb );
	}
}
