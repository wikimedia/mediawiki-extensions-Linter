<?php
/**
 * Copyright (C) 2017 Kunal Mehta <legoktm@member.fsf.org>
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301 USA.
 *
 */

namespace MediaWiki\Linter\Test;

use MediaWiki\Linter\CategoryManager;
use MediaWikiTestCase;

/**
 * Test that all of the messages for new categories
 * exist
 */
class CategoryMessagesTest extends MediaWikiTestCase {

	public static function provideCategoryNames() {
		$manager = new CategoryManager();
		$tests = [];
		foreach ( $manager as $category ) {
			$tests[] = [ $category ];
		}

		return $tests;
	}

	/**
	 * @dataProvider provideCategoryNames
	 */
	public function testMessagesExistence( $category ) {
		$this->assertTrue( wfMessage( "linter-category-$category" )->exists() );
		$this->assertTrue( wfMessage( "linter-category-$category-desc" )->exists() );
		$this->assertTrue( wfMessage( "linter-pager-$category-details" )->exists() );
	}
}
