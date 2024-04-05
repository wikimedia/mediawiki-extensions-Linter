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
use MediaWiki\Page\PageReference;

class RecordLintJob extends Job {
	private TotalsLookup $totalsLookup;
	private DatabaseFactory $databaseFactory;

	/**
	 * RecordLintJob constructor.
	 * @param PageReference $page
	 * @param array $params
	 * @param TotalsLookup $totalsLookup
	 * @param DatabaseFactory $databaseFactory
	 */
	public function __construct(
		PageReference $page,
		array $params,
		TotalsLookup $totalsLookup,
		DatabaseFactory $databaseFactory
	) {
		parent::__construct( 'RecordLintJob', $page, $params );
		$this->totalsLookup = $totalsLookup;
		$this->databaseFactory = $databaseFactory;
	}

	public function run() {
		if ( $this->title->getLatestRevID() != $this->params['revision'] ) {
			// Outdated now, let a later job handle it
			return true;
		}

		// [ 'id' => LintError ]
		$errors = [];
		foreach ( $this->params['errors'] as $errorInfo ) {
			if ( $errorInfo['type'] === 'inline-media-caption' ) {
				// Drop lints of this type for now
				continue;
			}
			$error = new LintError(
				$errorInfo['type'],
				$errorInfo['location'],
				$errorInfo['params'],
				$errorInfo['dbid']
			);
			// Use unique id as key to get rid of exact dupes
			// (e.g. same category of error in same template)
			$errors[$error->id()] = $error;
		}

		$lintDb = $this->databaseFactory->newDatabase(
			$this->title->getArticleID(), $this->title->getNamespace()
		);
		$this->totalsLookup->updateStats( $lintDb, $lintDb->setForPage( $errors ) );

		return true;
	}

}
