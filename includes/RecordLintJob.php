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

use Job;
use MediaWiki\MediaWikiServices;
use Title;

class RecordLintJob extends Job {
	/**
	 * RecordLintJob constructor.
	 * @param Title $title
	 * @param array $params
	 */
	public function __construct( Title $title, array $params ) {
		parent::__construct( 'RecordLintJob', $title, $params );
	}

	public function run() {
		if ( $this->title->getLatestRevID() != $this->params['revision'] ) {
			// Outdated now, let a later job handle it
			return true;
		}

		// [ 'category' => [ 'id' => LintError ] ]
		$errors = [];
		foreach ( $this->params['errors'] as $errorInfo ) {
			$error = new LintError(
				$errorInfo['type'],
				$errorInfo['location'],
				$errorInfo['params']
			);
			// Use unique id as key to get rid of exact dupes
			// (e.g. same category of error in same template)
			$errors[$error->category][$error->id()] = $error;
		}
		$lintDb = new Database( $this->title->getArticleID() );
		$toSet = [];
		foreach ( $errors as $category => $catErrors ) {
			// If there are too many errors for a category, trim some of them.
			if ( count( $catErrors ) > $lintDb::MAX_PER_CAT ) {
				$catErrors = array_slice( $catErrors, 0, $lintDb::MAX_PER_CAT );
			}
			$toSet = array_merge( $toSet, $catErrors );
		}

		$lintDb->setForPage( $toSet );
		$this->updateStats( $lintDb );

		return true;
	}

	/**
	 * Send stats to statsd
	 *
	 * @param Database $lintDb
	 */
	protected function updateStats( Database $lintDb ) {
		global $wgLinterStatsdSampleFactor;

		if ( $wgLinterStatsdSampleFactor === false ) {
			// Not enabled at all
			return;
		} elseif ( mt_rand( 1, $wgLinterStatsdSampleFactor ) != 1 ) {
			return;
		}

		$totals = $lintDb->getTotals();

		$stats = MediaWikiServices::getInstance()->getStatsdDataFactory();
		foreach ( $totals as $name => $count ) {
			$stats->gauge( "linter.category.$name", $count );
		}

		$stats->gauge( "linter.totals", array_sum( $totals ) );
	}

}
