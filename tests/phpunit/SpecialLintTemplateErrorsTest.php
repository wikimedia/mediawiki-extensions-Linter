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

namespace MediaWiki\Linter\Test;

use MediaWiki\Linter\SpecialLintTemplateErrors;
use MediaWiki\Tests\Specials\SpecialPageTestBase;

/**
 * @covers \MediaWiki\Linter\SpecialLintTemplateErrors
 *
 * @group Database
 */
class SpecialLintTemplateErrorsTest extends SpecialPageTestBase {

	protected function newSpecialPage() {
		$services = $this->getServiceContainer();
		return new SpecialLintTemplateErrors(
			$services->getConnectionProvider(),
			$services->getTitleParser(),
			$services->getLinkCache(),
			$services->getPermissionManager(),
			$services->get( 'Linter.CategoryManager' )
		);
	}

	public function testUninitializedCategoryDoesNotThrow() {
		$specialPage = $this->newSpecialPage();

		// Ensure getQueryInfo returns query structure without throwing uninitialized property exception
		$queryInfo = $specialPage->getQueryInfo();
		$this->assertIsArray( $queryInfo );
		$this->assertArrayHasKey( 'tables', $queryInfo );

		// Ensure getPageHeader returns empty string when category is null
		$header = $specialPage->getPageHeader();
		$this->assertIsString( $header );

		// Ensure fetchFromCache executes without throwing uninitialized property exception
		$resultSet = $specialPage->fetchFromCache( 10, 0 );
		$this->assertNotNull( $resultSet );
	}

	public function testExecuteWithCategory() {
		$categoryManager = $this->getServiceContainer()->get( 'Linter.CategoryManager' );
		$categories = $categoryManager->getVisibleCategories();
		$this->assertNotEmpty( $categories );
		$category = $categories[0];

		// Execute special page with category subpage parameter
		$html = $this->executeSpecialPage( $category, null, 'qqx' )[0];
		$this->assertStringContainsString( 'category-by-template-desc', $html );
	}

	public function testExecuteWithoutCategoryShowsIndex() {
		$html = $this->executeSpecialPage( '', null, 'qqx' )[0];
		$this->assertStringContainsString( 'linter-template-errors-index-desc', $html );
	}
}
