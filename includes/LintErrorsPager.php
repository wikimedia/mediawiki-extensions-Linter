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

	private $category;

	private $categoryId;

	/**
	 * @var LinkRenderer
	 */
	private $linkRenderer;

	public function __construct( IContextSource $context, $category, LinkRenderer $linkRenderer ) {
		$this->category = $category;
		$this->categoryId = ( new CategoryManager() )->getCategoryId( $this->category );
		$this->linkRenderer = $linkRenderer;
		parent::__construct( $context );
	}

	public function getQueryInfo() {
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
			'conds' => [ 'linter_cat' => $this->categoryId ],
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
					[ 'action' => 'edit', 'lintid' => $lintError->lintId, ]
				);

				return $this->msg( 'linker-page-title-edit' )->rawParams( $viewLink, $editLink )->escaped();
			case 'details':
				$hasNameCats = [ 'obsolete-tag', 'missing-end-tag', 'self-closed-tag' ];
				if ( in_array( $this->category, $hasNameCats ) && isset( $lintError->params['name'] ) ) {
					return Html::element( 'code', [], $lintError->params['name'] );
				} elseif ( $this->category === 'bogus-image-options' && isset( $lintError->params['items'] ) ) {
					$list = array_map( function( $in ) {
						return Html::element( 'code', [], $in );
					}, $lintError->params['items'] );
					return $this->getLanguage()->commaList( $list );
				}
				return '';
			case 'template':
				if ( !$lintError->templateInfo ) {
					return '&mdash;';
				}
				$templateName = $lintError->templateInfo['name'];
				$templateTitle = Title::newFromText( $templateName, NS_TEMPLATE );
				if ( !$templateTitle ) {
					// Shouldn't be possible...???
					return '&mdash;';
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
