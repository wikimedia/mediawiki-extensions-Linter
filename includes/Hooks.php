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

use ApiQuerySiteInfo;
use Content;
use DatabaseUpdater;
use EditPage;
use IContextSource;
use MWCallableUpdate;
use WikiPage;

class Hooks {
	/**
	 * @param DatabaseUpdater $updater
	 */
	public static function onLoadExtensionSchemaUpdates( DatabaseUpdater $updater ) {
		$dir = dirname( __DIR__ );
		$updater->addExtensionTable( 'linter', "$dir/linter.sql" );
	}

	/**
	 * Hook: EditFormInitialText
	 *
	 * If there is a lintid parameter, look up that error in the database
	 * and setup and output our client-side helpers
	 *
	 * @param EditPage $editPage
	 */
	public static function onEditFormInitialText( EditPage $editPage ) {
		$context = $editPage->getContext();
		$request = $context->getRequest();
		$lintId = $request->getInt( 'lintid' );
		if ( !$lintId ) {
			return;
		}
		$title = $editPage->getTitle();

		$lintError = ( new Database( $title->getArticleID() ) )->getFromId( $lintId );
		if ( !$lintError ) {
			// Already fixed or bogus URL parameter?
			return;
		}

		$out = $context->getOutput();
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
		$updates[] = new MWCallableUpdate( function() use ( $id ) {
			$database = new Database( $id );
			$database->setForPage( [] );
		}, __METHOD__ );
	}

	/**
	 * Hook: APIQuerySiteInfoGeneralInfo
	 *
	 * Expose categories via action=query&meta=siteinfo
	 *
	 * @param ApiQuerySiteInfo $api
	 * @param array &$data
	 */
	public static function onAPIQuerySiteInfoGeneralInfo( ApiQuerySiteInfo $api, array &$data ) {
		$catManager = new CategoryManager();
		$totals = ( new Database( 0 ) )->getTotals();
		$info = [];
		foreach ( $catManager->getErrors() as $error ) {
			$info['errors'][$error] = $totals[$error];
		}
		foreach ( $catManager->getWarnings() as $warning ) {
			$info['warnings'][$warning] = $totals[$warning];
		}
		$data['linter'] = $info;
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
			$pageInfo['linter'][] = [ $context->msg( "linter-category-$name" ), htmlspecialchars( $count ) ];
		}
	}
}
