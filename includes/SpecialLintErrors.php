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

use HTMLForm;
use Html;
use MediaWiki\MediaWikiServices;
use SpecialPage;

class SpecialLintErrors extends SpecialPage {

	private $category;

	public function __construct() {
		parent::__construct( 'LintErrors' );
	}

	protected function showNamespaceFilterForm( $ns, $nsinvert ) {
		$fields = [
			'namespace' => [
				'type' => 'namespaceselect',
				'name' => 'namespace',
				'label-message' => 'linter-form-namespace',
				'default' => $ns,
				'id' => 'namespace',
				'all' => '',
				'cssclass' => 'namespaceselector',
			],
			'nsinvert' => [
				'type' => 'check',
				'name' => 'invert',
				'label-message' => 'invert',
				'default' => $nsinvert,
				'tooltip' => 'invert',
			],
		];
		$form = HTMLForm::factory( 'ooui', $fields, $this->getContext() );
		$form->setWrapperLegend( true );
		$form->addHeaderText( $this->msg( "linter-category-{$this->category}-desc" )->parse() );
		$form->setMethod( 'get' );
		$form->prepareForm()->displayForm( false );
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
			$ns = $this->getRequest()->getIntOrNull( 'namespace' );
			$nsinvert = $this->getRequest()->getBool( 'invert' );
			$this->showNamespaceFilterForm( $ns, $nsinvert );
			$pager = new LintErrorsPager(
				$this->getContext(), $this->category, $this->getLinkRenderer(),
				$catManager, $ns, $nsinvert
			);
			$out->addParserOutput( $pager->getFullOutput() );
		}
	}

	/**
	 * @param string $priority
	 * @param int[] $totals name => count
	 * @param string[] $categories
	 */
	private function displayList( $priority, $totals, array $categories ) {
		$out = $this->getOutput();
		$msgName = 'linter-heading-' . $priority . '-priority';
		$out->addHTML( Html::element( 'h2', [], $this->msg( $msgName )->text() ) );
		$out->addHTML( $this->buildCategoryList( $categories, $totals ) );
	}

	private function showCategoryListings( CategoryManager $catManager ) {
		$lookup = new TotalsLookup(
			$catManager,
			MediaWikiServices::getInstance()->getMainWANObjectCache()
		);
		$totals = $lookup->getTotals();

		// Display lint issues by priority
		$this->displayList( 'high', $totals, $catManager->getHighPriority() );
		$this->displayList( 'medium', $totals, $catManager->getMediumPriority() );
		$this->displayList( 'low', $totals, $catManager->getLowPriority() );
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
			) . ' ' . Html::element( 'bdi', [],
				$this->msg( "linter-numerrors" )->numParams( $totals[$cat] )->text()
			) ) . "\n";
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
