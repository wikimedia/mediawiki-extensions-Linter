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
use HTMLForm;
use MediaWiki\MediaWikiServices;
use SpecialPage;
use Title;

class SpecialLintErrors extends SpecialPage {

	/**
	 * @var string|null
	 */
	private $category;

	public function __construct() {
		parent::__construct( 'LintErrors' );
	}

	/**
	 * @param int|null $ns
	 * @param bool $nsinvert
	 */
	protected function showNamespaceFilterForm( $ns, $nsinvert ) {
		$fields = [
			'namespace' => [
				'type' => 'namespaceselect',
				'name' => 'namespace',
				'label-message' => 'linter-form-namespace',
				'default' => $ns,
				'id' => 'namespace',
				'all' => '',
				'cssclass' => 'namespaceselector'
			],
			'nsinvert' => [
				'type' => 'check',
				'name' => 'invert',
				'label-message' => 'invert',
				'default' => $nsinvert,
				'tooltip' => 'invert'
			],
			'titleprefix' => [
				'type' => 'title',
				'name' => 'titleprefix',
				'label-message' => 'linter-form-title-prefix',
				'exists' => true,
				'required' => false
			],
		];
		$form = HTMLForm::factory( 'ooui', $fields, $this->getContext() );
		$form->setWrapperLegend( true );
		$form->addHeaderText( $this->msg( "linter-category-{$this->category}-desc" )->parse() );
		$form->setMethod( 'get' );
		$form->prepareForm()->displayForm( false );
	}

	/**
	 */
	protected function showPageNameFilterForm() {
		$fields = [
			'pagename' => [
				'type' => 'title',
				'name' => 'pagename',
				'label-message' => 'linter-pager-title-header',
				'exists' => true,
				'required' => false,
			]
		];
		$form = HTMLForm::factory( 'ooui', $fields, $this->getContext() );
		$form->setWrapperLegend( true );
		$form->setMethod( 'get' );
		$form->prepareForm()->displayForm( false );
	}

	/**
	 * @param string|null $par
	 */
	public function execute( $par ) {
		$this->setHeaders();
		$this->outputHeader();

		// If the request contains a `pagename` parameter, then the user entered a pagename
		// and pressed the Submit button to display of all lints for a single page.
		$params = $this->getRequest()->getQueryValues();
		if ( $par === null && isset( $params['pagename'] ) ) {
			// Use getText to ensure a string val
			$pageName = $this->getRequest()->getText( 'pagename', '' );

			$out = $this->getOutput();
			$out->setPageTitle( $this->msg( 'linterrors-subpage', $pageName ) );

			$title = Title::newFromText( $pageName );
			if ( $title !== null ) {
				$pageArticleID = $title->getArticleID();
				if ( $pageArticleID !== 0 ) {
					$ns = $title->getNamespace();
					$catManager = new CategoryManager();
					$pager = new LintErrorsPager(
						$this->getContext(), null, $this->getLinkRenderer(),
						$catManager, $ns, false, $pageArticleID
					);
					$out->addParserOutput( $pager->getFullOutput() );
					return;
				}
				// No $pageArticleID or no $title go through
			}

			$out->addHTML(
				Html::element( 'span', [ 'class' => 'error' ],
				$this->msg( "linter-invalid-title" )->text() )
			);
			return;
		}

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

			$titlePrefix = $this->getRequest()->getText( 'titleprefix', '' );
			// SQL injection risk is handled in LintErrorsPager.php, getQueryInfo( using buildLike( db function

			$this->showNamespaceFilterForm( $ns, $nsinvert );
			$pager = new LintErrorsPager(
				$this->getContext(), $this->category, $this->getLinkRenderer(),
				$catManager, $ns, $nsinvert, null, $titlePrefix
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

	/**
	 */
	private function displaySearchPage() {
		$out = $this->getOutput();
		$out->addHTML( Html::element( 'h2', [],
			$this->msg( "linter-lints-for-single-page-desc" )->text() ) );
		$this->showPageNameFilterForm();
	}

	/**
	 * @param CategoryManager $catManager
	 */
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

		$this->displaySearchPage();
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

	/** @inheritDoc */
	public function getGroupName() {
		return 'maintenance';
	}

	/**
	 * @return string[]
	 */
	protected function getSubpagesForPrefixSearch() {
		return ( new CategoryManager() )->getVisibleCategories();
	}

}
