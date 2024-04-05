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

namespace MediaWiki\Linter;

use ApiQuerySiteinfo;
use Content;
use IContextSource;
use JobQueueGroup;
use MediaWiki\Api\Hook\APIQuerySiteInfoGeneralInfoHook;
use MediaWiki\Deferred\MWCallableUpdate;
use MediaWiki\Hook\BeforePageDisplayHook;
use MediaWiki\Hook\InfoActionHook;
use MediaWiki\Hook\ParserLogLinterDataHook;
use MediaWiki\Linker\LinkRenderer;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\Output\OutputPage;
use MediaWiki\Page\Hook\RevisionFromEditCompleteHook;
use MediaWiki\Page\Hook\WikiPageDeletionUpdatesHook;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\Title\Title;
use MediaWiki\User\UserIdentity;
use Skin;
use WikiPage;

class Hooks implements
	APIQuerySiteInfoGeneralInfoHook,
	BeforePageDisplayHook,
	InfoActionHook,
	ParserLogLinterDataHook,
	RevisionFromEditCompleteHook,
	WikiPageDeletionUpdatesHook
{
	private LinkRenderer $linkRenderer;
	private JobQueueGroup $jobQueueGroup;
	private CategoryManager $categoryManager;
	private TotalsLookup $totalsLookup;
	private DatabaseFactory $databaseFactory;

	/**
	 * @param LinkRenderer $linkRenderer
	 * @param JobQueueGroup $jobQueueGroup
	 * @param CategoryManager $categoryManager
	 * @param TotalsLookup $totalsLookup
	 * @param DatabaseFactory $databaseFactory
	 */
	public function __construct(
		LinkRenderer $linkRenderer,
		JobQueueGroup $jobQueueGroup,
		CategoryManager $categoryManager,
		TotalsLookup $totalsLookup,
		DatabaseFactory $databaseFactory
	) {
		$this->linkRenderer = $linkRenderer;
		$this->jobQueueGroup = $jobQueueGroup;
		$this->categoryManager = $categoryManager;
		$this->totalsLookup = $totalsLookup;
		$this->databaseFactory = $databaseFactory;
	}

	/**
	 * Hook: BeforePageDisplay
	 *
	 * If there is a lintid parameter, look up that error in the database
	 * and setup and output our client-side helpers
	 *
	 * @param OutputPage $out
	 * @param Skin $skin
	 */
	public function onBeforePageDisplay( $out, $skin ): void {
		$request = $out->getRequest();
		$lintId = $request->getInt( 'lintid' );
		if ( !$lintId ) {
			return;
		}
		$title = $out->getTitle();
		if ( !$title ) {
			return;
		}

		$database = $this->databaseFactory->newDatabase( $title->getArticleID() );
		$lintError = $database->getFromId( $lintId );
		if ( !$lintError ) {
			// Already fixed or bogus URL parameter?
			return;
		}

		$out->addJsConfigVars( [
			'wgLinterErrorCategory' => $lintError->category,
			'wgLinterErrorLocation' => $lintError->location,
		] );
		$out->addModules( 'ext.linter.edit' );
	}

	/**
	 * Hook: WikiPageDeletionUpdates
	 *
	 * Remove entries from the linter table upon page deletion
	 *
	 * @param WikiPage $wikiPage
	 * @param Content $content
	 * @param array &$updates
	 */
	public function onWikiPageDeletionUpdates( $wikiPage, $content, &$updates ) {
		$id = $wikiPage->getId();
		$updates[] = new MWCallableUpdate( function () use ( $id ) {
			$database = $this->databaseFactory->newDatabase( $id );
			$this->totalsLookup->updateStats( $database, $database->setForPage( [] ) );
		}, __METHOD__ );
	}

	/**
	 * This should match Parsoid's PageConfig::hasLintableContentModel()
	 */
	public const LINTABLE_CONTENT_MODELS = [ CONTENT_MODEL_WIKITEXT, 'proofread-page' ];

	/**
	 * Hook: RevisionFromEditComplete
	 *
	 * Remove entries from the linter table upon page content model change away from wikitext
	 *
	 * @param WikiPage $wikiPage
	 * @param RevisionRecord $newRevisionRecord
	 * @param bool|int $originalRevId
	 * @param UserIdentity $user
	 * @param string[] &$tags
	 */
	public function onRevisionFromEditComplete(
		$wikiPage, $newRevisionRecord, $originalRevId, $user, &$tags
	) {
		// This is just a stop-gap to deal with callers that aren't complying
		// with the advertised hook signature.
		if ( !is_array( $tags ) ) {
			return;
		}

		if (
			in_array( "mw-blank", $tags ) ||
			( in_array( "mw-contentmodelchange", $tags ) &&
			!in_array( $wikiPage->getContentModel(), self::LINTABLE_CONTENT_MODELS ) )
		) {
			$database = $this->databaseFactory->newDatabase( $wikiPage->getId() );
			$this->totalsLookup->updateStats( $database, $database->setForPage( [] ) );
		}
	}

	/**
	 * Hook: APIQuerySiteInfoGeneralInfo
	 *
	 * Expose categories via action=query&meta=siteinfo
	 *
	 * @param ApiQuerySiteInfo $api
	 * @param array &$data
	 */
	public function onAPIQuerySiteInfoGeneralInfo( $api, &$data ) {
		$data['linter'] = [
			'high' => $this->categoryManager->getHighPriority(),
			'medium' => $this->categoryManager->getMediumPriority(),
			'low' => $this->categoryManager->getLowPriority(),
		];
	}

	/**
	 * Hook: InfoAction
	 *
	 * Display quick summary of errors for this page on ?action=info
	 *
	 * @param IContextSource $context
	 * @param array &$pageInfo
	 */
	public function onInfoAction( $context, &$pageInfo ) {
		$title = $context->getTitle();
		$pageId = $title->getArticleID();
		if ( !$pageId ) {
			return;
		}
		$database = $this->databaseFactory->newDatabase( $pageId );
		$totals = array_filter( $database->getTotalsForPage() );
		if ( !$totals ) {
			// No errors, yay!
			return;
		}

		foreach ( $totals as $name => $count ) {
			$pageInfo['linter'][] = [
				$context->msg( "linter-category-$name" ),
				htmlspecialchars( (string)$count )
			];
		}

		$pageInfo['linter'][] = [
			'below',
			$this->linkRenderer->makeKnownLink(
				SpecialPage::getTitleFor( 'LintErrors' ),
				$context->msg( 'pageinfo-linter-moreinfo' )->text(),
				[],
				[ 'wpNamespaceRestrictions' => $title->getNamespace(),
					'titlesearch' => $title->getText(), 'exactmatch' => 1 ]
			),
		];
	}

	/**
	 * Hook: ParserLogLinterData
	 *
	 * To record a lint errors.
	 *
	 * @param string $page
	 * @param int $revision
	 * @param array[] $data
	 * @return bool
	 */
	public function onParserLogLinterData(
		string $page, int $revision, array $data
	): bool {
		$errors = [];
		$title = Title::newFromText( $page );
		if (
			!$title || !$title->getArticleID() ||
			$title->getLatestRevID() != $revision
		) {
			return false;
		}
		$catCounts = [];
		foreach ( $data as $info ) {
			if ( $this->categoryManager->isKnownCategory( $info['type'] ) ) {
				$info[ 'dbid' ] = null;
			} elseif ( !isset( $info[ 'dbid' ] ) ) {
				continue;
			}
			$count = $catCounts[$info['type']] ?? 0;
			if ( $count > Database::MAX_PER_CAT ) {
				// Drop
				continue;
			}
			$catCounts[$info['type']] = $count + 1;
			if ( !isset( $info['dsr'] ) ) {
				LoggerFactory::getInstance( 'Linter' )->warning(
					'dsr for {page} @ rev {revid}, for lint: {lint} is missing',
					[
						'page' => $page,
						'revid' => $revision,
						'lint' => $info['type'],
					]
				);
				continue;
			}
			$info['location'] = array_slice( $info['dsr'], 0, 2 );
			if ( !isset( $info['params'] ) ) {
				$info['params'] = [];
			}
			if ( isset( $info['templateInfo'] ) && $info['templateInfo'] ) {
				$info['params']['templateInfo'] = $info['templateInfo'];
			}
			$errors[] = $info;
		}
		$job = new RecordLintJob( $title, [
			'errors' => $errors,
			'revision' => $revision,
		], $this->totalsLookup, $this->databaseFactory );
		$this->jobQueueGroup->push( $job );
		return true;
	}
}
