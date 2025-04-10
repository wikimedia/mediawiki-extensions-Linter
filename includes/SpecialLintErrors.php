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

use MediaWiki\Cache\LinkCache;
use MediaWiki\Html\Html;
use MediaWiki\HTMLForm\HTMLForm;
use MediaWiki\Output\OutputPage;
use MediaWiki\Parser\ParserOptions;
use MediaWiki\Permissions\PermissionManager;
use MediaWiki\Request\WebRequest;
use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\Title\MalformedTitleException;
use MediaWiki\Title\NamespaceInfo;
use MediaWiki\Title\TitleParser;

class SpecialLintErrors extends SpecialPage {

	private NamespaceInfo $namespaceInfo;
	private TitleParser $titleParser;
	private LinkCache $linkCache;
	private PermissionManager $permissionManager;
	private CategoryManager $categoryManager;
	private TotalsLookup $totalsLookup;

	/**
	 * @var string|null
	 */
	private $category;

	/**
	 * @param NamespaceInfo $namespaceInfo
	 * @param TitleParser $titleParser
	 * @param LinkCache $linkCache
	 * @param PermissionManager $permissionManager
	 * @param CategoryManager $categoryManager
	 * @param TotalsLookup $totalsLookup
	 */
	public function __construct(
		NamespaceInfo $namespaceInfo,
		TitleParser $titleParser,
		LinkCache $linkCache,
		PermissionManager $permissionManager,
		CategoryManager $categoryManager,
		TotalsLookup $totalsLookup
	) {
		parent::__construct( 'LintErrors' );
		$this->namespaceInfo = $namespaceInfo;
		$this->titleParser = $titleParser;
		$this->linkCache = $linkCache;
		$this->permissionManager = $permissionManager;
		$this->categoryManager = $categoryManager;
		$this->totalsLookup = $totalsLookup;
	}

	/**
	 * @param string $titleLabel
	 */
	protected function showFilterForm( $titleLabel ) {
		$selectOptions = [
			(string)$this->msg( 'linter-form-exact-match' )->escaped() => true,
			(string)$this->msg( 'linter-form-prefix-match' )->escaped() => false,
		];
		$namespaces = $this->getContext()->getRequest()->getVal( "wpNamespaceRestrictions" );
		$fields = [
			'NamespaceRestrictions' => [
				'type' => 'namespacesmultiselect',
				'label' => $this->msg( 'linter-form-namespace' )->text(),
				'exists' => true,
				'cssclass' => 'mw-block-partial-restriction',
				'default' => $namespaces,
				'input' => [ 'autocomplete' => false ]
			],
			'titlefield' => [
				'type' => 'title',
				'name' => $titleLabel,
				'label-message' => 'linter-form-title-prefix',
				'exists' => true,
				'required' => false
			],
			'exactmatchradio' => [
				'type' => 'radio',
				'name' => 'exactmatch',
				'options' => $selectOptions,
				'label-message' => 'linter-form-exact-or-prefix',
				'default' => true
			]
		];

		$selectTemplateOptions = [
			(string)$this->msg( 'linter-form-template-option-all' )->escaped() => 'all',
			(string)$this->msg( 'linter-form-template-option-with' )->escaped() => 'with',
			(string)$this->msg( 'linter-form-template-option-without' )->escaped() => 'without',
		];
		$htmlTags = new HtmlTags( $this );
		$tagAndTemplateFields = [
			'tag' => [
				'type' => 'select',
				'name' => 'tag',
				'label-message' => 'linter-form-tag',
				'options' => $htmlTags->getAllowedHTMLTags()
			],
			'template' => [
				'type' => 'select',
				'name' => 'template',
				'label-message' => 'linter-form-template',
				'options' => $selectTemplateOptions
			]
		];
		$fields = array_merge( $fields, $tagAndTemplateFields );

		$form = HTMLForm::factory( 'ooui', $fields, $this->getContext() );
		$form->setWrapperLegend( true );
		if ( $this->category !== null ) {
			$form->addHeaderHtml( $this->msg( "linter-category-{$this->category}-desc" )->parse() );
		}
		$form->setMethod( 'get' );
		$form->prepareForm()->displayForm( false );
	}

	/**
	 * cleanTitle parses a title and handles a malformed titles, namespaces that are mismatched
	 * and exact title searches that find no matching records, and produce appropriate error messages
	 *
	 * @param string $title
	 * @param array $namespaces
	 * @return array
	 */
	public function cleanTitle( string $title, $namespaces ): array {
		// Check all titles for malformation regardless of exact match or prefix match
		try {
			$titleElements = $this->titleParser->parseTitle( $title );
		} catch ( MalformedTitleException  $e ) {
			return [ 'titlefield' => null, 'error' => 'linter-invalid-title' ];
		}

		// The drop-down namespace defaults to 'all' which is returned as a null, indicating match all namespaces.
		// If 'main' is selected in the drop-down, int 0 is returned. Other namespaces are returned as int values > 0.
		//
		// If the user does not specify a namespace in the title text box, parseTitle sets it to int 0 as the default.
		// If the user entered ':' (main) namespace as the namespace prefix of a title such as ":MyPageTitle",
		// parseTitle will also return int 0 as the namespace. Other valid system namespaces entered as prefixes
		// in the title text box are returned by parseTitle as int values > 0.
		// To determine if the user entered the ':' (main) namespace when int 0 is returned, a separate check for
		// the substring ':' at offset 0 must be performed.

		$titleNamespace = $titleElements->getNamespace();
		// Determine if the user entered ':' (resolves to main) as the namespace part of the title,
		// or was it was set by default by parseTitle() to 0, but the user intended to search across 'all' namespaces.
		if ( $titleNamespace === 0 && $title[0] !== ':' ) {
			$titleNamespace = null;
		}

		if ( $namespaces && $titleNamespace !== null && !in_array( $titleNamespace, $namespaces ) ) {
			// Show the namespace mismatch error if the namespaces specified in drop-down and title text do not match.
			return [ 'titlefield' => null, 'error' => 'linter-namespace-mismatch' ];
		}

		// If no namespaces are selected (null), return the namespace from the title text
		$namespaces = $namespaces ?: [ $titleNamespace ];

		return [ 'titlefield' => $titleElements->getDBkey(), 'namespace' => $namespaces ];
	}

	/**
	 * @param OutputPage $out
	 * @param string|null $message
	 */
	private function displayError( $out, $message ) {
		$out->addHTML(
			Html::element( 'span', [ 'class' => 'error' ],
				$this->msg( $message )->text() )
		);
	}

	/**
	 * Extract namespace settings from the request object,
	 * returning an array of namespace id numbers
	 *
	 * @param WebRequest $request
	 * @return array
	 */
	protected function findNamespaces( $request ) {
		$namespaceRequestValues = $request->getRawVal( 'wpNamespaceRestrictions' ) ?? '';
		if ( $namespaceRequestValues === '' ) {
			return [];
		}

		// Security measure: only allow active namespace IDs to reach the query
		return array_values(
			array_intersect(
				// Remove -2 = "media" and -1 = "Special" namespace elements
				array_filter(
					array_keys(
						$this->namespaceInfo->getCanonicalNamespaces()
					),
					static function ( $x ) {
						return $x >= 0;
					}
				),
				array_map( 'intval', explode( "\n", $namespaceRequestValues ) )
			)
		);
	}

	/**
	 * @param string|null $subPage
	 */
	public function execute( $subPage ) {
		$request = $this->getRequest();
		$out = $this->getOutput();
		$out->addModuleStyles( 'ext.linter.styles' );

		$params = $request->getQueryValues();

		$this->setHeaders();
		$this->outputHeader( $subPage || isset( $params[ 'titlesearch' ] ) ? 'disable-summary' : '' );

		$namespaces = $this->findNamespaces( $request );

		$exactMatch = $request->getBool( 'exactmatch', true );
		$tagName = $this->getRequest()->getText( 'tag' );
		// map command line tag name through an associative array to protect request from an SQL injection security risk
		$htmlTags = new HtmlTags( $this );
		$allowedHtmlTags = $htmlTags->getAllowedHTMLTags();
		$tag = $allowedHtmlTags[ $tagName ] ?? 'all';
		$template = $this->getRequest()->getText( 'template' );

		// If the request contains a 'titlesearch' parameter, then the user entered a page title
		// or just the first few characters of the title. They also may have entered the first few characters
		// of a custom namespace (just the text before a ':') to search for and pressed the associated Submit button.
		// Added the pageback parameter to inform the code that the '<- Special:LintErrors' link had been used to allow
		// the UI to redisplay with previous form values, instead of just resubmitting the query.
		if ( $subPage === null && isset( $params[ 'titlesearch' ] ) && !isset( $params[ 'pageback'] ) ) {
			unset( $params[ 'title' ] );
			$params = array_merge( [ 'pageback' => true ], $params );
			$out->addBacklinkSubtitle( $this->getPageTitle(), $params );

			$title = $request->getText( 'titlesearch' );
			$titleSearch = $this->cleanTitle( $title, $namespaces );

			if ( $titleSearch[ 'titlefield' ] !== null ) {
				$out->setPageTitleMsg( $this->msg( 'linter-prefix-search-subpage', $titleSearch[ 'titlefield' ] ) );

				$pager = new LintErrorsPager(
					$this->getContext(),
					$this->categoryManager,
					$this->linkCache,
					$this->getLinkRenderer(),
					$this->permissionManager,
					null,
					$namespaces,
					$exactMatch, $titleSearch[ 'titlefield' ], $template, $tag
				);
				$out->addParserOutput(
					$pager->getFullOutput(),
					ParserOptions::newFromContext( $this->getContext() )
				);
			} else {
				$this->displayError( $out, $titleSearch[ 'error' ] );
			}
			return;
		}

		if ( in_array( $subPage, array_merge(
			$this->categoryManager->getVisibleCategories(),
			$this->categoryManager->getInvisibleCategories()
		) ) ) {
			$this->category = $subPage;
		}

		if ( !$this->category ) {
			$this->addHelpLink( 'Help:Extension:Linter' );
			$this->showCategoryListings();
		} else {
			$this->addHelpLink( "Help:Lint_errors/{$this->category}" );
			$out->setPageTitleMsg(
				$this->msg( 'linterrors-subpage',
					$this->msg( "linter-category-{$this->category}" )->text()
				)
			);
			$out->addBacklinkSubtitle( $this->getPageTitle() );

			$title = $request->getText( 'titlecategorysearch' );
			// For category-based searches, allow an undefined title to display all records
			if ( $title === '' ) {
				$titleCategorySearch = [ 'titlefield' => '', 'namespace' => $namespaces, 'pageid' => null ];
			} else {
				$titleCategorySearch = $this->cleanTitle( $title, $namespaces );
			}

			if ( $titleCategorySearch[ 'titlefield' ] !== null ) {
				$this->showFilterForm( 'titlecategorysearch' );
				$pager = new LintErrorsPager(
					$this->getContext(),
					$this->categoryManager,
					$this->linkCache,
					$this->getLinkRenderer(),
					$this->permissionManager,
					$this->category,
					$namespaces,
					$exactMatch, $titleCategorySearch[ 'titlefield' ], $template, $tag
				);
				$out->addParserOutput(
					$pager->getFullOutput(),
					ParserOptions::newFromContext( $this->getContext() )
				);
			} else {
				$this->displayError( $out, $titleCategorySearch[ 'error' ] );
			}
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
			$this->msg( "linter-lints-prefix-search-page-desc" )->text() ) );
		$this->showFilterForm( 'titlesearch' );
	}

	private function showCategoryListings() {
		$totals = $this->totalsLookup->getTotals();

		// Display lint issues by priority
		$this->displayList( 'high', $totals, $this->categoryManager->getHighPriority() );
		$this->displayList( 'medium', $totals, $this->categoryManager->getMediumPriority() );
		$this->displayList( 'low', $totals, $this->categoryManager->getLowPriority() );

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
		return $this->categoryManager->getVisibleCategories();
	}

}
