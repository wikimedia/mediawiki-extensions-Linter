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
use DatabaseUpdater;
use IContextSource;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\MediaWikiServices;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\User\UserIdentity;
use MWCallableUpdate;
use OutputPage;
use Title;
use WikiPage;

class Hooks {
	/**
	 * @param DatabaseUpdater $updater
	 */
	public static function onLoadExtensionSchemaUpdates( DatabaseUpdater $updater ) {
		$dbType = $updater->getDB()->getType();
		if ( $dbType === 'mysql' ) {
			$updater->addExtensionTable( 'linter',
				dirname( __DIR__ ) . '/sql/tables-generated.sql'
			);
			$updater->addExtensionField( 'linter', 'linter_namespace',
				dirname( __DIR__ ) . '/sql/patch-linter-add-namespace.sql'
			);
			$updater->addExtensionField( 'linter', 'linter_template',
				dirname( __DIR__ ) . '/sql/patch-linter-template-tag-fields.sql'
			);
		} elseif ( $dbType === 'sqlite' ) {
			$updater->addExtensionTable( 'linter',
				dirname( __DIR__ ) . '/sql/sqlite/tables-generated.sql'
			);
			$updater->addExtensionField( 'linter', 'linter_namespace',
				dirname( __DIR__ ) . '/sql/sqlite/patch-linter-add-namespace.sql'
			);
			$updater->addExtensionField( 'linter', 'linter_template',
				dirname( __DIR__ ) . '/sql/sqlite/patch-linter-template-tag-fields.sql'
			);
		} elseif ( $dbType === 'postgres' ) {
			$updater->addExtensionTable( 'linter',
				dirname( __DIR__ ) . '/sql/postgres/tables-generated.sql'
			);
			$updater->addExtensionField( 'linter', 'linter_namespace',
				dirname( __DIR__ ) . '/sql/postgres/patch-linter-add-namespace.sql'
			);
			$updater->addExtensionField( 'linter', 'linter_template',
				dirname( __DIR__ ) . '/sql/postgres/patch-linter-template-tag-fields.sql'
			);
		}
	}

	/**
	 * Hook: BeforePageDisplay
	 *
	 * If there is a lintid parameter, look up that error in the database
	 * and setup and output our client-side helpers
	 *
	 * @param OutputPage &$out
	 */
	public static function onBeforePageDisplay( OutputPage &$out ) {
		$request = $out->getRequest();
		$lintId = $request->getInt( 'lintid' );
		if ( !$lintId ) {
			return;
		}
		$title = $out->getTitle();
		if ( !$title ) {
			return;
		}

		$lintError = ( new Database( $title->getArticleID() ) )->getFromId( $lintId );
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
	public static function onWikiPageDeletionUpdates( WikiPage $wikiPage,
		Content $content, array &$updates
	) {
		$id = $wikiPage->getId();
		$updates[] = new MWCallableUpdate( static function () use ( $id ) {
			$database = new Database( $id );
			$database->updateStats( $database->setForPage( [] ) );
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
	public static function onRevisionFromEditComplete(
		WikiPage $wikiPage,
		RevisionRecord $newRevisionRecord,
		$originalRevId,
		UserIdentity $user,
		&$tags
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
			$database = new Database( $wikiPage->getId() );
			$database->updateStats( $database->setForPage( [] ) );
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
	public static function onAPIQuerySiteInfoGeneralInfo( ApiQuerySiteinfo $api, array &$data ) {
		$catManager = new CategoryManager();
		$data['linter'] = [
			'high' => $catManager->getHighPriority(),
			'medium' => $catManager->getMediumPriority(),
			'low' => $catManager->getLowPriority(),
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
	public static function onInfoAction( IContextSource $context, array &$pageInfo ) {
		$pageId = $context->getTitle()->getArticleID();
		if ( !$pageId ) {
			return;
		}
		$database = new Database( $pageId );
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
	public static function onParserLogLinterData(
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
		$categoryMgr = new CategoryManager();
		$catCounts = [];
		foreach ( $data as $info ) {
			if ( $categoryMgr->isKnownCategory( $info['type'] ) ) {
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
		] );
		MediaWikiServices::getInstance()->getJobQueueGroup()->push( $job );
		return true;
	}
}
