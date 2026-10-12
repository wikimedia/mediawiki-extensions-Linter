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

use MediaWiki\Content\JavaScriptContent;
use MediaWiki\Content\JavaScriptContentHandler;
use MediaWiki\Content\WikitextContent;
use MediaWiki\Content\WikitextContentHandler;
use MediaWiki\JobQueue\Jobs\RefreshLinksJob;
use MediaWiki\Linter\LintUpdate;
use MediaWiki\MainConfigNames;
use MediaWiki\Page\WikiPage;
use MediaWiki\Parser\ParserOutput;
use MediaWiki\Revision\MutableRevisionRecord;
use MediaWiki\Revision\RenderedRevision;
use MediaWiki\Revision\RevisionRecord;
use MediaWikiIntegrationTestCase;
use Wikimedia\Stats\StatsFactory;

/**
 * @group Database
 * @covers \MediaWiki\Linter\LintUpdate
 */
class LintUpdateTest extends MediaWikiIntegrationTestCase {

	protected function setUp(): void {
		parent::setUp();

		$this->overrideConfigValues( [
			MainConfigNames::ParsoidSettings => [
				'linting' => true
			],
			'LinterParseOnDerivedDataUpdate' => true,
			// Ensure that parser cache contents don't
			// affect tests.
			'ParserCacheType' => CACHE_NONE,
		] );
	}

	/**
	 * Assert that we trigger a parse, and that parse triggers onParserLogLinterData,
	 * and in turn triggers the ParserLogLinterData hook via Parsoid.
	 */
	public function testUpdate() {
		// NOTE: This performs an edit, so do it before installing the temp hook below!
		$rrev = $this->newRenderedRevision();

		$hookCalled = false;
		$this->setTemporaryHook( 'ParserLogLinterData', static function () use ( &$hookCalled ) {
			$hookCalled = true;
		}, false );

		$update = $this->newLintUpdate( $rrev );
		$update->doUpdate();

		$this->assertTrue( $hookCalled );
	}

	/**
	 * Assert that we don't parse if the content model is not supported.
	 */
	public function testSkipModel() {
		$contentHandler = $this->createNoOpMock(
			JavaScriptContentHandler::class,
			[ 'getParserOutput' ]
		);
		$contentHandler->expects( $this->never() )->method( 'getParserOutput' );
		$contentHandlers = $this->getConfVar( MainConfigNames::ContentHandlers );
		$this->overrideConfigValue( MainConfigNames::ContentHandlers, [
			CONTENT_MODEL_JAVASCRIPT => [
				'factory' => static fn () => $contentHandler,
			],
		] + $contentHandlers );

		$page = $this->getExistingTestPage();
		$rev = MutableRevisionRecord::newFromContent( $page, new JavascriptContent( '{}' ) );
		// Clear the local cache in the ParserOutputAccess
		$this->resetServices();

		$update = $this->newLintUpdate( $this->newRenderedRevision( $page, $rev ) );
		$update->doUpdate();
	}

	/**
	 * Assert that we don't parse if the given revision is no longer the
	 * latest revision.
	 */
	public function testSkipOld() {
		// This may use the "real" wikitext content handler
		$page = $this->getExistingTestPage();
		$rev = MutableRevisionRecord::newFromContent( $page, new WikitextContent( 'bla bla' ) )
			 // make it not the current revision
			->setId( $page->getLatest() - 1 );
		$newRev = $this->newRenderedRevision( $page, $rev );

		// Ok, now set up a mock content handler for the remainder
		$contentHandler = $this->createNoOpMock(
			WikitextContentHandler::class,
			[ 'getParserOutput' ]
		);
		$contentHandler->expects( $this->never() )->method( 'getParserOutput' );
		$contentHandlers = $this->getConfVar( MainConfigNames::ContentHandlers );
		$this->overrideConfigValue( MainConfigNames::ContentHandlers, [
			CONTENT_MODEL_WIKITEXT => [
				'factory' => static fn () => $contentHandler,
			],
		] + $contentHandlers );

		// Clear the local cache in the ParserOutputAccess
		$this->resetServices();

		$update = $this->newLintUpdate( $newRev );
		$update->doUpdate();
	}

	/**
	 * Assert that an edit triggers the ParserLogLinterData hook via Parsoid
	 * exactly once. With the legacy parser, the LintUpdate does the Parsoid
	 * parse. With Parsoid, the canonical parse does it, and the LintUpdate
	 * does not parse again.
	 *
	 * @covers \MediaWiki\Linter\Hooks::onRevisionDataUpdates
	 * @dataProvider provideUseParsoidParser
	 */
	public function testPageEditIntegration( bool $useParsoidParser ) {
		$this->overrideConfigValue( MainConfigNames::UseParsoidParser, $useParsoidParser );
		// Clear the local cache in the ParserOutputAccess
		$this->resetServices();

		$hookCalled = 0;
		$this->setTemporaryHook( 'ParserLogLinterData', static function () use ( &$hookCalled ) {
			$hookCalled++;
		}, false );

		$this->editPage( 'JustSomePage', new WikitextContent( 'hello world' ) );

		$this->assertSame( 1, $hookCalled );
	}

	/**
	 * Assert that an edit sends lints when the output was rendered before
	 * the save. Parsoid does not send the lints of that output, because the
	 * revision ID is not known. Core reuses the output after the save.
	 * TemplateData and the edit stash render before the save.
	 *
	 * @covers \MediaWiki\Linter\Hooks::onRevisionDataUpdates
	 * @dataProvider provideUseParsoidParser
	 */
	public function testPageEditIntegrationRenderBeforeSave( bool $useParsoidParser ) {
		$this->overrideConfigValue( MainConfigNames::UseParsoidParser, $useParsoidParser );
		// Clear the local cache in the ParserOutputAccess
		$this->resetServices();

		$this->setTemporaryHook( 'MultiContentSave', static function ( RenderedRevision $rrev ) {
			$rrev->getRevisionParserOutput( [ 'generate-html' => false ] );
		}, false );
		$linted = [];
		$this->setTemporaryHook( 'ParserLogLinterData', static function (
			string $title, int $revId, array $data
		) use ( &$linted ) {
			$linted[] = $data;
		}, false );

		$this->editPage( 'JustSomePage', new WikitextContent( '<b>hello world' ) );

		$this->assertCount( 1, $linted );
		$this->assertContains( 'missing-end-tag', array_column( $linted[0], 'type' ) );
	}

	/**
	 * Assert that a RefreshLinksJob does not parse again for the lints
	 * after an edit that reused output from before the save. The
	 * LintUpdate of the edit puts its output in the parser cache, and
	 * the job uses that output.
	 *
	 * @covers \MediaWiki\Linter\Hooks::onRevisionDataUpdates
	 */
	public function testRefreshLinksJobAfterRenderBeforeSave() {
		$this->overrideConfigValues( [
			MainConfigNames::UseParsoidParser => true,
			MainConfigNames::UseParsoidLinksUpdate => true,
			MainConfigNames::ParserCacheType => CACHE_HASH,
		] );
		$page = $this->getExistingTestPage();
		// Clear the local cache in the ParserOutputAccess
		$this->resetServices();

		$this->setTemporaryHook( 'MultiContentSave', static function ( RenderedRevision $rrev ) {
			$rrev->getRevisionParserOutput( [ 'generate-html' => false ] );
		}, false );
		$hookCalled = 0;
		$this->setTemporaryHook( 'ParserLogLinterData', static function () use ( &$hookCalled ) {
			$hookCalled++;
		}, false );

		$rootJobTimestamp = wfTimestampNow();
		$this->editPage( $page, new WikitextContent( '<b>hello world' ) );
		$this->assertSame( 1, $hookCalled, 'edit' );

		// The job usually runs in a different process. Thus it gets
		// the output from the parser cache, not from the local cache.
		$this->getServiceContainer()->getParserOutputAccess()->clearLocalCache();
		$hookCalled = 0;
		$job = new RefreshLinksJob( $page, [
			'rootJobTimestamp' => $rootJobTimestamp,
			'isOpportunistic' => true,
		] );
		$job->run();
		$this->assertSame( 0, $hookCalled, 'RefreshLinksJob' );
	}

	public static function provideUseParsoidParser(): array {
		return [
			'Legacy parser' => [ false ],
			'Parsoid parser' => [ true ],
		];
	}

	/**
	 * Assert that a LintUpdate is triggered from RefreshLinksJob through the
	 * RevisionDataUpdates hook, and in turn triggers the ParserLogLinterData
	 * hook via Parsoid.
	 *
	 * @covers \MediaWiki\Linter\Hooks::onRevisionDataUpdates
	 * @dataProvider provideUseParsoidLinksUpdate
	 */
	public function testRefreshLinksJobIntegration( bool $useParsoidLinksUpdate ) {
		$this->overrideConfigValue( MainConfigNames::UseParsoidLinksUpdate, $useParsoidLinksUpdate );
		// NOTE: This performs an edit, so do it before installing the temp hook below!
		$page = $this->getExistingTestPage();
		// Clear the local cache in the ParserOutputAccess
		$this->resetServices();

		$hookCalled = 0;
		$this->setTemporaryHook( 'ParserLogLinterData', static function () use ( &$hookCalled ) {
			$hookCalled++;
		}, false );

		$job = new RefreshLinksJob( $page, [] );
		$job->run();
		$this->assertSame( 1, $hookCalled );
	}

	public static function provideUseParsoidLinksUpdate(): array {
		return [
			'Parsoid links update' => [ true ],
			'Legacy links update' => [ false ],
		];
	}

	private function newRenderedRevision( ?WikiPage $page = null, ?RevisionRecord $rev = null ) {
		$page = $this->getExistingTestPage();
		$title = $page->getTitle();

		$rev ??= $this->getServiceContainer()->getRevisionLookup()->getRevisionByTitle( $title );
		$pOpt = $page->makeParserOptions( 'canonical' );

		$parserOutput = new ParserOutput( 'testing' );
		// Since ParserOutput is constructed with a legacy string
		$pOpt->setUseParsoid( false );

		$rrev = new RenderedRevision(
			$rev,
			$pOpt,
			$this->getServiceContainer()->getContentRenderer(),
			static function () {
			}
		);

		$rrev->setRevisionParserOutput( $parserOutput );
		// Clear the local cache in the ParserOutputAccess
		$this->resetServices();

		return $rrev;
	}

	private function newLintUpdate( RenderedRevision $renderedRevision ) {
		$wikiPageFactory = $this->getServiceContainer()->getWikiPageFactory();
		$parserOutputAccess = $this->getServiceContainer()->getParserOutputAccess();
		return new LintUpdate(
			StatsFactory::newNull(),
			$wikiPageFactory,
			$parserOutputAccess,
			$renderedRevision
		);
	}

}
