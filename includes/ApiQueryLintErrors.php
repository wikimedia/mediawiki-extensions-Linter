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

use ApiBase;
use ApiQuery;
use ApiQueryBase;
use ApiResult;
use Title;

class ApiQueryLintErrors extends ApiQueryBase {
	public function __construct( ApiQuery $queryModule, $moduleName ) {
		parent::__construct( $queryModule, $moduleName, 'lnt' );
	}

	public function execute() {
		$params = $this->extractRequestParams();
		$categoryMgr = new CategoryManager();

		$this->addTables( 'linter' );
		$this->addWhereFld( 'linter_cat', array_values( $categoryMgr->getCategoryIds(
			$params['categories']
		) ) );
		$db = $this->getDB();
		if ( $params['from'] !== null ) {
			$this->addWhere( 'linter_id >= ' . $db->addQuotes( $params['from'] ) );
		}
		if ( $params['namespace'] !== null ) {
			$this->addWhereFld( 'page_namespace', $params['namespace'] );
		}
		$this->addTables( 'page' );
		$this->addJoinConds( [ 'page' => [ 'INNER JOIN', 'page_id=linter_page' ] ] );
		$this->addFields( [
			'linter_id', 'linter_cat', 'linter_params',
			'page_namespace', 'page_title',
		] );
		// Be explicit about ORDER BY
		$this->addOption( 'ORDER BY', 'linter_id' );
		// Add +1 to limit to know if there's another row for continuation
		$this->addOption( 'LIMIT', $params['limit'] + 1 );
		$rows = $this->select( __METHOD__ );
		$result = $this->getResult();
		$count = 0;
		foreach ( $rows as $row ) {
			$lintError = Database::makeLintError( $row );
			$count++;
			if ( $count > $params['limit'] ) {
				$this->setContinueEnumParameter( 'from', $lintError->lintId );
				break;
			}
			$title = Title::makeTitle( $row->page_namespace, $row->page_title );

			$data = [
				'title' => $title->getPrefixedText(),
				'lintId' => $lintError->lintId,
				'category' => $lintError->category,
				'location' => $lintError->location,
				'templateInfo' => $lintError->templateInfo,
				'params' => $lintError->getExtraParams(),
			];
			// template info and params are an object
			$data['params'][ApiResult::META_TYPE] = 'assoc';
			$data['templateInfo'][ApiResult::META_TYPE] = 'assoc';

			$fit = $result->addValue( [ 'query', $this->getModuleName() ], null, $data );
			if ( !$fit ) {
				$this->setContinueEnumParameter( 'from', $lintError->lintId );
				break;
			}
		}
	}

	public function getAllowedParams() {
		$visibleCats = ( new CategoryManager() )->getVisibleCategories();
		return [
			'categories' => [
				ApiBase::PARAM_TYPE => $visibleCats,
				ApiBase::PARAM_ISMULTI => true,
				// Default is to show all categories
				ApiBase::PARAM_DFLT => implode( '|', $visibleCats ),
			],
			'limit' => [
				ApiBase::PARAM_DFLT => 10,
				ApiBase::PARAM_TYPE => 'limit',
				ApiBase::PARAM_MIN => 1,
				ApiBase::PARAM_MAX => ApiBase::LIMIT_BIG1,
				ApiBase::PARAM_MAX2 => ApiBase::LIMIT_BIG2
			],
			'namespace' => [
				ApiBase::PARAM_TYPE => 'namespace',
				ApiBase::PARAM_ISMULTI => true,
			],
			'from' => [
				ApiBase::PARAM_TYPE => 'integer',
			],
		];
	}

	public function getExamplesMessages() {
		return [
			'action=query&list=linterrors&lntcategories=obsolete-tag' =>
				'apihelp-query+linterrors-example-1',
		];
	}
}
