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

use ExtensionRegistry;
use IContextSource;
use InvalidArgumentException;
use LinkCache;
use Html;
use MediaWiki\Linker\LinkRenderer;
use MediaWiki\MediaWikiServices;
use TablePager;
use TitleValue;
use Title;

class LintErrorsPager extends TablePager {

	private $categoryManager;

	private $category;

	private $categoryId;

	/**
	 * @var LinkRenderer
	 */
	private $linkRenderer;

	/**
	 * @var bool
	 */
	private $haveParserMigrationExt;

	/**
	 * @var int|null
	 */
	private $namespace;

	public function __construct( IContextSource $context, $category, LinkRenderer $linkRenderer,
		CategoryManager $catManager, $namespace
	) {
		$this->category = $category;
		$this->categoryManager = $catManager;
		$this->categoryId = $catManager->getCategoryId( $this->category );
		$this->linkRenderer = $linkRenderer;
		$this->namespace = $namespace;
		$this->haveParserMigrationExt = ExtensionRegistry::getInstance()->isLoaded( 'ParserMigration' );
		parent::__construct( $context );
	}

	public function getQueryInfo() {
		$conds = [ 'linter_cat' => $this->categoryId ];
		if ( $this->namespace !== null ) {
			$conds['page_namespace'] = $this->namespace;
		}
		return [
			'tables' => [ 'page', 'linter' ],
			'fields' => array_merge(
				LinkCache::getSelectFields(),
				[
					'page_namespace', 'page_title',
					'linter_id', 'linter_params',
					'linter_start', 'linter_end',
				]
			),
			'conds' => $conds,
			'join_conds' => [ 'page' => [ 'INNER JOIN', 'page_id=linter_page' ] ]
		];
	}

	protected function doBatchLookups() {
		$linkCache = MediaWikiServices::getInstance()->getLinkCache();
		foreach ( $this->mResult as $row ) {
			$titleValue = new TitleValue( (int)$row->page_namespace, $row->page_title );
			$linkCache->addGoodLinkObjFromRow( $titleValue, $row );
		}
	}

	public function isFieldSortable( $field ) {
		return false;
	}

	public function formatValue( $name, $value ) {
		$row = $this->mCurrentRow;
		$row->linter_cat = $this->categoryId;
		$lintError = Database::makeLintError( $row );
		if ( $this->haveParserMigrationExt &&
			$this->categoryManager->needsParserMigrationEdit( $name )
		) {
			$editAction = 'parsermigration-edit';
		} else {
			$editAction = 'edit';
		}
		switch ( $name ) {
			case 'title':
				$title = Title::makeTitle( $row->page_namespace, $row->page_title );
				$viewLink = $this->linkRenderer->makeLink( $title );
				if ( !$title->quickUserCan( 'edit', $this->getUser() ) ) {
					return $viewLink;
				}
				$editLink = $this->linkRenderer->makeLink(
					$title,
					$this->msg( 'linker-page-edit' )->text(),
					[],
					[ 'action' => $editAction, 'lintid' => $lintError->lintId, ]
				);

				$historyLink = $this->linkRenderer->makeLink(
					$title,
					$this->msg( 'linker-page-history' )->text(),
					[],
					[ 'action' => 'history' ]
				);

				$editHistLinks = $this->getLanguage()->pipeList( [ $editLink, $historyLink ] );
				return $this->msg( 'linker-page-title-edit' )
					->rawParams( $viewLink, $editHistLinks )
					->escaped();
			case 'details':
				$hasNameCats = [
					'deletable-table-tag',
					'obsolete-tag',
					'missing-end-tag',
					'self-closed-tag',
					'misnested-tag',
					'stripped-tag',
				];
				if ( in_array( $this->category, $hasNameCats ) && isset( $lintError->params['name'] ) ) {
					return Html::element( 'code', [], $lintError->params['name'] );
				} elseif ( $this->category === 'bogus-image-options' && isset( $lintError->params['items'] ) ) {
					$list = array_map( function ( $in ) {
						return Html::element( 'code', [], $in );
					}, $lintError->params['items'] );
					return $this->getLanguage()->commaList( $list );
				} elseif ( $this->category === 'pwrap-bug-workaround' &&
					isset( $lintError->params['root'] ) &&
					isset( $lintError->params['child'] ) ) {
					return Html::element( 'code', [],
						$lintError->params['root'] . " > " . $lintError->params['child'] );
				} elseif ( $this->category === 'tidy-whitespace-bug' &&
					isset( $lintError->params['node'] ) &&
					isset( $lintError->params['sibling'] ) ) {
					return Html::element( 'code', [],
						$lintError->params['node'] . " + " . $lintError->params['sibling'] );
				}
				return '';
			case 'template':
				if ( !$lintError->templateInfo ) {
					return '&mdash;';
				}

				if ( isset( $lintError->templateInfo['multiPartTemplateBlock'] ) ) {
					return $this->msg( 'multi-part-template-block' )->escaped();
				} else {
					$templateName = $lintError->templateInfo['name'];
					// Parsoid provides us with fully qualified template title
					// So, fallback to the default main namespace
					$templateTitle = Title::newFromText( $templateName );
					if ( !$templateTitle ) {
						// Shouldn't be possible...???
						return '&mdash;';
					}
				}

				return $this->linkRenderer->makeLink(
					$templateTitle
				);
			default:
				throw new InvalidArgumentException( "Unexpected name: $name" );
		}
	}

	public function getDefaultSort() {
		return 'linter_id';
	}

	public function getFieldNames() {
		$names = [
			'title' => $this->msg( 'linter-pager-title' )->text(),
		];
		if ( $this->category !== 'fostered' ) {
			// TODO: don't hardcode list of stuff with no parameters...?
			$names['details'] = $this->msg( "linter-pager-{$this->category}-details" )->text();
		}
		$names['template'] = $this->msg( "linter-pager-template" )->text();
		return $names;
	}
}
