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

use Html;
use SpecialPage;

class SpecialLintErrors extends SpecialPage {

	private $category;

	public function __construct() {
		parent::__construct( 'LintErrors' );
	}

	public function execute( $par ) {
		$this->setHeaders();
		$this->outputHeader();
		$catManager = new CategoryManager();
		if ( in_array( $par, $catManager->getVisibleCategories() ) ) {
			$this->category = $par;
		}

		if ( !$this->category ) {
			$this->addHelpLink( 'Help:Extension:Linter' );
			$this->showCategoryListings( $catManager );
		} else {
			$this->addHelpLink( "Help:Extension:Linter/{$this->category}" );
			$out = $this->getOutput();
			$out->setPageTitle(
				$this->msg( 'linterrors-subpage',
					$this->msg( "linter-category-{$this->category}" )->text()
				)
			);
			$out->addBacklinkSubtitle( $this->getPageTitle() );
			$out->addWikiMsg( "linter-category-{$this->category}-desc" );
			$pager = new LintErrorsPager(
				$this->getContext(), $this->category, $this->getLinkRenderer(),
				$catManager
			);
			$out->addParserOutput( $pager->getFullOutput() );
		}
	}

	private function showCategoryListings( CategoryManager $catManager ) {
		$totals = ( new Database( 0 ) )->getTotals();

		$out = $this->getOutput();
		$out->addHTML( Html::element( 'h2', [], $this->msg( 'linter-heading-errors' )->text() ) );
		$out->addHTML( $this->buildCategoryList( $catManager->getErrors(), $totals ) );
		$out->addHTML( Html::element( 'h2', [], $this->msg( 'linter-heading-warnings' )->text() ) );
		$out->addHTML( $this->buildCategoryList( $catManager->getWarnings(), $totals ) );
	}

	/**
	 * @param string[] $cats
	 * @param int[] $totals name => count
	 * @return string
	 */
	private function buildCategoryList( array $cats, array $totals ) {
		$linkRenderer = $this->getLinkRenderer();
		$html = Html::openElement( 'ul' ) . "\n";
		foreach ( $cats as $cat ) {
			$html .= Html::rawElement( 'li', [], $linkRenderer->makeKnownLink(
				$this->getPageTitle( $cat ),
				$this->msg( "linter-category-$cat" )->text()
			) . ' ' . $this->msg( "linter-numerrors" )->numParams( $totals[$cat] )->escaped() ) . "\n";
		}
		$html .= Html::closeElement( 'ul' );

		return $html;
	}

	public function getGroupName() {
		return 'maintenance';
	}

	protected function getSubpagesForPrefixSearch() {
		return ( new CategoryManager() )->getVisibleCategories();
	}

}
